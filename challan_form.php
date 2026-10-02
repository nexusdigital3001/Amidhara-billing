<?php
require_once __DIR__ . '/lib.php';
require_login();

$id  = (int)($_GET['id'] ?? 0);
$row = $id ? one('SELECT * FROM challans WHERE id = ?', [$id]) : null;
if ($id && !$row) { http_response_code(404); exit('Challan not found.'); }

$locked = $id && ($row['status'] !== 'open' || !can_edit());
if (!$id) require_edit();

$err   = '';
$items = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$locked) {
    check_csrf();
    require_edit();

    $customerId = (int)($_POST['customer_id'] ?? 0);
    $date       = $_POST['challan_date'] ?: date('Y-m-d');
    $challanNo  = trim($_POST['challan_no'] ?? '');
    $kata       = trim($_POST['kata_chithi_no'] ?? '');
    $vehicle    = trim($_POST['vehicle_no'] ?? '');
    $remarks    = trim($_POST['remarks'] ?? '');

    $prods = [];
    foreach (all('SELECT * FROM products') as $p) $prods[$p['id']] = $p;

    $lines = [];
    $pids  = $_POST['product_id'] ?? [];
    foreach ($pids as $i => $pid) {
        $pid = (int)$pid;
        $qv  = (float)($_POST['qty'][$i] ?? 0);
        $rv  = (float)($_POST['rate'][$i] ?? 0);
        if (!$pid || !isset($prods[$pid]) || $qv <= 0) continue;
        $p = $prods[$pid];
        $lines[] = [
            'product_id'   => $pid,
            'product_name' => $p['name'],
            'hsn'          => $p['hsn'],
            'unit'         => $p['unit'],
            'qty'          => round($qv, 4),
            'rate'         => round($rv, 2),
            'amount'       => round(round($qv, 4) * round($rv, 2), 2),
        ];
    }

    if (!$customerId)      $err = 'Pick a customer.';
    elseif (!$challanNo)   $err = 'Enter a challan number.';
    elseif (!$lines)       $err = 'Add at least one line with a weight above zero.';

    if (!$err) {
        // challan number must be unique (ignoring this record when editing)
        $dupe = $id
            ? scalar('SELECT id FROM challans WHERE challan_no = ? AND id <> ?', [$challanNo, $id])
            : scalar('SELECT id FROM challans WHERE challan_no = ?', [$challanNo]);
        if ($dupe) $err = 'Challan number "' . $challanNo . '" is already used. Enter a different one.';
    }

    if (!$err) {
        $totQty = array_sum(array_column($lines, 'qty'));
        $totAmt = array_sum(array_column($lines, 'amount'));
        db()->beginTransaction();
        try {
            if ($id) {
                q('UPDATE challans SET challan_no=?,challan_date=?,customer_id=?,kata_chithi_no=?,vehicle_no=?,remarks=?,
                   total_qty=?,total_amount=? WHERE id=?',
                  [$challanNo, $date, $customerId, $kata, $vehicle, $remarks, $totQty, $totAmt, $id]);
                q('DELETE FROM challan_items WHERE challan_id = ?', [$id]);
            } else {
                $fy = fy_of($date);
                q('INSERT INTO challans (challan_no,seq_no,fy,challan_date,customer_id,kata_chithi_no,vehicle_no,
                   remarks,total_qty,total_amount,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                  [$challanNo, 0, $fy, $date, $customerId, $kata, $vehicle, $remarks, $totQty, $totAmt, current_user()['id']]);
                $id = (int)db()->lastInsertId();
            }
            foreach ($lines as $l) {
                q('INSERT INTO challan_items (challan_id,product_id,product_name,hsn,unit,qty,rate,amount)
                   VALUES (?,?,?,?,?,?,?,?)',
                  [$id, $l['product_id'], $l['product_name'], $l['hsn'], $l['unit'], $l['qty'], $l['rate'], $l['amount']]);
                remember_price($customerId, $l['product_id'], $l['rate']);
            }
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            throw $e;
        }
        flash('Challan saved.');
        redirect('challans.php?customer_id=' . $customerId);
    }
    $row = array_merge($row ?? [], [
        'customer_id' => $customerId, 'challan_date' => $date, 'kata_chithi_no' => $kata,
        'vehicle_no' => $vehicle, 'remarks' => $remarks,
    ]);
    $items = $lines;
} elseif ($id) {
    $items = all('SELECT * FROM challan_items WHERE challan_id = ? ORDER BY id', [$id]);
}

$customers = all('SELECT id, name FROM customers WHERE active = 1 ORDER BY name');
$products  = all('SELECT id,name,hsn,unit,rate,is_royalty FROM products WHERE active = 1 ORDER BY sort_order, name');

// product master rates + every customer override, so rate fills in without a round trip
$masterRate = [];
foreach ($products as $p) $masterRate[$p['id']] = (float)$p['rate'];
$overrides = [];
foreach (all('SELECT customer_id, product_id, rate FROM customer_prices') as $o)
    $overrides[$o['customer_id']][$o['product_id']] = (float)$o['rate'];

$PAGE_TITLE = $id ? ($locked ? 'Challan ' . $row['challan_no'] : 'Edit challan ' . $row['challan_no']) : 'New challan';
$PAGE_SUB   = $id ? ucfirst($row['status']) : 'Enter your own challan number';
$PAGE_ACTIONS = $id ? '<a class="btn" href="print_challan.php?id=' . $id . '" target="_blank">Print / PDF</a>' : '';
require __DIR__ . '/header.php';
?>
<?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
<?php if ($locked && $id && $row['status'] === 'invoiced'): ?>
  <div class="flash">Already invoiced, so it can't be changed. Cancel the invoice first to reopen it.</div>
<?php endif; ?>

<form method="post" id="chform">
<input type="hidden" name="csrf" value="<?= csrf() ?>">
<fieldset style="border:0;padding:0;margin:0" <?= $locked ? 'disabled' : '' ?>>

<div class="panel">
  <h2>Challan</h2>
  <div class="body">
    <div class="grid g4">
      <label class="f"><span>Challan no.</span>
        <input type="text" name="challan_no" required value="<?= h($row['challan_no'] ?? '') ?>"
               placeholder="e.g. 001" autocomplete="off">
      </label>
      <label class="f"><span>Date</span>
        <input type="date" name="challan_date" required value="<?= h($row['challan_date'] ?? date('Y-m-d')) ?>">
      </label>
      <label class="f"><span>Party name</span>
        <select name="customer_id" id="cust" class="searchable" data-placeholder="Type party name…" required>
          <option value="">— select —</option>
          <?php foreach ($customers as $c): ?>
            <option value="<?= $c['id'] ?>"<?= (int)($row['customer_id'] ?? 0) === (int)$c['id'] ? ' selected' : '' ?>>
              <?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="f"><span>Kata chithi no.</span>
        <input type="text" name="kata_chithi_no" value="<?= h($row['kata_chithi_no'] ?? '') ?>">
      </label>
    </div>
    <div class="grid g2" style="margin-top:16px">
      <label class="f"><span>Vehicle no.</span>
        <input type="text" name="vehicle_no" value="<?= h($row['vehicle_no'] ?? '') ?>">
      </label>
      <label class="f"><span>Remarks</span>
        <input type="text" name="remarks" value="<?= h($row['remarks'] ?? '') ?>">
      </label>
    </div>
  </div>
</div>

<div class="panel">
  <h2>Material</h2>
  <div class="body">
    <table class="lines" id="lines">
      <thead>
        <tr>
          <th style="width:38%">Item</th>
          <th style="width:18%">Weight</th>
          <th style="width:18%">Rate</th>
          <th style="width:20%;text-align:right">Amount</th>
          <th style="width:44px"></th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
    <?php if (!$locked): ?>
      <div class="actions" style="margin-top:14px">
        <button type="button" class="btn ghost" id="addrow">+ Add line</button>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="totals">
  <div class="cell"><span>Lines</span><b id="rLines">0</b></div>
  <div class="cell"><span>Total weight</span><b id="rQty">0.00</b></div>
  <div class="spacer"></div>
  <div class="cell total"><span>Challan total</span><b id="rAmt">0.00</b></div>
</div>

<?php if (!$locked): ?>
<div class="actions">
  <button class="btn primary">Save challan</button>
  <a class="btn ghost" href="challans.php">Cancel</a>
</div>
<?php endif; ?>
</fieldset>
</form>


<script>
const PRODUCTS  = <?= json_encode($products, JSON_UNESCAPED_UNICODE) ?>;
const MASTER    = <?= json_encode($masterRate) ?>;
const OVERRIDES = <?= json_encode($overrides) ?>;
const EXISTING  = <?= json_encode($items, JSON_UNESCAPED_UNICODE) ?>;
const LOCKED    = <?= $locked ? 'true' : 'false' ?>;

const tbody = document.querySelector('#lines tbody');

function inr(n){
  n = (Number(n)||0).toFixed(2).split('.');
  let i = n[0], last3 = i.slice(-3), rest = i.slice(0,-3);
  if (rest) rest = rest.replace(/\B(?=(\d{2})+(?!\d))/g, ',');
  return (rest ? rest + ',' + last3 : last3) + '.' + n[1];
}
function rateFor(pid){
  const cid = document.getElementById('cust').value;
  if (cid && OVERRIDES[cid] && OVERRIDES[cid][pid] !== undefined) return OVERRIDES[cid][pid];
  return MASTER[pid] !== undefined ? MASTER[pid] : 0;
}
function addRow(data){
  const tr = document.createElement('tr');
  const opts = PRODUCTS.map(p =>
    `<option value="${p.id}" ${data && Number(data.product_id)===Number(p.id) ? 'selected':''}>${p.name} (${p.unit})</option>`
  ).join('');
  tr.innerHTML =
    `<td data-label="Item"><select name="product_id[]" class="pid"><option value="">— item —</option>${opts}</select></td>
     <td data-label="Weight" class="num"><input type="number" step="0.01" min="0" name="qty[]" class="qty" value="${data ? data.qty : ''}" placeholder="0.00"></td>
     <td data-label="Rate" class="num"><input type="number" step="0.01" min="0" name="rate[]" class="rate" value="${data ? data.rate : ''}" placeholder="0.00"></td>
     <td data-label="Amount" class="amt">0.00</td>
     <td><button type="button" class="rowdel" title="Remove line">&times;</button></td>`;
  tbody.appendChild(tr);
  if (LOCKED) tr.querySelector('.rowdel').style.display = 'none';
  recalc();
}
function recalc(){
  let q = 0, a = 0, n = 0;
  tbody.querySelectorAll('tr').forEach(tr => {
    const qty  = Number(tr.querySelector('.qty').value)  || 0;
    const rate = Number(tr.querySelector('.rate').value) || 0;
    const amt  = Math.round(qty * rate * 100) / 100;
    tr.querySelector('.amt').textContent = inr(amt);
    if (qty > 0) { q += qty; a += amt; n++; }
  });
  document.getElementById('rLines').textContent = n;
  document.getElementById('rQty').textContent   = q.toFixed(2);
  document.getElementById('rAmt').textContent   = inr(a);
}

tbody.addEventListener('input', e => {
  if (e.target.classList.contains('pid')) {
    const tr = e.target.closest('tr');
    const r  = rateFor(e.target.value);
    if (r) tr.querySelector('.rate').value = r;
    tr.querySelector('.qty').focus();
  }
  recalc();
});
tbody.addEventListener('click', e => {
  if (e.target.classList.contains('rowdel')) {
    e.target.closest('tr').remove();
    if (!tbody.children.length) addRow();
    recalc();
  }
});
document.getElementById('cust').addEventListener('change', () => {
  tbody.querySelectorAll('tr').forEach(tr => {
    const pid = tr.querySelector('.pid').value;
    if (pid) { const r = rateFor(pid); if (r) tr.querySelector('.rate').value = r; }
  });
  recalc();
});
const addBtn = document.getElementById('addrow');
if (addBtn) addBtn.addEventListener('click', () => { addRow(); tbody.lastElementChild.querySelector('.pid').focus(); });

if (EXISTING.length) EXISTING.forEach(addRow); else addRow();
recalc();
</script>
<?php require __DIR__ . '/footer.php'; ?>
