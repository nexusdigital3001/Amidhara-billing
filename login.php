<?php
require_once __DIR__ . '/lib.php';
if (current_user()) redirect('index.php');

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';

    // Brute-force protection. Only active when harden.php is loaded;
    // without it these functions don't exist and login behaves as before.
    $locked = function_exists('login_is_locked') && login_is_locked($u);

    if ($locked) {
        $err = 'Too many failed attempts. Please wait a few minutes and try again.';
    } else {
        $row = one('SELECT * FROM users WHERE username = ? AND active = 1', [$u]);
        if ($row && password_verify($p, $row['password_hash'])) {
            if (function_exists('login_record')) login_record($u, true);
            session_regenerate_id(true);
            $_SESSION['uid'] = $row['id'];
            redirect('index.php');
        }
        if (function_exists('login_record')) login_record($u, false);
        $err = 'Wrong username or password.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="login">
  <div class="box">
    <h1><?= h(cfg('company_name', 'Amidhara Stone Crusher')) ?></h1>
    <p class="s">Billing</p>
    <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
    <form method="post">
      <div class="grid" style="gap:16px">
        <label class="f"><span>Username</span>
          <input type="text" name="username" autofocus autocomplete="username" required>
        </label>
        <label class="f"><span>Password</span>
          <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button class="btn primary" style="width:100%;padding:14px">Sign in</button>
      </div>
    </form>
  </div>
</div>
</body>
</html>
