<?php
require_once __DIR__ . '/lib.php';
require_login();
require_edit();

$id  = (int)($_GET['id'] ?? 0);
$row = $id ? one('SELECT * FROM products WHERE id = ?', [$id]) : null;
if ($id && !$row) { http_response_code(404); exit('Product not found.'); }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $d = [
        'name'       => trim($_POST['name'] ?? ''),
        'hsn'        => trim($_POST['hsn'] ?? ''),
        'unit'       => trim($_POST['unit'] ?? 'Ton'),
        'rate'       => (float)($_POST['rate'] ?? 0),
        'gst_rate'   => (float)($_POST['gst_rate'] ?? 5),
        'is_royalty' => isset($_POST['is_royalty']) ? 1 : 0,
        'active'     => isset($_POST['active']) ? 1 : 0,
        'sort_order' => (int)($_POST['sort_order'] ?? 0),
    ];
    if ($d['name'] === '' || $d['hsn'] === '') {
        $err = 'Name and HSN/SAC are both required — the bill prints them.';
    } else {
        if ($id) {
            q('UPDATE products SET name=?,hsn=?,unit=?,rate=?,gst_rate=?,is_royalty=?,active=?,sort_order=? WHERE id=?',
              [...array_values($d), $id]);
        } else {
            q('INSERT INTO products (name,hsn,unit,rate,gst_rate,is_royalty,active,sort_order) VALUES (?,?,?,?,?,?,?,?)',
              array_values($d));
        }
        flash('Saved ' . $d['name'] . '.');
        redirect('products.php');
    }
    $row = array_merge($row ?? [], $d);
}

$PAGE_TITLE = $id ? 'Edit product' : 'Add product';
require __DIR__ . '/header.php';
?>
<?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= csrf() ?>">
<div class="panel">
  <div class="body">
    <div class="grid g2">
      <label class="f"><span>Product name</span>
        <input type="text" name="name" required value="<?= h($row['name'] ?? '') ?>">
        <div class="hint">Prints under "Description of Goods".</div>
      </label>
      <label class="f"><span>HSN / SAC</span>
        <input type="text" name="hsn" required value="<?= h($row['hsn'] ?? '2517') ?>">
      </label>
    </div>
    <div class="grid g4" style="margin-top:16px">
      <label class="f"><span>Unit</span>
        <input type="text" name="unit" value="<?= h($row['unit'] ?? 'Ton') ?>">
      </label>
      <label class="f"><span>Master rate</span>
        <input type="number" step="0.01" name="rate" value="<?= h($row['rate'] ?? '0.00') ?>">
      </label>
      <label class="f"><span>GST rate %</span>
        <input type="number" step="0.01" name="gst_rate" value="<?= h($row['gst_rate'] ?? '5.00') ?>">
      </label>
      <label class="f"><span>Sort order</span>
        <input type="number" name="sort_order" value="<?= h($row['sort_order'] ?? '0') ?>">
      </label>
    </div>
    <div class="grid g2" style="margin-top:16px">
      <label class="f"><span>Royalty line</span>
        <label style="display:flex;align-items:center;gap:10px;padding:12px 0;font-size:18px">
          <input type="checkbox" name="is_royalty" value="1" style="width:22px;height:22px"
                 <?= ($row['is_royalty'] ?? 0) ? 'checked' : '' ?>> Add automatically on invoices
        </label>
        <div class="hint">Weight is taken as the total material weight on the invoice.</div>
      </label>
      <label class="f"><span>Status</span>
        <label style="display:flex;align-items:center;gap:10px;padding:12px 0;font-size:18px">
          <input type="checkbox" name="active" value="1" style="width:22px;height:22px"
                 <?= (!$id || ($row['active'] ?? 1)) ? 'checked' : '' ?>> Active
        </label>
      </label>
    </div>
  </div>
</div>
<div class="actions">
  <button class="btn primary">Save product</button>
  <a class="btn ghost" href="products.php">Cancel</a>
</div>
</form>
<?php require __DIR__ . '/footer.php'; ?>
