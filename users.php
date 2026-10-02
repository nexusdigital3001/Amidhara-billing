<?php
require_once __DIR__ . '/lib.php';
require_login();
require_admin();

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'add') {
        $u = trim($_POST['username'] ?? '');
        $n = trim($_POST['full_name'] ?? '');
        $p = $_POST['password'] ?? '';
        $r = in_array($_POST['role'] ?? '', ['admin','biller','viewer'], true) ? $_POST['role'] : 'biller';
        if ($u === '' || $n === '' || strlen($p) < 6) {
            $err = 'Username, name, and a password of at least 6 characters are all required.';
        } elseif (one('SELECT id FROM users WHERE username = ?', [$u])) {
            $err = 'That username is taken.';
        } else {
            q('INSERT INTO users (username,password_hash,full_name,role) VALUES (?,?,?,?)',
              [$u, password_hash($p, PASSWORD_DEFAULT), $n, $r]);
            flash('User ' . $u . ' added.');
            redirect('users.php');
        }
    }

    if ($act === 'update') {
        $uid = (int)$_POST['id'];
        $r   = in_array($_POST['role'] ?? '', ['admin','biller','viewer'], true) ? $_POST['role'] : 'biller';
        $on  = isset($_POST['active']) ? 1 : 0;
        if ($uid === (int)current_user()['id'] && ($r !== 'admin' || !$on)) {
            $err = "You can't remove your own admin access.";
        } else {
            q('UPDATE users SET role = ?, active = ? WHERE id = ?', [$r, $on, $uid]);
            if (($_POST['password'] ?? '') !== '') {
                if (strlen($_POST['password']) < 6) $err = 'Password must be at least 6 characters.';
                else q('UPDATE users SET password_hash = ? WHERE id = ?',
                       [password_hash($_POST['password'], PASSWORD_DEFAULT), $uid]);
            }
            if (!$err) { flash('User updated.'); redirect('users.php'); }
        }
    }
}

$rows = all('SELECT * FROM users ORDER BY active DESC, username');
$PAGE_TITLE = 'Users';
require __DIR__ . '/header.php';
?>
<?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>

<form method="post">
<input type="hidden" name="csrf" value="<?= csrf() ?>">
<input type="hidden" name="action" value="add">
<div class="panel">
  <h2>Add a user</h2>
  <div class="body">
    <div class="grid g4">
      <label class="f"><span>Username</span><input type="text" name="username" required></label>
      <label class="f"><span>Full name</span><input type="text" name="full_name" required></label>
      <label class="f"><span>Password</span><input type="password" name="password" required minlength="6"></label>
      <label class="f"><span>Role</span>
        <select name="role">
          <option value="biller">Biller — challans, invoices, payments</option>
          <option value="viewer">Viewer — read and print only</option>
          <option value="admin">Admin — everything</option>
        </select></label>
    </div>
    <div class="actions" style="margin-top:18px"><button class="btn primary">Add user</button></div>
  </div>
</div>
</form>

<div class="panel">
  <h2>Existing users</h2>
  <table class="data">
    <tr><th>Username</th><th>Name</th><th style="width:280px">Role</th>
        <th style="width:200px">New password</th><th style="width:110px">Active</th><th></th></tr>
    <?php foreach ($rows as $u): ?>
    <tr>
      <form method="post">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" value="<?= $u['id'] ?>">
      <td><b><?= h($u['username']) ?></b></td>
      <td><?= h($u['full_name']) ?></td>
      <td><select name="role">
        <?php foreach (['admin'=>'Admin','biller'=>'Biller','viewer'=>'Viewer'] as $k=>$v): ?>
          <option value="<?= $k ?>"<?= $u['role'] === $k ? ' selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select></td>
      <td><input type="password" name="password" placeholder="leave blank to keep"></td>
      <td class="c"><input type="checkbox" name="active" value="1" style="width:22px;height:22px"
            <?= $u['active'] ? 'checked' : '' ?>></td>
      <td class="num"><button class="btn sm">Save</button></td>
      </form>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<p class="hint">
  Biller can create and edit challans, invoices and payments. Viewer can look and print but change nothing.
  Only an admin can delete a payment or reach this page.
</p>
<?php require __DIR__ . '/footer.php'; ?>
