<?php
require_once __DIR__ . '/lib.php';
require_login();

$id  = (int)($_GET['id'] ?? 0);
$inv = $id ? one('SELECT * FROM invoices WHERE id = ?', [$id]) : null;
if ($id && !$inv) { http_response_code(404); exit('Invoice not found.'); }

$err = '';

/* ---------------- actions on an existing invoice ---------------- */
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    require_edit();
    $act = $_POST['action'] ?? '';

    if ($act === 'cancel' && $inv['status'] === 'active') {
        db()->beginTransaction();
        q("UPDATE invoices SET status = 'cancelled' WHERE id = ?", [$id]);
        q("UPDATE challans SET status = 'open', invoice_id = NULL WHERE invoice_id = ?", [$id]);
        q('DELETE FROM invoice_challans WHERE invoice_id = ?', [$id]);
        db()->commit();
        flash('Invoice ' . $inv['invoice_no'] . ' cancelled. Its challans are open again.');
        redirect('invoice_form.php?id=' . $id);
    }

    if ($act === 'delete') {
        db()->beginTransaction();
        q("UPDATE challans SET status = 'open', invoice_id = NULL WHERE invoice_id = ?", [$id]);
        q('DELETE FROM invoice_challans WHERE invoice_id = ?', [$id]);
        q('DELETE FROM invoice_items WHERE invoice_id = ?', [$id]);
        q('DELETE FROM invoices WHERE id = ?', [$id]);
        db()->commit();
        flash('Invoice ' . $inv['invoice_no'] . ' deleted. Its challans are open again and can be edited.');
        redirect('invoices.php');
    }

    if ($act === 'header') {
        $f = ['delivery_note','payment_terms','ref_no_date','other_ref','buyer_order_no','buyer_order_dt',
              'dispatch_doc','delivery_dt','dispatched_via','destination','delivery_terms','place_supply'];
        $vals = [];
        foreach ($f as $k) $vals[] = trim($_POST[$k] ?? '');
        $vals[] = $id;
        q('UPDATE invoices SET delivery_note=?,payment_terms=?,ref_no_date=?,other_ref=?,buyer_order_no=?,
           buyer_order_dt=?,dispatch_doc=?,delivery_dt=?,dispatched_via=?,destination=?,delivery_terms=?,
           place_supply=? WHERE id=?', $vals);
        flash('Invoice details updated.');
        redirect('invoice_form.php?id=' . $id);
    }
}

/* ---------------- create a new invoice ---------------- */
if (!$id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    require_edit();

    $customerId = (int)($_POST['customer_id'] ?? 0);
    $billType   = ($_POST['bill_type'] ?? 'gst') === 'nongst' ? 'nongst' : 'gst';
    $date       = $_POST['invoice_date'] ?: date('Y-m-d');
    $chIds      = array_map('intval', $_POST['challan_ids'] ?? []);
    $wantRoy    = !empty($_POST['add_royalty']);
    $royRate    = (float)($_POST['royalty_rate'] ?? 0);

    $cust = one('SELECT * FROM customers WHERE id = ?', [$customerId]);
    if (!$cust)        $err = 'Pick a customer.';
    elseif (!$chIds)   $err = 'Tick at least one challan to bill.';

    if (!$err) {
        $in   = implode(',', array_fill(0, count($chIds), '?'));
        $args = array_merge($chIds, [$customerId]);
        $chs  = all("SELECT * FROM challans WHERE id IN ($in) AND customer_id = ? AND status = 'open'", $args);
        if (count($chs) !== count($chIds)) {
            $err = 'Some challans were already billed or belong to another customer. Reload and try again.';
        }
    }

    if (!$err) {
        $items = all("SELECT ci.*, p.gst_rate, p.sort_order, p.is_royalty
                      FROM challan_items ci JOIN products p ON p.id = ci.product_id
                      WHERE ci.challan_id IN ($in)", $chIds);

        // one printed line per challan item — no merging, so each challan stays traceable
        $chMap = [];
        foreach ($chs as $c) $chMap[$c['id']] = $c;

        $lines = [];
        foreach ($items as $it) {
            $lines[] = [
                'challan_no'   => $chMap[$it['challan_id']]['challan_no'] ?? '',
                'challan_date' => $chMap[$it['challan_id']]['challan_date'] ?? '',
                'kata_chithi'  => $chMap[$it['challan_id']]['kata_chithi_no'] ?? '',
                'product_id'   => $it['product_id'],
                'product_name' => $it['product_name'],
                'hsn'          => $it['hsn'],
                'unit'         => $it['unit'],
                'rate'         => round((float)$it['rate'], 2),
                'qty'          => round((float)$it['qty'], 4),
                'amount'       => round((float)$it['amount'], 2),
                'gst_rate'     => (float)$it['gst_rate'],
                'sort_order'   => (int)$it['sort_order'],
            ];
        }
        usort($lines, fn($a, $b) => [$a['challan_date'], $a['challan_no'], $a['sort_order']]
                                 <=> [$b['challan_date'], $b['challan_no'], $b['sort_order']]);

        $materialQty = array_sum(array_column($lines, 'qty'));

        // royalty line: weight equals total material weight on this invoice, not tied to one challan
        $roy = one('SELECT * FROM products WHERE is_royalty = 1 AND active = 1 ORDER BY sort_order LIMIT 1');
        if ($wantRoy && $roy && $materialQty > 0) {
            if ($royRate <= 0) $royRate = rate_for($customerId, (int)$roy['id']);
            $lines[] = ['challan_no' => '', 'challan_date' => '9999-99-99', 'kata_chithi' => '',
                'product_id' => $roy['id'], 'product_name' => $roy['name'], 'hsn' => $roy['hsn'],
                'unit' => $roy['unit'], 'rate' => round($royRate, 2), 'qty' => round($materialQty, 4),
                'amount' => round(round($materialQty, 4) * round($royRate, 2), 2),
                'gst_rate' => (float)$roy['gst_rate'], 'sort_order' => 9999];
        }

        $rates = array_unique(array_map(fn($l) => (float)$l['gst_rate'], $lines));
        if ($billType === 'gst' && count($rates) > 1) {
            $err = 'These products carry different GST rates (' . implode('%, ', $rates)
                 . '%). One invoice can hold one rate. Split it into separate invoices.';
        }
    }

    if (!$err) {
        $taxable = round(array_sum(array_column($lines, 'amount')), 2);
        $totQty  = round(array_sum(array_column($lines, 'qty')), 4);
        $gstRate = $billType === 'gst' ? (float)reset($rates) : 0.0;
        $tax     = $billType === 'gst'
                 ? gst_split($taxable, $gstRate, (string)$cust['state_code'])
                 : ['cgst_rate'=>0,'sgst_rate'=>0,'igst_rate'=>0,'cgst_amount'=>0,'sgst_amount'=>0,'igst_amount'=>0];
        $grand   = round($taxable + $tax['cgst_amount'] + $tax['sgst_amount'] + $tax['igst_amount'], 2);

        db()->beginTransaction();
        try {
            $fy  = fy_of($date);
            $seq = next_invoice_seq($billType, $fy);
            $no  = format_invoice_no($billType, $seq, $fy);

            q('INSERT INTO invoices (invoice_no,bill_type,seq_no,fy,invoice_date,customer_id,
                buyer_name,buyer_address,buyer_gstin,buyer_state,buyer_code,place_supply,
                delivery_note,payment_terms,ref_no_date,other_ref,buyer_order_no,buyer_order_dt,
                dispatch_doc,delivery_dt,dispatched_via,destination,delivery_terms,
                taxable_value,cgst_rate,sgst_rate,igst_rate,cgst_amount,sgst_amount,igst_amount,
                total_qty,grand_total,created_by)
               VALUES (?,?,?,?,?,?, ?,?,?,?,?,?, ?,?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?,?,?, ?,?,?)', [
                $no, $billType, $seq, $fy, $date, $customerId,
                $cust['name'], $cust['address'], $cust['gstin'], $cust['state_name'], $cust['state_code'],
                trim($_POST['place_supply'] ?? '') ?: $cust['state_name'],
                trim($_POST['delivery_note'] ?? ''), trim($_POST['payment_terms'] ?? ''),
                trim($_POST['ref_no_date'] ?? ''), trim($_POST['other_ref'] ?? ''),
                trim($_POST['buyer_order_no'] ?? ''), trim($_POST['buyer_order_dt'] ?? ''),
                trim($_POST['dispatch_doc'] ?? ''), trim($_POST['delivery_dt'] ?? ''),
                trim($_POST['dispatched_via'] ?? ''), trim($_POST['destination'] ?? ''),
                trim($_POST['delivery_terms'] ?? ''),
                $taxable, $tax['cgst_rate'], $tax['sgst_rate'], $tax['igst_rate'],
                $tax['cgst_amount'], $tax['sgst_amount'], $tax['igst_amount'],
                $totQty, $grand, current_user()['id'],
            ]);
            $newId = (int)db()->lastInsertId();

            $n = 1;
            foreach ($lines as $l) {
                q('INSERT INTO invoice_items (invoice_id,product_id,product_name,hsn,unit,qty,rate,amount,line_no,challan_no,challan_date,kata_chithi)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                  [$newId, $l['product_id'], $l['product_name'], $l['hsn'], $l['unit'],
                   $l['qty'], $l['rate'], $l['amount'], $n++, $l['challan_no'],
                   ($l['challan_date'] === '9999-99-99' ? null : ($l['challan_date'] ?: null)), $l['kata_chithi']]);
            }
            foreach ($chs as $c) {
                q('INSERT INTO invoice_challans (invoice_id, challan_id) VALUES (?,?)', [$newId, $c['id']]);
                q("UPDATE challans SET status = 'invoiced', invoice_id = ? WHERE id = ?", [$newId, $c['id']]);
            }
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            throw $e;
        }
        flash('Invoice ' . $no . ' created.');
        redirect('invoice_form.php?id=' . $newId);
    }
}

/* ================= render ================= */
$customers = all('SELECT id, name FROM customers WHERE active = 1 ORDER BY name');

if ($id) {
    /* ---- existing invoice ---- */
    $items = all('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY line_no', [$id]);
    $chs   = all('SELECT ch.* FROM challans ch JOIN invoice_challans ic ON ic.challan_id = ch.id
                  WHERE ic.invoice_id = ? ORDER BY ch.challan_date, ch.id', [$id]);
    $tax   = $inv['cgst_amount'] + $inv['sgst_amount'] + $inv['igst_amount'];

    $PAGE_TITLE   = 'Invoice ' . $inv['invoice_no'];
    $PAGE_SUB     = $inv['buyer_name'] . ' · ' . dmy($inv['invoice_date']);
    $PAGE_ACTIONS = '<a class="btn primary" href="print_invoice.php?id=' . $id . '" target="_blank">Print / PDF</a>';
    require __DIR__ . '/header.php';
    ?>
    <?php if ($inv['status'] === 'cancelled'): ?>
      <div class="err">This invoice is cancelled. Its challans went back to open.</div>
    <?php endif; ?>

    <div class="panel">
      <h2>Lines</h2>
      <table class="data">
        <tr><th>#</th><th>Challan</th><th>Description</th><th>HSN/SAC</th><th class="num">Quantity</th>
            <th class="num">Rate</th><th>per</th><th class="num">Amount</th></tr>
        <?php foreach ($items as $it): ?>
          <tr><td><?= $it['line_no'] ?></td><td><?= h($it['challan_no'] ?: '—') ?></td>
            <td><b><?= h($it['product_name']) ?></b></td>
            <td><?= h($it['hsn']) ?></td><td class="num"><?= inr($it['qty'], 2) ?></td>
            <td class="num"><?= inr($it['rate']) ?></td><td><?= h($it['unit']) ?></td>
            <td class="num big"><?= inr($it['amount']) ?></td></tr>
        <?php endforeach; ?>
        <tr style="background:var(--dust)">
          <td colspan="4"><b>Taxable value</b></td>
          <td class="num big"><?= inr($inv['total_qty'], 2) ?></td>
          <td colspan="2"></td><td class="num big"><?= inr($inv['taxable_value']) ?></td>
        </tr>
        <?php if ($inv['bill_type'] === 'gst'): ?>
          <?php if ($inv['igst_amount'] > 0): ?>
            <tr><td colspan="7" style="text-align:right">IGST <?= inr($inv['igst_rate'],2) ?>%</td>
                <td class="num"><?= inr($inv['igst_amount']) ?></td></tr>
          <?php else: ?>
            <tr><td colspan="7" style="text-align:right">SGST <?= inr($inv['sgst_rate'],2) ?>%</td>
                <td class="num"><?= inr($inv['sgst_amount']) ?></td></tr>
            <tr><td colspan="7" style="text-align:right">CGST <?= inr($inv['cgst_rate'],2) ?>%</td>
                <td class="num"><?= inr($inv['cgst_amount']) ?></td></tr>
          <?php endif; ?>
        <?php endif; ?>
      </table>
    </div>

    <div class="panel">
      <h2>Challans on this invoice</h2>
      <table class="data">
        <tr><th>Challan</th><th>Date</th><th>Kata chithi</th><th>Vehicle</th><th class="num">Weight</th></tr>
        <?php foreach ($chs as $c): ?>
          <tr><td><b><?= h($c['challan_no']) ?></b></td><td><?= dmy($c['challan_date']) ?></td>
            <td><?= h($c['kata_chithi_no']) ?></td><td><?= h($c['vehicle_no']) ?></td>
            <td class="num"><?= inr($c['total_qty'], 2) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$chs): ?><tr><td colspan="5" class="empty">Released — invoice is cancelled.</td></tr><?php endif; ?>
      </table>
    </div>

    <?php if (can_edit() && $inv['status'] === 'active'): ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="header">
      <div class="panel">
        <h2>Bill header details (optional)</h2>
        <div class="body">
          <div class="grid g3">
            <?php
            $hf = ['place_supply'=>'Place of supply','delivery_note'=>'Delivery note','payment_terms'=>'Mode/terms of payment',
                   'ref_no_date'=>'Reference no. & date','other_ref'=>'Other references','buyer_order_no'=>"Buyer's order no.",
                   'buyer_order_dt'=>'Order dated','dispatch_doc'=>'Dispatch doc no.','delivery_dt'=>'Delivery note date',
                   'dispatched_via'=>'Dispatched through','destination'=>'Destination','delivery_terms'=>'Terms of delivery'];
            foreach ($hf as $k => $lbl): ?>
              <label class="f"><span><?= h($lbl) ?></span>
                <input type="text" name="<?= $k ?>" value="<?= h($inv[$k]) ?>"></label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="actions"><button class="btn primary">Save details</button></div>
    </form>

    <form method="post" onsubmit="return confirm('Cancel <?= h($inv['invoice_no']) ?>? The number stays used and its challans reopen.')">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="cancel">
      <div class="actions" style="margin-top:28px"><button class="btn danger">Cancel this invoice</button></div>
      <p class="hint">GST needs an unbroken number series, so the number is never reused.</p>
    </form>
    <?php endif; ?>

    <?php if (is_admin()): ?>
    <form method="post" onsubmit="return confirm('Delete <?= h($inv['invoice_no']) ?> completely? Its challans go back to open so you can edit them. This cannot be undone.')">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="delete">
      <div class="actions" style="margin-top:10px"><button class="btn danger">Delete this invoice</button></div>
      <p class="hint">Removes the invoice entirely and reopens its challans for editing.</p>
    </form>
    <?php endif; ?>

    <?php
    require __DIR__ . '/footer.php';
    exit;
}

/* ---- new invoice ---- */
require_edit();
$customerId = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
$billType   = $_GET['bill_type'] ?? $_POST['bill_type'] ?? 'gst';
$billType   = $billType === 'nongst' ? 'nongst' : 'gst';
$date       = $_GET['invoice_date'] ?? $_POST['invoice_date'] ?? date('Y-m-d');
$cust       = $customerId ? one('SELECT * FROM customers WHERE id = ?', [$customerId]) : null;

$open = [];
$chItems = [];
if ($cust) {
    $open = all("SELECT * FROM challans WHERE customer_id = ? AND status = 'open'
                 ORDER BY challan_date, id", [$customerId]);
    if ($open) {
        $ids = array_column($open, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        foreach (all("SELECT ci.*, p.sort_order, p.is_royalty FROM challan_items ci
                      JOIN products p ON p.id = ci.product_id WHERE ci.challan_id IN ($in)", $ids) as $r) {
            $chItems[$r['challan_id']][] = $r;
        }
    }
}
$chMeta = [];
foreach ($open as $c) $chMeta[$c['id']] = ['no' => $c['challan_no'], 'date' => $c['challan_date']];

$royProd = one('SELECT * FROM products WHERE is_royalty = 1 AND active = 1 ORDER BY sort_order LIMIT 1');
$royRate = ($royProd && $cust) ? rate_for($customerId, (int)$royProd['id']) : 0;
$custBalance = $cust ? customer_balance((int)$customerId) : 0.0;
$previewGst = (float)(scalar('SELECT gst_rate FROM products WHERE active = 1 AND is_royalty = 0
                             ORDER BY sort_order LIMIT 1') ?? 5);

$customers = all('SELECT id, name FROM customers WHERE active = 1 ORDER BY name');

$PAGE_TITLE = 'New invoice';
$PAGE_SUB   = $cust ? $cust['name'] : 'Pick a customer to see their open challans';
require __DIR__ . '/header.php';
?>
<?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>

<form class="filters" method="get">
  <label class="f" style="min-width:260px"><span>Customer</span>
    <select name="customer_id" class="searchable" data-placeholder="Type party name…" onchange="this.form.submit()">
      <option value="">— select —</option>
      <?php foreach ($customers as $c): ?>
        <option value="<?= $c['id'] ?>"<?= $customerId === (int)$c['id'] ? ' selected' : '' ?>><?= h($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="f" style="min-width:180px"><span>Bill type</span>
    <select name="bill_type" onchange="this.form.submit()">
      <option value="gst"<?= $billType === 'gst' ? ' selected' : '' ?>>GST — Tax Invoice</option>
      <option value="nongst"<?= $billType === 'nongst' ? ' selected' : '' ?>>Without GST</option>
    </select>
  </label>
  <label class="f"><span>Invoice date</span>
    <input type="date" name="invoice_date" value="<?= h($date) ?>" onchange="this.form.submit()"></label>
  <div class="hint" style="align-self:center">
    Next number: <b><?= h(format_invoice_no($billType, next_invoice_seq($billType, fy_of($date)), fy_of($date))) ?></b>
  </div>
</form>

<?php if (!$cust): ?>
  <div class="panel"><div class="empty">Choose a customer above.</div></div>
<?php elseif (!$open): ?>
  <div class="panel"><div class="empty">
    <?= h($cust['name']) ?> has no open challans. <a href="challan_form.php">Create one</a>.
  </div></div>
<?php else: ?>

<form method="post" id="invform">
<input type="hidden" name="csrf" value="<?= csrf() ?>">
<input type="hidden" name="customer_id" value="<?= $customerId ?>">
<input type="hidden" name="bill_type" value="<?= h($billType) ?>">
<input type="hidden" name="invoice_date" value="<?= h($date) ?>">

<div class="panel">
  <h2>Open challans — tick what goes on this bill</h2>
  <table class="data" id="chtable">
    <tr>
      <th style="width:44px"><input type="checkbox" id="all" checked style="width:22px;height:22px"></th>
      <th>Challan</th><th>Date</th><th class="hidem">Kata chithi</th><th class="hidem">Vehicle</th>
      <th class="num">Weight</th><th class="num">Amount</th>
    </tr>
    <?php foreach ($open as $c): ?>
      <tr>
        <td><input type="checkbox" class="chk" name="challan_ids[]" value="<?= $c['id'] ?>" checked
                   style="width:22px;height:22px"></td>
        <td><b><?= h($c['challan_no']) ?></b></td>
        <td><?= dmy($c['challan_date']) ?></td>
        <td class="hidem"><?= h($c['kata_chithi_no']) ?></td>
        <td class="hidem"><?= h($c['vehicle_no']) ?></td>
        <td class="num"><?= inr($c['total_qty'], 2) ?></td>
        <td class="num big"><?= inr($c['total_amount']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php if ($royProd): ?>
<div class="panel">
  <h2>Royalty</h2>
  <div class="body">
    <div class="grid g2">
      <label class="f"><span>Add royalty line</span>
        <label style="display:flex;align-items:center;gap:10px;padding:12px 0;font-size:18px">
          <input type="checkbox" name="add_royalty" id="addroy" value="1" style="width:22px;height:22px"
            <?= ($billType === 'gst' && cfg('royalty_default','1') === '1') ? 'checked' : '' ?>>
          <?= h($royProd['name']) ?> · HSN <?= h($royProd['hsn']) ?>
        </label>
        <div class="hint">Weight = total material weight on this bill. Printed as the last line.</div>
      </label>
      <label class="f"><span>Royalty rate per <?= h($royProd['unit']) ?></span>
        <input type="number" step="0.01" name="royalty_rate" id="royrate" value="<?= h(number_format($royRate,2,'.','')) ?>">
      </label>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="panel">
  <h2>Bill header details (optional)</h2>
  <div class="body">
    <div class="grid g3">
      <label class="f"><span>Place of supply</span>
        <input type="text" name="place_supply" value="<?= h($cust['state_name']) ?>"></label>
      <?php
      $hf = ['delivery_note'=>'Delivery note','payment_terms'=>'Mode/terms of payment',
             'ref_no_date'=>'Reference no. & date','other_ref'=>'Other references',
             'buyer_order_no'=>"Buyer's order no.",'buyer_order_dt'=>'Order dated',
             'dispatch_doc'=>'Dispatch doc no.','delivery_dt'=>'Delivery note date',
             'dispatched_via'=>'Dispatched through','destination'=>'Destination',
             'delivery_terms'=>'Terms of delivery'];
      foreach ($hf as $k => $lbl): ?>
        <label class="f"><span><?= h($lbl) ?></span><input type="text" name="<?= $k ?>"></label>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="totals">
  <div class="cell"><span>Challans</span><b id="rN">0</b></div>
  <div class="cell"><span>Weight</span><b id="rQ">0.00</b></div>
  <div class="cell"><span>Taxable</span><b id="rT">0.00</b></div>
  <div class="cell"><span>Tax</span><b id="rX">0.00</b></div>
  <div class="cell"><span>Present outstanding</span><b>&#8377; <?= inr($custBalance) ?></b></div>
  <div class="spacer"></div>
  <div class="cell total"><span>Grand total</span><b id="rG">0.00</b></div>
</div>

<div class="actions">
  <button class="btn primary">Create invoice</button>
  <a class="btn ghost" href="invoices.php">Cancel</a>
</div>
</form>


<script>
const CH_ITEMS = <?= json_encode($chItems, JSON_UNESCAPED_UNICODE) ?>;
const CH_META  = <?= json_encode($chMeta, JSON_UNESCAPED_UNICODE) ?>;
const GST_RATE = <?= json_encode($billType === 'gst' ? $previewGst : 0) ?>;
const IS_GST   = <?= $billType === 'gst' ? 'true' : 'false' ?>;
const INTRA    = <?= trim((string)$cust['state_code']) === trim(cfg('company_state_code','24')) ? 'true' : 'false' ?>;
const ROY      = <?= $royProd ? json_encode($royProd, JSON_UNESCAPED_UNICODE) : 'null' ?>;

function inr(n){
  n = (Number(n)||0).toFixed(2).split('.');
  let i = n[0], last3 = i.slice(-3), rest = i.slice(0,-3);
  if (rest) rest = rest.replace(/\B(?=(\d{2})+(?!\d))/g, ',');
  return (rest ? rest + ',' + last3 : last3) + '.' + n[1];
}
const r2 = n => Math.round(n * 100) / 100;

function build(){
  const picked = [...document.querySelectorAll('.chk:checked')].map(c => c.value);
  let lines = [];
  picked.forEach(cid => {
    const meta = CH_META[cid] || {no:'', date:''};
    (CH_ITEMS[cid] || []).forEach(it => {
      lines.push({challanNo: meta.no, challanDate: meta.date,
        name: it.product_name, hsn: it.hsn, unit: it.unit,
        rate: r2(Number(it.rate)), qty: Math.round(Number(it.qty)*1e4)/1e4,
        amount: r2(Number(it.amount)), sort: Number(it.sort_order)});
    });
  });
  lines.sort((a,b) => a.challanDate.localeCompare(b.challanDate) || a.challanNo.localeCompare(b.challanNo) || a.sort - b.sort);

  const matQty = lines.reduce((s,l) => s + l.qty, 0);
  const royOn  = ROY && document.getElementById('addroy') && document.getElementById('addroy').checked;
  if (royOn && matQty > 0) {
    const rr = Number(document.getElementById('royrate').value) || 0;
    const q  = Math.round(matQty * 1e4) / 1e4;
    lines.push({challanNo:'', challanDate:'9999', name: ROY.name, hsn: ROY.hsn, unit: ROY.unit,
      rate: rr, qty: q, amount: r2(q * rr), sort: 9999});
  }

  const taxable = r2(lines.reduce((s,l) => s + l.amount, 0));
  const totQty  = Math.round(lines.reduce((s,l) => s + l.qty, 0) * 1e4) / 1e4;
  let cg = 0, sg = 0, ig = 0;
  if (IS_GST) {
    if (INTRA) { cg = sg = r2(taxable * (GST_RATE/2) / 100); }
    else       { ig = r2(taxable * GST_RATE / 100); }
  }
  const grand = r2(taxable + cg + sg + ig);

  document.getElementById('rN').textContent = picked.length;
  document.getElementById('rQ').textContent = totQty.toFixed(2);
  document.getElementById('rT').textContent = inr(taxable);
  document.getElementById('rX').textContent = inr(cg + sg + ig);
  document.getElementById('rG').textContent = inr(grand);
}

document.getElementById('all').addEventListener('change', e => {
  document.querySelectorAll('.chk').forEach(c => c.checked = e.target.checked);
  build();
});
document.getElementById('chtable').addEventListener('change', build);
document.getElementById('invform').addEventListener('input', build);
document.getElementById('invform').addEventListener('change', build);
build();
</script>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
