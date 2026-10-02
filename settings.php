<?php
require_once __DIR__ . '/lib.php';
require_login();
require_admin();

$fields = [
    'Company' => [
        'company_name'       => ['Company name', 'text'],
        'company_gstin'      => ['GSTIN / UIN', 'text'],
        'company_state'      => ['State', 'text'],
        'company_state_code' => ['State code', 'text'],
        'company_address'    => ['Address (prints as typed)', 'area'],
    ],
    'Bank details' => [
        'bank_holder' => ["A/c holder's name", 'text'],
        'bank_name'   => ['Bank name', 'text'],
        'bank_ac'     => ['A/c number', 'text'],
        'bank_ifsc'   => ['Branch & IFS code', 'text'],
        'bank_swift'  => ['SWIFT code', 'text'],
    ],
    'Numbering' => [
        'gst_prefix'      => ['GST invoice prefix', 'text'],
        'gst_suffix'      => ['GST invoice suffix', 'text'],
        'nongst_prefix'   => ['Non-GST invoice prefix', 'text'],
        'nongst_suffix'   => ['Non-GST invoice suffix', 'text'],
        'challan_prefix'  => ['Challan prefix', 'text'],
        'seq_pad'         => ['Digits in the running number', 'text'],
    ],
    'Bill text' => [
        'declaration' => ['Declaration', 'area'],
    ],
];

$resetErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_all') {
    check_csrf();
    if (trim($_POST['confirm_text'] ?? '') !== 'DELETE ALL DATA') {
        $resetErr = 'Type the confirmation text exactly as shown, then try again. Nothing was deleted.';
    } else {
        db()->beginTransaction();
        try {
            db()->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ([
                'invoice_challans', 'invoice_items', 'invoices',
                'challan_items', 'challans',
                'customer_prices', 'customers', 'products', 'payments',
            ] as $t) {
                db()->exec("TRUNCATE TABLE `$t`");
            }
            db()->exec('SET FOREIGN_KEY_CHECKS = 1');
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            throw $e;
        }
        flash('All challans, invoices, payments, customers and products have been deleted. Your login and settings were kept.');
        redirect('settings.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    check_csrf();
    foreach ($fields as $group) foreach ($group as $k => $_) set_cfg($k, trim($_POST[$k] ?? ''));
    set_cfg('royalty_default', isset($_POST['royalty_default']) ? '1' : '0');
    flash('Settings saved.');
    redirect('settings.php');
}

$fy = fy_of(date('Y-m-d'));
$PAGE_TITLE = 'Settings';
require __DIR__ . '/header.php';
?>
<form method="post">
<input type="hidden" name="csrf" value="<?= csrf() ?>">

<?php foreach ($fields as $group => $set): ?>
<div class="panel">
  <h2><?= h($group) ?></h2>
  <div class="body">
    <div class="grid <?= $group === 'Numbering' ? 'g3' : 'g2' ?>">
      <?php foreach ($set as $k => [$lbl, $type]): ?>
        <label class="f"<?= $type === 'area' ? ' style="grid-column:1/-1"' : '' ?>>
          <span><?= h($lbl) ?></span>
          <?php if ($type === 'area'): ?>
            <textarea name="<?= $k ?>" rows="4"><?= h(cfg($k)) ?></textarea>
          <?php else: ?>
            <input type="text" name="<?= $k ?>" value="<?= h(cfg($k)) ?>">
          <?php endif; ?>
        </label>
      <?php endforeach; ?>
    </div>
    <?php if ($group === 'Numbering'): ?>
      <p class="hint" style="margin-top:14px">
        Next GST number: <b><?= h(format_invoice_no('gst', next_invoice_seq('gst', $fy), $fy)) ?></b> ·
        Next non-GST: <b><?= h(format_invoice_no('nongst', next_invoice_seq('nongst', $fy), $fy)) ?></b> ·
        Next challan: <b><?= h(format_challan_no(next_challan_seq($fy), $fy)) ?></b><br>
        Each series counts from 1 again on 1 April. Numbers are never reused, even after a cancellation.
      </p>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<div class="panel">
  <h2>Invoice defaults</h2>
  <div class="body">
    <label style="display:flex;align-items:center;gap:10px;font-size:18px">
      <input type="checkbox" name="royalty_default" value="1" style="width:22px;height:22px"
        <?= cfg('royalty_default','1') === '1' ? 'checked' : '' ?>>
      Tick the royalty line by default on new invoices
    </label>
  </div>
</div>

<div class="actions"><button class="btn primary">Save settings</button></div>
</form>

<div class="panel" style="border-color:#A32020">
  <h2 style="color:#A32020">Danger zone</h2>
  <div class="body">
    <?php if ($resetErr): ?><div class="err"><?= h($resetErr) ?></div><?php endif; ?>
    <p style="margin:0 0 16px">
      Deletes every challan, invoice, payment, customer and product — everything except your
      user accounts and the settings above. Use this to clear sample or test data before going
      live. <b>This cannot be undone.</b>
    </p>
    <form method="post" onsubmit="return confirm('Really delete all business data? This cannot be undone.')">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="reset_all">
      <label class="f" style="max-width:360px">
        <span>Type DELETE ALL DATA to confirm</span>
        <input type="text" name="confirm_text" autocomplete="off" placeholder="DELETE ALL DATA">
      </label>
      <div class="actions" style="margin-top:14px">
        <button class="btn danger">Reset all data</button>
      </div>
    </form>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
