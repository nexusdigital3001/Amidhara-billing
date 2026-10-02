<?php
require_once __DIR__ . '/lib.php';
require_login();

$cust = (int)($_GET['customer_id'] ?? 0);
$type = $_GET['bill_type'] ?? '';
$from = $_GET['from'] ?? '';
$to   = $_GET['to'] ?? '';
$search = trim($_GET['q'] ?? '');

$where = ' WHERE 1';
$wargs = [];
if ($cust)        { $where .= ' AND i.customer_id = ?'; $wargs[] = $cust; }
if ($type !== '') { $where .= ' AND i.bill_type = ?';   $wargs[] = $type; }
if ($from !== '') { $where .= ' AND i.invoice_date >= ?'; $wargs[] = $from; }
if ($to !== '')   { $where .= ' AND i.invoice_date <= ?'; $wargs[] = $to; }
if ($search !== ''){ $where .= ' AND i.invoice_no LIKE ?'; $wargs[] = "%$search%"; }

$agg = one("SELECT COUNT(*) AS n,
            COALESCE(SUM(CASE WHEN status='active' THEN grand_total ELSE 0 END),0) AS a
            FROM invoices i$where", $wargs);
$totalRows = (int)$agg['n'];
$grandAmt  = (float)$agg['a'];

$perPage = 15;
$pages   = max(1, (int)ceil($totalRows / $perPage));
$page    = max(1, min($pages, (int)($_GET['p'] ?? 1)));
$offset  = ($page - 1) * $perPage;

$sql  = "SELECT i.*, c.name AS cust FROM invoices i JOIN customers c ON c.id = i.customer_id$where
         ORDER BY i.invoice_date DESC, i.id DESC LIMIT $perPage OFFSET $offset";
$rows = all($sql, $wargs);

$customers = all('SELECT id, name FROM customers WHERE active = 1 ORDER BY name');

$qs = $_GET; unset($qs['p']);
$baseQs = http_build_query($qs);

$PAGE_TITLE = 'Invoices';
$PAGE_SUB   = $totalRows . ' total';
if (can_edit()) $PAGE_ACTIONS = '<a class="btn primary" href="invoice_form.php">New invoice</a>';
require __DIR__ . '/header.php';
?>
<form class="filters" method="get">
  <label class="f"><span>Customer</span>
    <select name="customer_id"><option value="">All</option>
      <?php foreach ($customers as $c): ?>
        <option value="<?= $c['id'] ?>"<?= $cust === (int)$c['id'] ? ' selected' : '' ?>><?= h($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="f" style="min-width:150px"><span>Bill type</span>
    <select name="bill_type">
      <option value="">Both series</option>
      <option value="gst"<?= $type === 'gst' ? ' selected' : '' ?>>GST</option>
      <option value="nongst"<?= $type === 'nongst' ? ' selected' : '' ?>>Without GST</option>
    </select>
  </label>
  <label class="f"><span>From</span><input type="date" name="from" value="<?= h($from) ?>"></label>
  <label class="f"><span>To</span><input type="date" name="to" value="<?= h($to) ?>"></label>
  <label class="f"><span>Invoice no.</span><input type="text" name="q" value="<?= h($search) ?>"></label>
  <button class="btn">Apply</button>
  <a class="btn ghost" href="invoices.php">Reset</a>
</form>

<div class="panel">
  <?php if (!$rows): ?>
    <div class="empty">No invoices match these filters.</div>
  <?php else: ?>
  <table class="data">
    <tr><th>Invoice no.</th><th>Date</th><th>Customer</th><th>Type</th>
        <th class="num">Weight</th><th class="num hidem">Taxable</th><th class="num hidem">Tax</th><th class="num">Total</th><th></th></tr>
    <?php foreach ($rows as $r):
      $tax = $r['cgst_amount'] + $r['sgst_amount'] + $r['igst_amount']; ?>
      <tr<?= $r['status'] === 'cancelled' ? ' style="opacity:.45"' : '' ?>>
        <td><b><?= h($r['invoice_no']) ?></b>
          <?= $r['status'] === 'cancelled' ? ' <span class="tag cancelled">Cancelled</span>' : '' ?></td>
        <td><?= dmy($r['invoice_date']) ?></td>
        <td><?= h($r['cust']) ?></td>
        <td><span class="tag <?= $r['bill_type'] === 'gst' ? 'gst' : 'nongst' ?>">
            <?= $r['bill_type'] === 'gst' ? 'GST' : 'No GST' ?></span></td>
        <td class="num"><?= inr($r['total_qty'], 2) ?></td>
        <td class="num hidem"><?= inr($r['taxable_value']) ?></td>
        <td class="num hidem"><?= inr($tax) ?></td>
        <td class="num big"><?= inr($r['grand_total']) ?></td>
        <td class="num" style="white-space:nowrap">
          <a class="btn sm ghost" href="print_invoice.php?id=<?= $r['id'] ?>" target="_blank">PDF</a>
          <a class="btn sm" href="invoice_form.php?id=<?= $r['id'] ?>">Open</a>
        </td>
      </tr>
    <?php endforeach; ?>
    <tr style="background:var(--dust)">
      <td colspan="7"><b>Total (all <?= $totalRows ?> invoices, active only)</b></td>
      <td class="num big"><?= inr($grandAmt) ?></td><td></td>
    </tr>
  </table>
  <?php require __DIR__ . '/pager.php'; ?>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
