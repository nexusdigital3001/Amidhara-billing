<?php
/**
 * harden.php — production hardening for Amidhara Billing.
 *
 * ADDITIVE BY DESIGN. This file only adds protection; it changes no
 * business logic, no database structure and no existing behaviour.
 * If you delete it, the app still works exactly as before.
 *
 * HOW TO ENABLE (one line):
 *   In config.php, add this as the LAST line:
 *       require_once __DIR__ . '/harden.php';
 *
 * HOW TO DISABLE: remove that line. Nothing else depends on it.
 *
 * What it does:
 *   1. Hides PHP errors from users, logs them instead   (audit: HIGH)
 *   2. Hardens the session cookie                        (audit: MEDIUM)
 *   3. Adds sane security headers                        (audit: MEDIUM)
 *   4. Rate-limits failed logins                         (audit: HIGH)
 */

/* ------------------------------------------------------------------ *
 * 1. Errors: never to the screen in production, always to a log.
 * ------------------------------------------------------------------ */
if (!defined('APP_DEBUG')) define('APP_DEBUG', false);

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);

    // Keep the log outside the web root if the host allows it.
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0750, true);
    ini_set('error_log', $logDir . '/php-error.log');

    // Any uncaught error shows a neutral page, never a stack trace.
    set_exception_handler(function ($e) {
        error_log('UNCAUGHT: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(500);
        echo '<!doctype html><meta charset="utf-8">'
           . '<div style="font:16px/1.6 system-ui,sans-serif;max-width:520px;margin:12vh auto;padding:0 20px">'
           . '<h1 style="font-size:20px">Something went wrong</h1>'
           . '<p>The action could not be completed. Nothing was saved. '
           . 'Please try again, or tell your administrator if it keeps happening.</p>'
           . '<p><a href="index.php">Back to dashboard</a></p></div>';
        exit;
    });
}

/* ------------------------------------------------------------------ *
 * 2. Session cookie hardening. Must run BEFORE session_start().
 *    lib.php starts the session, and config.php is required first,
 *    so including this from config.php gets the ordering right.
 * ------------------------------------------------------------------ */
if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,      // JavaScript cannot read the session cookie
        'secure'   => $https,    // only sent over HTTPS when available
        'samesite' => 'Lax',     // blocks cross-site form CSRF attempts
    ]);
}

/* ------------------------------------------------------------------ *
 * 3. Security headers.
 * ------------------------------------------------------------------ */
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');      // no clickjacking in an iframe
    header('Referrer-Policy: same-origin');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

/* ------------------------------------------------------------------ *
 * 4. Login rate limiting.
 *
 *    Uses a small table. Create it once with:
 *        docs/sql/001-harden-login-attempts.sql
 *
 *    If the table does not exist, these functions fail OPEN (login keeps
 *    working normally) so enabling this file can never lock anyone out.
 * ------------------------------------------------------------------ */

const LOGIN_MAX_FAILS   = 5;     // failures allowed...
const LOGIN_WINDOW_MIN  = 15;    // ...within this many minutes
const LOGIN_LOCKOUT_MIN = 15;    // then locked out for this long

function login_client_ip(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/** True if this IP/username is currently locked out. Fails open on error. */
function login_is_locked(string $username): bool {
    try {
        $n = scalar(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip = ? AND username = ? AND success = 0
               AND attempted_at > (NOW() - INTERVAL ? MINUTE)',
            [login_client_ip(), $username, LOGIN_WINDOW_MIN]
        );
        return (int)$n >= LOGIN_MAX_FAILS;
    } catch (Throwable $e) {
        return false; // table missing or DB hiccup -> do not block real users
    }
}

/** Record one attempt. Silently ignored if the table is not there. */
function login_record(string $username, bool $ok): void {
    try {
        q('INSERT INTO login_attempts (ip, username, success) VALUES (?,?,?)',
          [login_client_ip(), substr($username, 0, 60), $ok ? 1 : 0]);
        // On success, clear that user's failure history.
        if ($ok) {
            q('DELETE FROM login_attempts WHERE ip = ? AND username = ? AND success = 0',
              [login_client_ip(), $username]);
        }
        // Opportunistic cleanup so the table cannot grow forever.
        if (random_int(1, 50) === 1) {
            q('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 7 DAY)');
        }
    } catch (Throwable $e) {
        // ignore
    }
}
