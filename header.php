<?php
require_once __DIR__ . '/lib.php';
require_login();
$me   = current_user();
$here = basename($_SERVER['PHP_SELF']);
$PAGE_TITLE = $PAGE_TITLE ?? 'Amidhara';
$PAGE_SUB   = $PAGE_SUB   ?? '';
function nav_on(array $files): string {
    return in_array(basename($_SERVER['PHP_SELF']), $files, true) ? ' class="on"' : '';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($PAGE_TITLE) ?> — <?= h(cfg('company_name', 'Amidhara')) ?></title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="scrim" onclick="document.body.classList.remove('navopen')"></div>
<div class="shell">
  <aside class="rail">
    <div class="brand">
      <b><?= h(cfg('company_name', 'Amidhara Stone Crusher')) ?></b>
      <span>Billing</span>
    </div>
    <nav>
      <a href="index.php"<?= nav_on(['index.php']) ?>>Dashboard</a>
      <div class="sep">Daily work</div>
      <a href="challans.php"<?= nav_on(['challans.php','challan_form.php']) ?>>Challans</a>
      <a href="invoices.php"<?= nav_on(['invoices.php','invoice_form.php']) ?>>Invoices</a>
      <a href="payments.php"<?= nav_on(['payments.php']) ?>>Payments received</a>
      <a href="ledger.php"<?= nav_on(['ledger.php']) ?>>Ledger</a>
      <div class="sep">Masters</div>
      <a href="customers.php"<?= nav_on(['customers.php','customer_form.php']) ?>>Customers</a>
      <a href="products.php"<?= nav_on(['products.php','product_form.php']) ?>>Products</a>
      <?php if (is_admin()): ?>
        <div class="sep">Admin</div>
        <a href="users.php"<?= nav_on(['users.php']) ?>>Users</a>
        <a href="settings.php"<?= nav_on(['settings.php']) ?>>Settings</a>
      <?php endif; ?>
    </nav>
    <div class="who">
      <b><?= h($me['full_name']) ?></b>
      <?= h(ucfirst($me['role'])) ?> · <a href="logout.php">Sign out</a>
    </div>
  </aside>
  <div class="main">
    <div class="topbar">
      <button class="navtoggle" onclick="document.body.classList.toggle('navopen')" aria-label="Menu"><span></span></button>
      <h1><?= h($PAGE_TITLE) ?></h1>
      <?php if ($PAGE_SUB): ?><div class="sub"><?= h($PAGE_SUB) ?></div><?php endif; ?>
      <div class="spacer"></div>
      <div id="pageActions"><?= $PAGE_ACTIONS ?? '' ?></div>
    </div>
    <div class="wrap">
    <?php if ($f = flash()): ?><div class="flash"><?= h($f) ?></div><?php endif; ?>
