<?php
require_once __DIR__ . '/lib.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$ch = one('SELECT ch.*, c.name AS cust, c.address, c.gstin, c.state_name, c.state_code
           FROM challans ch JOIN customers c ON c.id = ch.customer_id WHERE ch.id = ?', [$id]);
if (!$ch) { http_response_code(404); exit('Challan not found.'); }
$items  = all('SELECT * FROM challan_items WHERE challan_id = ? ORDER BY id', [$id]);
$copies = max(1, min(3, (int)($_GET['copies'] ?? 1)));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($ch['challan_no']) ?></title>
<link rel="stylesheet" href="assets/print.css">
<style>.sheet{min-height:0;padding-bottom:6px}</style>
</head>
<body>

<div class="toolbar">
  <b><?= h($ch['challan_no']) ?></b>
  <span class="tip">Print, then choose "Save as PDF" as the destination.</span>
  <div class="spacer"></div>
  <a href="print_challan.php?id=<?= $id ?>&copies=2">2 copies</a>
  <a href="challan_form.php?id=<?= $id ?>">Back</a>
  <button class="go" onclick="window.print()">Print / Save as PDF</button>
</div>

<?php for ($copy = 0; $copy < $copies; $copy++): ?>
<div class="sheet">
<table class="doc">
  <colgroup>
    <col style="width:6%"><col style="width:38%"><col style="width:12%">
    <col style="width:16%"><col style="width:12%"><col style="width:16%">
  </colgroup>

  <tr><td class="title" colspan="6">Delivery Challan
    <?php if ($ch['status'] === 'cancelled'): ?> <span class="cancel-stamp">CANCELLED</span><?php endif; ?>
  </td></tr>

  <tr class="meta">
    <td colspan="3" rowspan="2" class="seller">
      <b><?= h(cfg('company_name')) ?></b><br>
      <?= nl2brh(cfg('company_address')) ?><br>
      GSTIN/UIN: <?= h(cfg('company_gstin')) ?><br>
      State Name : <?= h(cfg('company_state')) ?>, Code : <?= h(cfg('company_state_code')) ?>
    </td>
    <td colspan="2"><div class="lbl">Challan No.</div><div class="val"><?= h($ch['challan_no']) ?></div></td>
    <td><div class="lbl">Dated</div><div class="val"><?= dmy($ch['challan_date']) ?></div></td>
  </tr>
  <tr class="meta">
    <td colspan="2"><div class="lbl">Kata Chithi No.</div><div class="val"><?= h($ch['kata_chithi_no']) ?></div></td>
    <td><div class="lbl">Vehicle No.</div><div class="val"><?= h($ch['vehicle_no']) ?></div></td>
  </tr>

  <tr class="meta">
    <td colspan="3" rowspan="2" class="buyer">
      <div class="lbl">Party</div>
      <b><?= h($ch['cust']) ?></b><br>
      <?= nl2brh($ch['address']) ?><br>
      <?php if ($ch['gstin']): ?>GSTIN/UIN&nbsp;&nbsp;&nbsp;: <?= h($ch['gstin']) ?><br><?php endif; ?>
      State Name&nbsp;&nbsp;: <?= h($ch['state_name']) ?>, Code : <?= h($ch['state_code']) ?>
    </td>
    <td colspan="3" style="height:34px"><div class="lbl">Remarks</div>
      <div class="val"><?= h($ch['remarks']) ?></div></td>
  </tr>
  <tr class="meta">
    <td colspan="3"><div class="lbl">Status</div><div class="val"><?= h(ucfirst($ch['status'])) ?></div></td>
  </tr>

  <tr class="items">
    <th>Sl<br>No.</th><th>Description of Goods</th><th>HSN/SAC</th>
    <th>Weight</th><th>Rate</th><th>Amount</th>
  </tr>
  <?php $n = 1; foreach ($items as $it): ?>
  <tr class="items">
    <td class="c"><?= $n++ ?></td>
    <td class="desc"><?= h($it['product_name']) ?></td>
    <td class="c"><?= h($it['hsn']) ?></td>
    <td class="r nowrap"><?= qty($it['qty']) ?> <?= h($it['unit']) ?></td>
    <td class="r"><?= inr($it['rate']) ?></td>
    <td class="r"><?= inr($it['amount']) ?></td>
  </tr>
  <?php endforeach; ?>
  <tr class="items" style="height:<?= max(30, 130 - count($items) * 16) ?>px">
    <td></td><td></td><td></td><td></td><td></td><td></td>
  </tr>

  <tr class="tot">
    <td colspan="3" class="r">Total</td>
    <td class="r nowrap"><?= inr($ch['total_qty'], 2) ?> <?= h($items[0]['unit'] ?? '') ?></td>
    <td></td>
    <td class="r nowrap">&#8377; <?= inr($ch['total_amount']) ?></td>
  </tr>

  <tr><td colspan="6" class="words">
    Amount (in words)<br><b><?= h(amount_words($ch['total_amount'])) ?></b>
  </td></tr>

  <tr>
    <td colspan="3" class="foot sign">Receiver's Signature</td>
    <td colspan="3" class="foot sign">
      <div class="for">for <?= h(cfg('company_name')) ?></div>
      <div class="auth">Authorised Signatory</div>
    </td>
  </tr>
  <tr><td colspan="6" class="cgen">Not a tax invoice. Goods delivered against this challan are billed separately.</td></tr>
</table>
</div>
<?php endfor; ?>
</body>
</html>
