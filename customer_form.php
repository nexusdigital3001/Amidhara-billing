<?php
require_once __DIR__ . '/lib.php';
require_login();
require_edit();

$id  = (int)($_GET['id'] ?? 0);
$row = $id ? one('SELECT * FROM customers WHERE id = ?', [$id]) : null;
if ($id && !$row) { http_response_code(404); exit('Customer not found.'); }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $d = [
        'name'        => trim($_POST['name'] ?? ''),
        'address'     => trim($_POST['address'] ?? ''),
        'gstin'       => strtoupper(trim($_POST['gstin'] ?? '')),
        'state_name'  => trim($_POST['state_name'] ?? 'Gujarat'),
        'state_code'  => trim($_POST['state_code'] ?? '24'),
        'phone'       => trim($_POST['phone'] ?? ''),
        'email'       => trim($_POST['email'] ?? ''),
        'opening_bal' => (float)($_POST['opening_bal'] ?? 0),
        'active'      => isset($_POST['active']) ? 1 : 0,
    ];
    if ($d['name'] === '') {
        $err = 'Customer name is required.';
    } else {
        db()->beginTransaction();
        if ($id) {
            q('UPDATE customers SET name=?,address=?,gstin=?,state_name=?,state_code=?,phone=?,email=?,opening_bal=?,active=?
               WHERE id=?', [...array_values($d), $id]);
        } else {
            q('INSERT INTO customers (name,address,gstin,state_name,state_code,phone,email,opening_bal,active)
               VALUES (?,?,?,?,?,?,?,?,?)', array_values($d));
            $id = (int)db()->lastInsertId();
        }
        // price list
        foreach (($_POST['price'] ?? []) as $pid => $val) {
            $pid = (int)$pid;
            $val = trim($val);
            if ($val === '') {
                q('DELETE FROM customer_prices WHERE customer_id=? AND product_id=?', [$id, $pid]);
            } else {
                q('INSERT INTO customer_prices (customer_id,product_id,rate) VALUES (?,?,?)
                   ON DUPLICATE KEY UPDATE rate=VALUES(rate)', [$id, $pid, (float)$val]);
            }
        }
        db()->commit();
        flash('Saved ' . $d['name'] . '.');
        redirect('customers.php');
    }
    $row = array_merge($row ?? [], $d);
}

$products = all('SELECT * FROM products WHERE active=1 ORDER BY sort_order, name');
$override = [];
if ($id) foreach (all('SELECT product_id, rate FROM customer_prices WHERE customer_id=?', [$id]) as $r)
    $override[$r['product_id']] = $r['rate'];

$PAGE_TITLE = $id ? 'Edit customer' : 'Add customer';
$PAGE_SUB   = $id ? ($row['name'] ?? '') : '';
require __DIR__ . '/header.php';
?>
<?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= csrf() ?>">

<div class="panel">
  <h2>Details</h2>
  <div class="body">
    <div class="grid g2">
      <label class="f"><span>Customer name</span>
        <input type="text" name="name" required value="<?= h($row['name'] ?? '') ?>">
      </label>
      <label class="f"><span>GSTIN / UIN</span>
        <input type="text" name="gstin" maxlength="20" value="<?= h($row['gstin'] ?? '') ?>">
      </label>
    </div>
    <div class="grid" style="margin-top:16px">
      <label class="f"><span>Billing address</span>
        <textarea name="address" rows="3"><?= h($row['address'] ?? '') ?></textarea>
        <div class="hint">Prints exactly as typed. Use line breaks the way you want them on the bill.</div>
      </label>
    </div>
    <div class="grid g4" style="margin-top:16px">
      <label class="f"><span>State</span>
        <input type="text" name="state_name" value="<?= h($row['state_name'] ?? 'Gujarat') ?>">
      </label>
      <label class="f"><span>State code</span>
        <input type="text" name="state_code" maxlength="4" value="<?= h($row['state_code'] ?? '24') ?>">
        <div class="hint">Same as <?= h(cfg('company_state_code','24')) ?> = CGST+SGST. Different = IGST.</div>
      </label>
      <label class="f"><span>Phone</span>
        <input type="text" name="phone" value="<?= h($row['phone'] ?? '') ?>">
      </label>
      <label class="f"><span>Email</span>
        <input type="text" name="email" value="<?= h($row['email'] ?? '') ?>">
      </label>
    </div>
    <div class="grid g2" style="margin-top:16px">
      <label class="f"><span>Opening balance</span>
        <input type="number" step="0.01" name="opening_bal" value="<?= h($row['opening_bal'] ?? '0.00') ?>">
        <div class="hint">Amount already due when you started using this system.</div>
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

<div class="panel">
  <h2>Price list for this customer</h2>
  <div class="body">
    <p class="hint" style="margin:0 0 14px">
      Leave blank to use the master rate. Anything you type here is used for this customer only —
      and it updates itself whenever you bill them at a different rate.
    </p>
    <table class="lines">
      <tr><th>Product</th><th class="num" style="text-align:right">Master rate</th>
          <th style="width:200px">This customer</th></tr>
      <?php foreach ($products as $p): ?>
        <tr>
          <td style="font-size:18px;font-weight:600"><?= h($p['name']) ?>
            <span style="color:var(--grit);font-weight:400;font-size:15px">· <?= h($p['unit']) ?></span></td>
          <td class="amt" style="font-weight:400;color:var(--grit)"><?= inr($p['rate']) ?></td>
          <td class="num"><input type="number" step="0.01" name="price[<?= $p['id'] ?>]"
              placeholder="—" value="<?= isset($override[$p['id']]) ? h($override[$p['id']]) : '' ?>"></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>

<div class="actions">
  <button class="btn primary">Save customer</button>
  <a class="btn ghost" href="customers.php">Cancel</a>
</div>
</form>
<?php require __DIR__ . '/footer.php'; ?>
