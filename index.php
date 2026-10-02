<?php
require_once __DIR__ . '/lib.php';
require_login();

$items = [
    ['Challans',          'challans.php',  'Delivery challans — create, list, print'],
    ['Invoices',          'invoices.php',  'Bill customers, GST and non-GST'],
    ['Payments received', 'payments.php',  'Record cash and bank receipts'],
    ['Ledger',            'ledger.php',    'Running balance per customer'],
    ['Customers',         'customers.php', 'Party master and price lists'],
    ['Products',          'products.php',  'Material master, rates, GST'],
];
if (is_admin()) {
    $items[] = ['Users',    'users.php',    'Accounts and roles'];
    $items[] = ['Settings', 'settings.php', 'Company, bank, numbering'];
}

$PAGE_TITLE = 'Dashboard';
require __DIR__ . '/header.php';
?>
<div class="grid g3 menu-grid">
  <?php foreach ($items as [$label, $href, $desc]): ?>
    <a class="menu-card" href="<?= h($href) ?>">
      <span class="menu-card-title"><?= h($label) ?></span>
      <span class="menu-card-desc"><?= h($desc) ?></span>
    </a>
  <?php endforeach; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
