<?php
/**
 * CONFIG TEMPLATE
 *
 * Copy this file to config.php on the server and fill in the real values.
 * config.php is gitignored on purpose — real credentials never go in the repo.
 *
 *   cp config.sample.php config.php
 *
 * These four lines are the only thing you edit per server.
 */
define('DB_HOST', 'localhost');     // almost always localhost on shared hosting
define('DB_NAME', 'your_db_name');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

define('APP_TZ', 'Asia/Kolkata');

/**
 * Production hardening — recommended.
 * Adds error suppression + logging, session cookie flags, security headers
 * and login rate limiting. Purely additive; comment it out to disable.
 *
 * Requires the tables from docs/sql/001-harden-login-attempts.sql
 * (it fails open if they are missing, so it is safe to enable first).
 */
require_once __DIR__ . '/harden.php';
