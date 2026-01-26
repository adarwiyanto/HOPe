<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';

require_admin();
$me = current_user();

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
  csrf_check();
  $id = (int)($_POST['id'] ?? 0);
  if ($id > 0) {
    $stmt = db()->prepare("SELECT id, role FROM users WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if ($target && (int)$target['id'] !== (int)($me['id'] ?? 0)) {
      if (($me['role'] ?? '') === 'admin' && ($target['role'] ?? '') === 'superadmin') {
        $err = 'Admin tidak bisa menghapus superadmin.';
      } else {
        $del = db()->prepare("DELETE FROM users WHERE id=?");
        $del->execute([$id]);
        redirect(base_url('admin/users.php'));
      }
    }
  }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'delete') {
  csrf_check();
  $name = trim($_POST['name'] ?? '');
  $username = trim($_POST['username'] ?? '');
  $role = $_POST['role'] ?? 'user';
  $p1 = (string)($_POST['pass1'] ?? '');
  $p2 = (string)($_POST['pass2'] ?? '');

  try {
    if (($me['role'] ?? '') !== 'superadmin') {
      throw new Exception('Hanya superadmin yang bisa menambah user.');
    }
    if ($name === '' || $username === '') throw new Exception('Nama dan username wajib diisi.');
    if ($p1 === '' || $p1 !== $p2) throw new Exception('Password tidak cocok.');
    if (!in_array($role, ['admin','user','superadmin','pegawai'], true)) $role = 'user';

    $hash = password_hash($p1, PASSWORD_DEFAULT);
    $stmt = db()->prepare("INSERT INTO users (username,name,role,password_hash) VALUES (?,?,?,?)");
    $stmt->execute([$username,$name,$role,$hash]);
    redirect(base_url('admin/users.php'));
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$users = db()->query("SELECT id, username, name, role, created_at FROM users ORDER BY id DESC")->fetchAll();
$customCss = setting('custom_css','');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>User</title>
  <link rel="stylesheet" href="<?php echo e(base_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar">
      <button class="btn" data-toggle-sidebar type="button">Menu</button>
      <div class="badge">User</div>
    </div>

    <div class="content">
      <div class="grid cols-2">
        <div class="card">
          <h3 style="margin-top:0">Tambah User</h3>
          <?php if ($err): ?>
            <div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
          <?php endif; ?>
          <?php if (($me['role'] ?? '') === 'superadmin'): ?>
            <form method="post">
              <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
              <div class="row"><label>Nama</label><input name="name" required></div>
              <div class="row"><label>Username</label><input name="username" required></div>
              <div class="row">
                <label>Role</label>
                <select name="role">
                  <option value="admin">admin</option>
                  <option value="user">user</option>
                  <option value="superadmin">superadmin</option>
                  <option value="pegawai">pegawai</option>
                </select>
              </div>
              <div class="row"><label>Password</label><input type="password" name="pass1" required></div>
              <div class="row"><label>Ulangi Password</label><input type="password" name="pass2" required></div>
              <button class="btn" type="submit">Simpan</button>
            </form>
          <?php else: ?>
            <p><small>Hanya superadmin yang bisa menambah user.</small></p>
          <?php endif; ?>
        </div>

        <div class="card">
          <h3 style="margin-top:0">Daftar User</h3>
          <table class="table">
            <thead><tr><th>Username</th><th>Nama</th><th>Role</th><th>Dibuat</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($users as $u): ?>
                <?php
                  $roleLabels = [
                    'superadmin' => 'superadmin',
                    'admin' => 'admin',
                    'user' => 'user',
                    'pegawai' => 'pegawai',
                  ];
                  $roleValue = (string)($u['role'] ?? '');
                  $roleLabel = $roleLabels[$roleValue] ?? ($roleValue !== '' ? $roleValue : 'pegawai');
                ?>
                <tr>
                  <td><?php echo e($u['username']); ?></td>
                  <td><?php echo e($u['name']); ?></td>
                  <td><span class="badge"><?php echo e($roleLabel); ?></span></td>
                  <td><?php echo e($u['created_at']); ?></td>
                  <td>
                    <?php if (in_array($me['role'] ?? '', ['admin', 'superadmin'], true) && (int)$u['id'] !== (int)($me['id'] ?? 0)): ?>
                      <?php if (($me['role'] ?? '') !== 'admin' || ($u['role'] ?? '') !== 'superadmin'): ?>
                        <form method="post" onsubmit="return confirm('Hapus user ini?');" style="display:inline">
                          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="id" value="<?php echo e($u['id']); ?>">
                          <button class="btn" type="submit">Hapus</button>
                        </form>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

      </div>
    </div>
  </div>
</div>
<script src="<?php echo e(base_url('assets/app.js')); ?>"></script>
</body>
</html>
