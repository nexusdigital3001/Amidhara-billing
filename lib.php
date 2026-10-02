<?php
require_once __DIR__ . '/config.php';
date_default_timezone_set(APP_TZ);
if (session_status() === PHP_SESSION_NONE) session_start();

/* ---------------- database ---------------- */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function q(string $sql, array $args = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st;
}
function one(string $sql, array $args = []) { $r = q($sql, $args)->fetch(); return $r === false ? null : $r; }
function all(string $sql, array $args = []): array { return q($sql, $args)->fetchAll(); }
function scalar(string $sql, array $args = []) { $r = q($sql, $args)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }

/* ---------------- settings ---------------- */
function settings(): array {
    static $s = null;
    if ($s === null) {
        $s = [];
        foreach (all('SELECT k, v FROM settings') as $r) $s[$r['k']] = $r['v'];
    }
    return $s;
}
function cfg(string $key, string $default = ''): string {
    $s = settings();
    return isset($s[$key]) ? (string)$s[$key] : $default;
}
function set_cfg(string $key, string $val): void {
    q('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$key, $val]);
}

/* ---------------- auth ---------------- */
function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    static $u = null;
    if ($u === null) $u = one('SELECT * FROM users WHERE id = ? AND active = 1', [$_SESSION['uid']]);
    return $u ?: null;
}
function require_login(): void {
    if (!current_user()) { header('Location: login.php'); exit; }
}
function role(): string { $u = current_user(); return $u ? $u['role'] : 'viewer'; }
function can_edit(): bool { return in_array(role(), ['admin', 'biller'], true); }
function is_admin(): bool { return role() === 'admin'; }
function require_edit(): void {
    if (!can_edit()) { http_response_code(403); exit('Read-only account. Ask an admin for billing access.'); }
}
function require_admin(): void {
    if (!is_admin()) { http_response_code(403); exit('Admins only.'); }
}

/* ---------------- csrf ---------------- */
function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function check_csrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400); exit('Session expired. Go back and try again.');
    }
}

/* ---------------- output ---------------- */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function nl2brh($s): string { return nl2br(h($s)); }

/* Indian digit grouping: 1,45,584.94 */
function inr($n, int $dec = 2): string {
    $n = (float)$n;
    $neg = $n < 0; $n = abs($n);
    $s = number_format($n, $dec, '.', '');
    [$int, $frac] = array_pad(explode('.', $s), 2, '');
    if (strlen($int) > 3) {
        $last3 = substr($int, -3);
        $rest  = substr($int, 0, -3);
        $rest  = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $int   = $rest . ',' . $last3;
    }
    $out = $dec > 0 ? $int . '.' . $frac : $int;
    return ($neg ? '-' : '') . $out;
}
/* Weights print with 2 decimals: 151.52 */
function qty($n): string { return number_format((float)$n, 2, '.', ''); }
function qtyg($n): string { return inr($n, 2); }

function dmy($d): string { return $d ? date('d/m/Y', strtotime($d)) : ''; }

/* ---------------- number to words (Indian) ---------------- */
function words_below_hundred(int $n): string {
    $ones = ['', 'One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten',
             'Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
    $tens = ['', '', 'Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    if ($n < 20) return $ones[$n];
    $t = intdiv($n, 10); $o = $n % 10;
    return $tens[$t] . ($o ? ' ' . $ones[$o] : '');
}
function words_int(int $n): string {
    if ($n === 0) return 'Zero';
    $parts = [];
    $units = [10000000 => 'Crore', 100000 => 'Lakh', 1000 => 'Thousand', 100 => 'Hundred'];
    foreach ($units as $val => $label) {
        if ($n >= $val) {
            $count = intdiv($n, $val);
            $n     = $n % $val;
            $parts[] = ($val >= 1000 ? words_int($count) : words_below_hundred($count)) . ' ' . $label;
        }
    }
    if ($n > 0) $parts[] = words_below_hundred($n);
    return implode(' ', $parts);
}
/* "INR One Lakh Forty Five Thousand Five Hundred Eighty Four and Ninety Four paise Only" */
function amount_words($amount): string {
    $amount = round((float)$amount, 2);
    $rupees = (int)floor($amount);
    $paise  = (int)round(($amount - $rupees) * 100);
    if ($paise === 100) { $rupees++; $paise = 0; }
    $s = 'INR ' . words_int($rupees);
    if ($paise > 0) $s .= ' and ' . words_below_hundred($paise) . ' paise';
    return $s . ' Only';
}

/* ---------------- financial year ---------------- */
/* 2026-07-06 -> "2026-27" ; 2027-02-10 -> "2026-27" */
function fy_of(string $date): string {
    $ts = strtotime($date);
    $y  = (int)date('Y', $ts);
    $m  = (int)date('n', $ts);
    if ($m < 4) $y--;
    return $y . '-' . substr((string)($y + 1), -2);
}

function pad_seq(int $n): string {
    $w = max(1, (int)cfg('seq_pad', '2'));
    return str_pad((string)$n, $w, '0', STR_PAD_LEFT);
}

/* Next invoice number in its own series. Call inside a transaction. */
function next_invoice_seq(string $billType, string $fy): int {
    $max = scalar('SELECT COALESCE(MAX(seq_no),0) FROM invoices WHERE bill_type = ? AND fy = ? FOR UPDATE',
                  [$billType, $fy]);
    return (int)$max + 1;
}
function format_invoice_no(string $billType, int $seq, string $fy): string {
    $pre = $billType === 'gst' ? cfg('gst_prefix', 'ASC/')   : cfg('nongst_prefix', 'ASC/C/');
    $suf = $billType === 'gst' ? cfg('gst_suffix', '')       : cfg('nongst_suffix', '');
    return $pre . pad_seq($seq) . '/' . $fy . $suf;
}
function next_challan_seq(string $fy): int {
    $max = scalar('SELECT COALESCE(MAX(seq_no),0) FROM challans WHERE fy = ? FOR UPDATE', [$fy]);
    return (int)$max + 1;
}
function format_challan_no(int $seq, string $fy): string {
    return cfg('challan_prefix', 'CH/') . pad_seq($seq) . '/' . $fy;
}

/* ---------------- pricing ---------------- */
/* Customer override wins; otherwise the product master rate. */
function rate_for(int $customerId, int $productId): float {
    $r = scalar('SELECT rate FROM customer_prices WHERE customer_id = ? AND product_id = ?', [$customerId, $productId]);
    if ($r !== null) return (float)$r;
    return (float)scalar('SELECT rate FROM products WHERE id = ?', [$productId]);
}
function remember_price(int $customerId, int $productId, float $rate): void {
    $master = (float)scalar('SELECT rate FROM products WHERE id = ?', [$productId]);
    $existing = scalar('SELECT rate FROM customer_prices WHERE customer_id = ? AND product_id = ?', [$customerId, $productId]);
    if ($existing === null && abs($rate - $master) < 0.005) return;       // same as master, nothing to remember
    if ($existing !== null && abs((float)$existing - $rate) < 0.005) return; // unchanged
    q('INSERT INTO customer_prices (customer_id, product_id, rate) VALUES (?,?,?)
       ON DUPLICATE KEY UPDATE rate = VALUES(rate)', [$customerId, $productId, $rate]);
}
function price_map(int $customerId): array {
    $m = [];
    foreach (all('SELECT p.id, COALESCE(cp.rate, p.rate) AS rate, p.hsn, p.unit, p.name, p.is_royalty
                  FROM products p
                  LEFT JOIN customer_prices cp ON cp.product_id = p.id AND cp.customer_id = ?
                  WHERE p.active = 1 ORDER BY p.sort_order, p.name', [$customerId]) as $r) {
        $m[$r['id']] = $r;
    }
    return $m;
}

/* ---------------- gst ---------------- */
/* Intra-state (same state code) => CGST + SGST at half each. Otherwise IGST at full. */
function gst_split(float $taxable, float $gstRate, string $buyerCode): array {
    $home = cfg('company_state_code', '24');
    $intra = trim($buyerCode) === trim($home);
    if ($intra) {
        $half = $gstRate / 2;
        $amt  = round($taxable * $half / 100, 2);
        return ['cgst_rate' => $half, 'sgst_rate' => $half, 'igst_rate' => 0.0,
                'cgst_amount' => $amt, 'sgst_amount' => $amt, 'igst_amount' => 0.0];
    }
    return ['cgst_rate' => 0.0, 'sgst_rate' => 0.0, 'igst_rate' => $gstRate,
            'cgst_amount' => 0.0, 'sgst_amount' => 0.0,
            'igst_amount' => round($taxable * $gstRate / 100, 2)];
}

/* ---------------- ledger ---------------- */
function customer_balance(int $customerId, ?string $upto = null): float {
    $args = [$customerId];
    $cond = '';
    if ($upto) { $cond = ' AND invoice_date <= ?'; $args[] = $upto; }
    $inv = (float)scalar("SELECT COALESCE(SUM(grand_total),0) FROM invoices
                          WHERE customer_id = ? AND status = 'active'$cond", $args);
    $args2 = [$customerId];
    $cond2 = '';
    if ($upto) { $cond2 = ' AND pay_date <= ?'; $args2[] = $upto; }
    $pay = (float)scalar("SELECT COALESCE(SUM(amount),0) FROM payments WHERE customer_id = ?$cond2", $args2);
    $open = (float)scalar('SELECT opening_bal FROM customers WHERE id = ?', [$customerId]);
    return round($open + $inv - $pay, 2);
}

function flash(string $msg = null): ?string {
    if ($msg !== null) { $_SESSION['flash'] = $msg; return null; }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}
function redirect(string $to): void { header('Location: ' . $to); exit; }
