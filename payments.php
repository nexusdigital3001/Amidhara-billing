<?php
require_once __DIR__ . '/lib.php';
require_login();

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    require_edit();

    if (($_POST['action'] ?? '') === 'delete') {
        require_admin();
        q('DELETE FROM payments WHERE id = ?', [(int)$_POST['id']]);
        flash('Payment deleted.');
        redirect('payments.php');
    }

    $d = [
        'customer_id' => (int)($_POST['customer_id'] ?? 0),
        'pay_date'    => $_POST['pay_date'] ?: date('Y-m-d'),
        'mode'        => ($_POST['mode'] ?? 'bank') === 'cash' ? 'cash' : 'bank',
        'amount'      => round((float)($_POST['amount'] ?? 0), 2),
        'ref_no'      => trim($_POST['ref_no'] ?? ''),
        'note'        => trim($_POST['note'] ?? ''),
    ];
    if (!$d['customer_id'])   $err = 'Pick a customer.';
    elseif ($d['amount'] <= 0) $err = 'Amount must be more than zero.';
    else {
        q('INSERT INTO payments (customer_id,pay_date,mode,amount,ref_no,note,created_by) VALUES (?,?,?,?,?,?,?)',
          [...array_values($d), current_user()['id']]);
        flash('Payment recorded.');
        redirect('payments.php?customer_id=' . $d['customer_id']);
    }
}

$cust = (int)($_GET['customer_id'] ?? 0);
$from = $_GET['from'] ?? '';
$to   = $_GET['to'] ?? '';
$mode = $_GET['mode'] ?? '';

$sql  = 'SELECT p.*, c.name AS cust FROM payments p JOIN customers c ON c.id = p.customer_id WHERE 1';
$args = [];
if ($cust)        { $sql .= ' AND p.customer_id = ?'; $args[] = $cust; }
if ($from !== '') { $sql .= ' AND p.pay_date >= ?';   $args[] = $from; }
if ($to !== '')   { $sql .= ' AND p.pay_date <= ?';   $args[] = $to; }
if ($mode !== '') { $sql .= ' AND p.mode = ?';        $args[] = $mode; }
$sql .= ' ORDER BY p.pay_date DESC, p.id DESC LIMIT 300';
$rows = all($sql, $args);
$sum  = array_sum(array_column($rows, 'amount'));

$customers = all('SELECT id, name FROM customers WHERE active = 1 ORDER BY name');

$PAGE_TITLE = 'Payments received';
$PAGE_SUB   = count($rows) . ' shown';
require __DIR__ . '/header.php';
?>
<?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>

<?php if (can_edit()): ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= csrf() ?>">
<div class="panel">
  <h2>Record a payment</h2>
  <div class="body">
    <div class="grid g4">
      <label class="f"><span>Date</span>
        <input type="date" name="pay_date" required value="<?= h(date('Y-m-d')) ?>"></label>
      <label class="f"><span>Customer</span>
        <select name="customer_id" required>
          <option value="">— select —</option>
          <?php foreach ($customers as $c): ?>
            <option value="<?= $c['id'] ?>"<?= $cust === (int)$c['id'] ? ' selected' : '' ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select></label>
      <label class="f"><span>Mode</span>
        <select name="mode"><option value="bank">Bank</option><option value="cash">Cash</option></select></label>
      <label class="f"><span>Amount</span>
        <input type="number" step="0.01" min="0.01" name="amount" required placeholder="0.00"></label>
    </div>
    <div class="grid g2" style="margin-top:16px">
      <label class="f"><span>Reference (cheque / UTR)</span><input type="text" name="ref_no"></label>
      <label class="f"><span>Note</span><input type="text" name="note"></label>
    </div>
    <div class="actions" style="margin-top:18px"><button class="btn primary">Record payment</button></div>
  </div>
</div>
</form>
<?php endif; ?>

<form class="filters" method="get">
  <label class="f"><span>Customer</span>
    <select name="customer_id"><option value="">All</option>
      <?php foreach ($customers as $c): ?>
        <option value="<?= $c['id'] ?>"<?= $cust === (int)$c['id'] ? ' selected' : '' ?>><?= h($c['name']) ?></option>
      <?php endforeach; ?>
    </select></label>
  <label class="f" style="min-width:130px"><span>Mode</span>
    <select name="mode"><option value="">Both</option>
      <option value="bank"<?= $mode === 'bank' ? ' selected' : '' ?>>Bank</option>
      <option value="cash"<?= $mode === 'cash' ? ' selected' : '' ?>>Cash</option>
    </select></label>
  <label class="f"><span>From</span><input type="date" name="from" value="<?= h($from) ?>"></label>
  <label class="f"><span>To</span><input type="date" name="to" value="<?= h($to) ?>"></label>
  <button class="btn">Apply</button>
  <a class="btn ghost" href="payments.php">Reset</a>
</form>

<div class="panel">
  <?php if (!$rows): ?>
    <div class="empty">No payments match these filters.</div>
  <?php else: ?>
  <table class="data">
    <tr><th>Date</th><th>Customer</th><th>Mode</th><th>Reference</th><th>Note</th><th class="num">Amount</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= dmy($r['pay_date']) ?></td>
        <td><?= h($r['cust']) ?></td>
        <td><span class="tag <?= h($r['mode']) ?>"><?= h($r['mode']) ?></span></td>
        <td><?= h($r['ref_no']) ?></td>
        <td><?= h($r['note']) ?></td>
        <td class="num big"><?= inr($r['amount']) ?></td>
        <td class="num">
          <?php if (is_admin()): ?>
          <form method="post" onsubmit="return confirm('Delete this payment?')" style="display:inline">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $r['id'] ?>">
            <button class="btn sm danger">Delete</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <tr style="background:var(--dust)">
      <td colspan="5"><b>Total shown</b></td><td class="num big"><?= inr($sum) ?></td><td></td>
    </tr>
  </table>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
