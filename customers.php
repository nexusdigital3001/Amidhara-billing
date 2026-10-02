<?php
require_once __DIR__ . '/lib.php';
require_login();

$search = trim($_GET['q'] ?? '');
$sql = "SELECT c.*,
        c.opening_bal
        + COALESCE((SELECT SUM(grand_total) FROM invoices i WHERE i.customer_id=c.id AND i.status='active'),0)
        - COALESCE((SELECT SUM(amount) FROM payments p WHERE p.customer_id=c.id),0) AS bal
        FROM customers c WHERE 1";
$args = [];
if ($search !== '') { $sql .= ' AND (c.name LIKE ? OR c.gstin LIKE ?)'; $args[] = "%$search%"; $args[] = "%$search%"; }
$sql .= ' ORDER BY c.active DESC, c.name';
$rows = all($sql, $args);

$PAGE_TITLE = 'Customers';
$PAGE_SUB   = count($rows) . ' on file';
if (can_edit()) $PAGE_ACTIONS = '<a class="btn primary" href="customer_form.php">Add customer</a>';
require __DIR__ . '/header.php';
?>
<form class="filters" method="get">
  <label class="f" style="flex:1;min-width:260px"><span>Search</span>
    <input type="text" name="q" value="<?= h($search) ?>" placeholder="Name or GSTIN">
  </label>
  <button class="btn">Search</button>
  <?php if ($search !== ''): ?><a class="btn ghost" href="customers.php">Clear</a><?php endif; ?>
</form>

<div class="panel">
  <?php if (!$rows): ?>
    <div class="empty">No customers match. Add one to start billing.</div>
  <?php else: ?>
  <table class="data">
    <tr><th>Name</th><th class="hidem">GSTIN</th><th class="hidem">State</th><th class="hidem">Phone</th><th class="num">Balance</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
      <tr<?= $r['active'] ? '' : ' style="opacity:.5"' ?>>
        <td><b><?= h($r['name']) ?></b><?= $r['active'] ? '' : ' <span class="tag cancelled">Inactive</span>' ?></td>
        <td class="hidem"><?= h($r['gstin']) ?></td>
        <td class="hidem"><?= h($r['state_name']) ?> · <?= h($r['state_code']) ?></td>
        <td class="hidem"><?= h($r['phone']) ?></td>
        <td class="num big"><?= inr($r['bal']) ?></td>
        <td class="num" style="white-space:nowrap">
          <a class="btn sm ghost" href="ledger.php?customer_id=<?= $r['id'] ?>">Ledger</a>
          <?php if (can_edit()): ?><a class="btn sm" href="customer_form.php?id=<?= $r['id'] ?>">Edit</a><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
