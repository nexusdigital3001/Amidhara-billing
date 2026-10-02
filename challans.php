<?php
require_once __DIR__ . '/lib.php';
require_login();

$cust   = (int)($_GET['customer_id'] ?? 0);
$status = $_GET['status'] ?? '';
$from   = $_GET['from'] ?? '';
$to     = $_GET['to'] ?? '';
$search = trim($_GET['q'] ?? '');

// how many match in total (across all pages), plus grand totals
$where = ' WHERE 1';
$wargs = [];
if ($cust)          { $where .= ' AND ch.customer_id = ?'; $wargs[] = $cust; }
if ($status !== '') { $where .= ' AND ch.status = ?';      $wargs[] = $status; }
if ($from !== '')   { $where .= ' AND ch.challan_date >= ?'; $wargs[] = $from; }
if ($to !== '')     { $where .= ' AND ch.challan_date <= ?'; $wargs[] = $to; }
if ($search !== '') { $where .= ' AND (ch.challan_no LIKE ? OR ch.kata_chithi_no LIKE ? OR ch.vehicle_no LIKE ?)';
                      array_push($wargs, "%$search%", "%$search%", "%$search%"); }

$agg = one("SELECT COUNT(*) AS n, COALESCE(SUM(total_qty),0) AS q, COALESCE(SUM(total_amount),0) AS a
            FROM challans ch$where", $wargs);
$totalRows = (int)$agg['n'];
$grandQty  = (float)$agg['q'];
$grandAmt  = (float)$agg['a'];

$perPage = 15;
$pages   = max(1, (int)ceil($totalRows / $perPage));
$page    = max(1, min($pages, (int)($_GET['p'] ?? 1)));
$offset  = ($page - 1) * $perPage;

$sql  = "SELECT ch.*, c.name AS cust,
         (SELECT GROUP_CONCAT(DISTINCT ci.product_name ORDER BY ci.id SEPARATOR ', ')
          FROM challan_items ci WHERE ci.challan_id = ch.id) AS items
         FROM challans ch JOIN customers c ON c.id = ch.customer_id$where
         ORDER BY ch.challan_date DESC, ch.id DESC LIMIT $perPage OFFSET $offset";
$rows = all($sql, $wargs);

$customers = all('SELECT id, name FROM customers WHERE active = 1 ORDER BY name');

// build a querystring that preserves filters when switching pages
$qs = $_GET; unset($qs['p']);
$baseQs = http_build_query($qs);

$PAGE_TITLE = 'Challans';
$PAGE_SUB   = $totalRows . ' total';
if (can_edit()) $PAGE_ACTIONS = '<a class="btn primary" href="challan_form.php">New challan</a>';
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
  <label class="f" style="min-width:130px"><span>Status</span>
    <select name="status">
      <option value="">All</option>
      <?php foreach (['open' => 'Open', 'invoiced' => 'Invoiced', 'cancelled' => 'Cancelled'] as $k => $v): ?>
        <option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="f"><span>From</span><input type="date" name="from" value="<?= h($from) ?>"></label>
  <label class="f"><span>To</span><input type="date" name="to" value="<?= h($to) ?>"></label>
  <label class="f"><span>Search</span><input type="text" name="q" value="<?= h($search) ?>" placeholder="Challan / kata / vehicle"></label>
  <button class="btn">Apply</button>
  <a class="btn ghost" href="challans.php">Reset</a>
</form>

<div class="panel">
  <?php if (!$rows): ?>
    <div class="empty">No challans match these filters.</div>
  <?php else: ?>
  <table class="data compact">
    <tr><th>Challan</th><th>Date</th><th>Customer</th><th>Item</th>
        <th class="hidem">Kata chithi</th><th class="hidem">Vehicle</th>
        <th class="num">Weight</th><th class="num">Amount</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><b><?= h($r['challan_no']) ?></b></td>
        <td><?= dmy($r['challan_date']) ?></td>
        <td><?= h($r['cust']) ?></td>
        <td><?= h($r['items'] ?? '—') ?></td>
        <td class="hidem"><?= h($r['kata_chithi_no']) ?></td>
        <td class="hidem"><?= h($r['vehicle_no']) ?></td>
        <td class="num"><?= inr($r['total_qty'], 2) ?></td>
        <td class="num big"><?= inr($r['total_amount']) ?></td>
        <td class="num" style="white-space:nowrap">
          <a class="btn sm ghost" href="print_challan.php?id=<?= $r['id'] ?>" target="_blank">PDF</a>
          <a class="btn sm" href="challan_form.php?id=<?= $r['id'] ?>"><?= can_edit() && $r['status'] === 'open' ? 'Edit' : 'View' ?></a>
        </td>
      </tr>
    <?php endforeach; ?>
    <tr style="background:var(--dust)">
      <td colspan="6"><b>Total (all <?= $totalRows ?> challans)</b></td>
      <td class="num big"><?= inr($grandQty, 2) ?></td>
      <td class="num big"><?= inr($grandAmt) ?></td>
      <td colspan="2"></td>
    </tr>
  </table>
  <?php require __DIR__ . '/pager.php'; ?>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
