<?php
require_once __DIR__ . '/lib.php';
require_login();

$rows = all('SELECT * FROM products ORDER BY active DESC, sort_order, name');
$PAGE_TITLE = 'Products';
$PAGE_SUB   = count($rows) . ' on file';
if (can_edit()) $PAGE_ACTIONS = '<a class="btn primary" href="product_form.php">Add product</a>';
require __DIR__ . '/header.php';
?>
<div class="panel">
  <table class="data">
    <tr><th>Product</th><th>HSN / SAC</th><th>Unit</th><th class="num">Rate</th><th class="num">GST</th><th>Type</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
      <tr<?= $r['active'] ? '' : ' style="opacity:.5"' ?>>
        <td><b><?= h($r['name']) ?></b><?= $r['active'] ? '' : ' <span class="tag cancelled">Inactive</span>' ?></td>
        <td><?= h($r['hsn']) ?></td>
        <td><?= h($r['unit']) ?></td>
        <td class="num big"><?= inr($r['rate']) ?></td>
        <td class="num"><?= inr($r['gst_rate'], 2) ?>%</td>
        <td><?= $r['is_royalty'] ? '<span class="tag gst">Royalty</span>' : '<span class="tag nongst">Material</span>' ?></td>
        <td class="num"><?php if (can_edit()): ?><a class="btn sm" href="product_form.php?id=<?= $r['id'] ?>">Edit</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<p class="hint">
  A product flagged <b>Royalty</b> is not picked by hand. On a GST invoice it is added automatically as the
  last line, at a weight equal to the total material weight on that invoice.
</p>
<?php require __DIR__ . '/footer.php'; ?>
