<?php
require_once __DIR__ . '/lib.php';
require_login();

$id  = (int)($_GET['id'] ?? 0);
$inv = one('SELECT * FROM invoices WHERE id = ?', [$id]);
if (!$inv) { http_response_code(404); exit('Invoice not found.'); }
$items = all('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY line_no', [$id]);

$isGst   = $inv['bill_type'] === 'gst';
$isIgst  = $inv['igst_amount'] > 0;
$taxTot  = round($inv['cgst_amount'] + $inv['sgst_amount'] + $inv['igst_amount'], 2);
$title   = $isGst ? 'Tax Invoice' : 'Invoice';

/* Money summary shown near the total:
   previous balance (before this bill) + this bill - received = net outstanding */
$custId       = (int)$inv['customer_id'];
$netOutstanding = customer_balance($custId);            // opening + active invoices - payments
$received     = (float)scalar('SELECT COALESCE(SUM(amount),0) FROM payments WHERE customer_id = ?', [$custId]);
$thisTotal    = (float)$inv['grand_total'];
// balance the customer carried before this invoice was raised
$prevBalance  = round($netOutstanding + $received - $thisTotal, 2);
$outstanding  = $netOutstanding; // kept for backward compatibility below
$copies  = max(1, min(4, (int)($_GET['copies'] ?? 1)));
$labels  = ['Original for Recipient', 'Duplicate for Transporter', 'Triplicate for Supplier', 'Extra Copy'];
$auto    = isset($_GET['auto']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($inv['invoice_no']) ?></title>
<link rel="stylesheet" href="assets/print.css">
</head>
<body>

<div class="toolbar">
  <b><?= h($inv['invoice_no']) ?></b>
  <span class="tip">Print, then choose "Save as PDF" as the destination.</span>
  <div class="spacer"></div>
  <a href="print_invoice.php?id=<?= $id ?>&copies=3">3 copies</a>
  <a href="invoice_form.php?id=<?= $id ?>">Back</a>
  <button class="go" onclick="window.print()">Print / Save as PDF</button>
</div>

<?php if (!$isGst): /* ---------- simple bill: no GST, minimal columns ---------- */ ?>
<?php for ($copy = 0; $copy < $copies; $copy++): ?>
<div class="sheet simple">
  <div class="s-head">
    <div class="s-left">
      <h1><?= h(cfg('company_name')) ?></h1>
      <div class="s-sub"><?= nl2brh(cfg('company_address')) ?></div>
    </div>
    <div class="s-right">
      <h2>INVOICE</h2>
      <div class="s-sub">Invoice #: <?= h($inv['invoice_no']) ?></div>
      <div class="s-sub">Date: <?= dmy($inv['invoice_date']) ?></div>
      <?php if ($inv['status'] === 'cancelled'): ?>
        <div style="margin-top:6px"><span class="cancel-stamp">CANCELLED</span></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="s-billto">
    <b>Bill To:</b>
    <div class="s-sub" style="margin-top:8px">
      <?= h($inv['buyer_name']) ?><br>
      <?= nl2brh($inv['buyer_address']) ?>
    </div>
  </div>

  <table class="s-tbl">
    <thead>
      <tr>
        <th style="width:5%">S.N.</th>
        <th style="width:10%">Date</th>
        <th style="width:14%">Challan No.</th>
        <th style="width:11%">Kata Chithi</th>
        <th>Description of Goods</th>
        <th style="width:13%" class="r">Weight (<?= h($items[0]['unit'] ?? 'Ton') ?>)</th>
        <th style="width:10%" class="r">Rate</th>
        <th style="width:14%" class="r">Total</th>
      </tr>
    </thead>
    <tbody>
      <?php $n = 1; foreach ($items as $it): ?>
      <tr>
        <td><?= $n++ ?></td>
        <td class="nowrap"><?= $it['challan_date'] ? dmy($it['challan_date']) : '—' ?></td>
        <td class="nowrap"><?= h($it['challan_no'] ?: '—') ?></td>
        <td class="nowrap"><?= h($it['kata_chithi'] ?: '—') ?></td>
        <td><b><?= h($it['product_name']) ?></b></td>
        <td class="r nowrap"><?= qty($it['qty']) ?></td>
        <td class="r"><?= inr($it['rate']) ?></td>
        <td class="r nowrap"><?= inr($it['amount']) ?></td>
      </tr>
      <?php endforeach; ?>
      <tr class="s-tot">
        <td colspan="5" class="r">Total</td>
        <td class="r nowrap"><?= inr($inv['total_qty'], 2) ?></td>
        <td></td>
        <td class="r">&#8377; <?= inr($inv['grand_total']) ?></td>
      </tr>
      <tr class="s-sum">
        <td colspan="7" class="r">Previous balance</td>
        <td class="r">&#8377; <?= inr($prevBalance) ?></td>
      </tr>
      <tr class="s-sum">
        <td colspan="7" class="r">This invoice</td>
        <td class="r">&#8377; <?= inr($thisTotal) ?></td>
      </tr>
      <tr class="s-sum">
        <td colspan="7" class="r">Amount received</td>
        <td class="r">&#8722; &#8377; <?= inr($received) ?></td>
      </tr>
      <tr class="s-sum s-net">
        <td colspan="7" class="r">Net outstanding</td>
        <td class="r"><b>&#8377; <?= inr($netOutstanding) ?></b></td>
      </tr>
    </tbody>
  </table>

  <div class="s-words">
    Amount in words: <b><?= h(amount_words($inv['grand_total'])) ?></b>
  </div>

  <div class="s-foot">
    <div class="s-bank"></div>
    <div class="s-sign">
      for <?= h(cfg('company_name')) ?>
      <div class="s-authline">Authorised Signatory</div>
    </div>
  </div>
</div>
<?php endfor; ?>

<?php else: /* ---------- full GST tax invoice ---------- */ ?>

<?php for ($copy = 0; $copy < $copies; $copy++): ?>
<div class="sheet">
<table class="doc">
  <colgroup>
    <col style="width:5%"><col style="width:32%"><col style="width:10%">
    <col style="width:14%"><col style="width:11%"><col style="width:6%"><col style="width:22%">
  </colgroup>

  <tr><td class="title" colspan="7">
    <?= h($title) ?>
    <?php if ($inv['status'] === 'cancelled'): ?> <span class="cancel-stamp">CANCELLED</span><?php endif; ?>
    <?php if ($copies > 1): ?>
      <div style="font-size:9.5px;font-weight:400"><?= h($labels[$copy]) ?></div>
    <?php endif; ?>
  </td></tr>

  <!-- seller + invoice meta -->
  <tr class="meta">
    <td colspan="3" rowspan="2" class="seller">
      <b><?= h(cfg('company_name')) ?></b><br>
      <?= nl2brh(cfg('company_address')) ?><br>
      GSTIN/UIN: <?= h(cfg('company_gstin')) ?><br>
      State Name : <?= h(cfg('company_state')) ?>, Code : <?= h(cfg('company_state_code')) ?>
    </td>
    <td colspan="2"><div class="lbl">Invoice No.</div><div class="val"><?= h($inv['invoice_no']) ?></div></td>
    <td colspan="2"><div class="lbl">Dated</div><div class="val"><?= dmy($inv['invoice_date']) ?></div></td>
  </tr>
  <tr class="meta">
    <td colspan="2"><div class="lbl">Delivery Note</div><div class="val"><?= h($inv['delivery_note']) ?></div></td>
    <td colspan="2"><div class="lbl">Mode/Terms of Payment</div><div class="val"><?= h($inv['payment_terms']) ?></div></td>
  </tr>

  <!-- buyer + remaining meta -->
  <tr class="meta">
    <td colspan="3" rowspan="5" class="buyer">
      <div class="lbl">Buyer (Bill to)</div>
      <b><?= h($inv['buyer_name']) ?></b><br>
      <?= nl2brh($inv['buyer_address']) ?><br>
      <?php if ($inv['buyer_gstin']): ?>GSTIN/UIN&nbsp;&nbsp;&nbsp;: <?= h($inv['buyer_gstin']) ?><br><?php endif; ?>
      State Name&nbsp;&nbsp;: <?= h($inv['buyer_state']) ?>, Code : <?= h($inv['buyer_code']) ?><br>
      <?php if ($isGst): ?>Place of Supply : <?= h($inv['place_supply']) ?><?php endif; ?>
    </td>
    <td colspan="2"><div class="lbl">Reference No. &amp; Date.</div><div class="val"><?= h($inv['ref_no_date']) ?></div></td>
    <td colspan="2"><div class="lbl">Other References</div><div class="val"><?= h($inv['other_ref']) ?></div></td>
  </tr>
  <tr class="meta">
    <td colspan="2"><div class="lbl">Buyer's Order No.</div><div class="val"><?= h($inv['buyer_order_no']) ?></div></td>
    <td colspan="2"><div class="lbl">Dated</div><div class="val"><?= h($inv['buyer_order_dt']) ?></div></td>
  </tr>
  <tr class="meta">
    <td colspan="2"><div class="lbl">Dispatch Doc No.</div><div class="val"><?= h($inv['dispatch_doc']) ?></div></td>
    <td colspan="2"><div class="lbl">Delivery Note Date</div><div class="val"><?= h($inv['delivery_dt']) ?></div></td>
  </tr>
  <tr class="meta">
    <td colspan="2"><div class="lbl">Dispatched through</div><div class="val"><?= h($inv['dispatched_via']) ?></div></td>
    <td colspan="2"><div class="lbl">Destination</div><div class="val"><?= h($inv['destination']) ?></div></td>
  </tr>
  <tr class="meta">
    <td colspan="4" style="height:34px"><div class="lbl">Terms of Delivery</div>
      <div class="val"><?= h($inv['delivery_terms']) ?></div></td>
  </tr>
</table>

<!-- items: its own table so a Challan No. column doesn't disturb the header layout above -->
<table class="doc" style="border-top:0">
  <colgroup>
    <col style="width:4%"><col style="width:13%"><col style="width:21%"><col style="width:8%">
    <col style="width:13%"><col style="width:10%"><col style="width:5%"><col style="width:26%">
  </colgroup>

  <tr class="items">
    <th>Sl<br>No.</th><th>Challan<br>No.</th><th>Description of Goods</th><th>HSN/SAC</th>
    <th>Quantity</th><th>Rate</th><th>per</th><th>Amount</th>
  </tr>
  <?php foreach ($items as $it): ?>
  <tr class="items">
    <td class="c"><?= $it['line_no'] ?></td>
    <td class="c"><?= h($it['challan_no'] ?: '—') ?></td>
    <td class="desc"><?= h($it['product_name']) ?></td>
    <td class="c"><?= h($it['hsn']) ?></td>
    <td class="r nowrap"><?= qty($it['qty']) ?></td>
    <td class="r"><?= inr($it['rate']) ?></td>
    <td class="c"><?= h($it['unit']) ?></td>
    <td class="r"><?= inr($it['amount']) ?></td>
  </tr>
  <?php endforeach; ?>

  <!-- sub total: sits alone in the Amount column under a short rule, like Tally -->
  <tr class="items">
    <td></td><td></td><td></td><td></td><td></td><td></td><td></td>
    <td class="r topline"><?= inr($inv['taxable_value']) ?></td>
  </tr>

  <?php if ($isGst):
    $taxRows = $isIgst
      ? [['IGST', $inv['igst_rate'], $inv['igst_amount']]]
      : [['SGST', $inv['sgst_rate'], $inv['sgst_amount']],
         ['CGST', $inv['cgst_rate'], $inv['cgst_amount']]];
    foreach ($taxRows as [$lbl, $rate, $amt]): ?>
      <tr class="items">
        <td></td><td></td>
        <td class="taxlbl"><?= $lbl ?> <?= rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') ?>%</td>
        <td></td>
        <td class="r"><?= number_format($rate, 2) ?></td>
        <td class="c">%</td>
        <td></td>
        <td class="r"><?= inr($amt) ?></td>
      </tr>
  <?php endforeach; endif; ?>

  <!-- filler that stretches the item box down the page, column rules intact -->
  <tr class="items" style="height:<?= max(40, 240 - count($items) * 16) ?>px">
    <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
  </tr>

  <tr class="tot">
    <td colspan="4" class="r">Total</td>
    <td class="r nowrap"><?= inr($inv['total_qty'], 2) ?> <?= h($items[0]['unit'] ?? '') ?></td>
    <td></td><td></td>
    <td class="r nowrap">&#8377; <?= inr($inv['grand_total']) ?></td>
  </tr>
  <tr class="gsum"><td colspan="6"></td><td class="r">Previous balance</td>
      <td class="r">&#8377; <?= inr($prevBalance) ?></td></tr>
  <tr class="gsum"><td colspan="6"></td><td class="r">This invoice</td>
      <td class="r">&#8377; <?= inr($thisTotal) ?></td></tr>
  <tr class="gsum"><td colspan="6"></td><td class="r">Amount received</td>
      <td class="r">&#8722; &#8377; <?= inr($received) ?></td></tr>
  <tr class="gsum gnet"><td colspan="6"></td><td class="r"><b>Net outstanding</b></td>
      <td class="r"><b>&#8377; <?= inr($netOutstanding) ?></b></td></tr>
  <tr><td colspan="8" class="nb r" style="font-size:9.5px;padding-right:4px">E. &amp; O.E</td></tr>

  <tr><td colspan="8" class="words">
    Amount Chargeable (in words)<br>
    <b><?= h(amount_words($inv['grand_total'])) ?></b>
  </td></tr>

  <?php if ($isGst): ?>
  <tr><td colspan="8" style="padding:0">
    <table class="taxtbl">
      <colgroup>
        <col style="width:34%"><col style="width:11%"><col style="width:16%">
        <col style="width:11%"><col style="width:16%"><col style="width:12%">
      </colgroup>
      <?php if ($isIgst): ?>
        <tr><th rowspan="2" style="vertical-align:bottom">Taxable<br>Value</th>
            <th colspan="2">IGST</th><th colspan="2"></th>
            <th rowspan="2" style="vertical-align:bottom">Total<br>Tax Amount</th></tr>
        <tr><th>Rate</th><th>Amount</th><th></th><th></th></tr>
        <tr><td class="r"><?= inr($inv['taxable_value']) ?></td>
            <td><?= number_format($inv['igst_rate'], 2) ?>%</td>
            <td class="r"><?= inr($inv['igst_amount']) ?></td><td></td><td></td>
            <td class="r"><?= inr($taxTot) ?></td></tr>
        <tr><td class="r"><b>Total: <?= inr($inv['taxable_value']) ?></b></td>
            <td></td><td class="r"><b><?= inr($inv['igst_amount']) ?></b></td><td></td><td></td>
            <td class="r"><b><?= inr($taxTot) ?></b></td></tr>
      <?php else: ?>
        <tr><th rowspan="2" style="vertical-align:bottom">Taxable<br>Value</th>
            <th colspan="2">CGST</th><th colspan="2">SGST/UTGST</th>
            <th rowspan="2" style="vertical-align:bottom">Total<br>Tax Amount</th></tr>
        <tr><th>Rate</th><th>Amount</th><th>Rate</th><th>Amount</th></tr>
        <tr><td class="r"><?= inr($inv['taxable_value']) ?></td>
            <td><?= number_format($inv['cgst_rate'], 2) ?>%</td>
            <td class="r"><?= inr($inv['cgst_amount']) ?></td>
            <td><?= number_format($inv['sgst_rate'], 2) ?>%</td>
            <td class="r"><?= inr($inv['sgst_amount']) ?></td>
            <td class="r"><?= inr($taxTot) ?></td></tr>
        <tr><td class="r"><b>Total: <?= inr($inv['taxable_value']) ?></b></td>
            <td></td><td class="r"><b><?= inr($inv['cgst_amount']) ?></b></td>
            <td></td><td class="r"><b><?= inr($inv['sgst_amount']) ?></b></td>
            <td class="r"><b><?= inr($taxTot) ?></b></td></tr>
      <?php endif; ?>
    </table>
  </td></tr>

  <tr><td colspan="8" class="words">
    Tax Amount (in words) : <b><?= h(amount_words($taxTot)) ?></b>
  </td></tr>
  <?php endif; ?>

  <tr>
    <td colspan="4" class="foot" style="height:78px">
      <u>Declaration</u><br>
      <?= nl2brh(cfg('declaration')) ?>
    </td>
    <td colspan="4" class="foot">
      <b>Company's Bank Details</b><br>
      A/c Holder's Name&nbsp;: <b><?= h(cfg('bank_holder')) ?></b><br>
      Bank Name&nbsp;: <b><?= h(cfg('bank_name')) ?></b><br>
      A/c No.&nbsp;: <b><?= h(cfg('bank_ac')) ?></b><br>
      Branch &amp; IFS Code&nbsp;: <b><?= h(cfg('bank_ifsc')) ?></b><br>
      SWIFT Code&nbsp;: <?= h(cfg('bank_swift')) ?>
    </td>
  </tr>

  <tr>
    <td colspan="4" class="foot sign">Customer's Seal and Signature</td>
    <td colspan="4" class="foot sign">
      <div class="for">for <?= h(cfg('company_name')) ?></div>
      <div class="auth">Authorised Signatory</div>
    </td>
  </tr>

  <tr><td colspan="8" class="cgen">This is a Computer Generated Invoice</td></tr>
</table>
</div>
<?php endfor; ?>
<?php endif; ?>

<?php if ($auto): ?><script>window.onload = () => window.print();</script><?php endif; ?>
</body>
</html>
