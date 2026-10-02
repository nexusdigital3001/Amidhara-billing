<?php
require_once __DIR__ . '/lib.php';
require_login();

$cid  = (int)($_GET['customer_id'] ?? 0);
$from = $_GET['from'] ?? '';
$to   = $_GET['to'] ?? '';
$cust = $cid ? one('SELECT * FROM customers WHERE id = ?', [$cid]) : null;

$rowsL   = [];
$opening = 0.0;
if ($cust) {
    // everything before the window collapses into one opening figure
    $opening = (float)$cust['opening_bal'];
    if ($from !== '') {
        $opening += (float)scalar("SELECT COALESCE(SUM(grand_total),0) FROM invoices
                                   WHERE customer_id = ? AND status='active' AND invoice_date < ?", [$cid, $from]);
        $opening -= (float)scalar('SELECT COALESCE(SUM(amount),0) FROM payments
                                   WHERE customer_id = ? AND pay_date < ?', [$cid, $from]);
    }

    $ia = [$cid]; $ic = '';
    if ($from !== '') { $ic .= ' AND invoice_date >= ?'; $ia[] = $from; }
    if ($to   !== '') { $ic .= ' AND invoice_date <= ?'; $ia[] = $to; }
    foreach (all("SELECT invoice_date AS d, invoice_no AS ref, bill_type, grand_total AS amt, id
                  FROM invoices WHERE customer_id = ? AND status = 'active'$ic", $ia) as $r) {
        $rowsL[] = ['d' => $r['d'], 'type' => 'invoice', 'id' => $r['id'],
                    'particulars' => 'Invoice ' . $r['ref'] . ($r['bill_type'] === 'nongst' ? ' (no GST)' : ''),
                    'debit' => (float)$r['amt'], 'credit' => 0.0];
    }

    $pa = [$cid]; $pc = '';
    if ($from !== '') { $pc .= ' AND pay_date >= ?'; $pa[] = $from; }
    if ($to   !== '') { $pc .= ' AND pay_date <= ?'; $pa[] = $to; }
    foreach (all("SELECT pay_date AS d, mode, amount, ref_no, note, id
                  FROM payments WHERE customer_id = ?$pc", $pa) as $r) {
        $lbl = 'Payment received — ' . ucfirst($r['mode']);
        if ($r['ref_no']) $lbl .= ' · ' . $r['ref_no'];
        if ($r['note'])   $lbl .= ' · ' . $r['note'];
        $rowsL[] = ['d' => $r['d'], 'type' => 'payment', 'id' => $r['id'],
                    'particulars' => $lbl, 'debit' => 0.0, 'credit' => (float)$r['amount']];
    }

    usort($rowsL, fn($a, $b) => [$a['d'], $a['type']] <=> [$b['d'], $b['type']]);
}

$customers = all('SELECT id, name FROM customers ORDER BY name');
$PAGE_TITLE = 'Ledger';
$PAGE_SUB   = $cust ? $cust['name'] : 'Pick a customer';
if ($cust) $PAGE_ACTIONS = '<button class="btn" onclick="window.print()">Print</button>';
require __DIR__ . '/header.php';
?>
<style>
@media print{
  .rail,.topbar,.filters,.readout{display:none!important}
  .wrap{padding:0}
  body{background:#fff}
  .panel{border:0}
  table.data td,table.data th{padding:6px 8px;font-size:12px}
}
</style>

<form class="filters" method="get">
  <label class="f" style="min-width:280px"><span>Customer</span>
    <select name="customer_id" onchange="this.form.submit()">
      <option value="">— select —</option>
      <?php foreach ($customers as $c): ?>
        <option value="<?= $c['id'] ?>"<?= $cid === (int)$c['id'] ? ' selected' : '' ?>><?= h($c['name']) ?></option>
      <?php endforeach; ?>
    </select></label>
  <label class="f"><span>From</span><input type="date" name="from" value="<?= h($from) ?>"></label>
  <label class="f"><span>To</span><input type="date" name="to" value="<?= h($to) ?>"></label>
  <button class="btn">Apply</button>
  <?php if ($cid): ?><a class="btn ghost" href="ledger.php?customer_id=<?= $cid ?>">Full history</a><?php endif; ?>
</form>

<?php if (!$cust): ?>
  <div class="panel"><div class="empty">Choose a customer to see their account.</div></div>
<?php else: ?>
<div class="panel">
  <h2><?= h($cust['name']) ?><?= ($from || $to) ? ' — ' . h($from ?: 'start') . ' to ' . h($to ?: 'today') : '' ?></h2>
  <table class="data">
    <tr><th>Date</th><th>Particulars</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr>
    <tr style="background:var(--dust)">
      <td></td><td><b>Opening balance</b></td><td></td><td></td>
      <td class="num big"><?= inr($opening) ?></td>
    </tr>
    <?php
    $bal = $opening; $dr = 0; $cr = 0;
    foreach ($rowsL as $r):
      $bal += $r['debit'] - $r['credit'];
      $dr  += $r['debit']; $cr += $r['credit']; ?>
      <tr>
        <td><?= dmy($r['d']) ?></td>
        <td><?php if ($r['type'] === 'invoice'): ?>
              <a href="invoice_form.php?id=<?= $r['id'] ?>"><?= h($r['particulars']) ?></a>
            <?php else: ?><?= h($r['particulars']) ?><?php endif; ?></td>
        <td class="num"><?= $r['debit']  ? inr($r['debit'])  : '' ?></td>
        <td class="num"><?= $r['credit'] ? inr($r['credit']) : '' ?></td>
        <td class="num big"><?= inr($bal) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rowsL): ?>
      <tr><td colspan="5" class="empty">No entries in this period.</td></tr>
    <?php endif; ?>
    <tr style="background:var(--dust)">
      <td colspan="2"><b>Closing balance</b></td>
      <td class="num big"><?= inr($dr) ?></td>
      <td class="num big"><?= inr($cr) ?></td>
      <td class="num big"><?= inr($bal) ?></td>
    </tr>
  </table>
</div>
<p class="hint">Debit = billed to them. Credit = received from them. A positive closing balance is money still due.</p>

<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
