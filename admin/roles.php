<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/permissions.php';

start_secure_session();
require_admin();
ensure_roles_permissions_schema();
if (!current_user_is_owner()) {
  http_response_code(403);
  exit('Forbidden');
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');
  try {
    if ($action === 'save') {
      $id = (int)($_POST['id'] ?? 0);
      $roleName = trim((string)($_POST['role_name'] ?? ''));
      $roleKey = strtolower(trim((string)($_POST['role_key'] ?? '')));
      $roleKey = preg_replace('/[^a-z0-9_\.\-]/', '_', $roleKey);
      if ($roleName === '' || $roleKey === '') throw new Exception('Nama dan key role wajib diisi.');
      if ($roleKey === 'owner' && $id <= 0) throw new Exception('Role owner sudah permanen.');
      if ($id > 0) {
        $role = role_by_id($id);
        if (!$role) throw new Exception('Role tidak ditemukan.');
        if ((int)$role['is_owner_locked'] === 1) throw new Exception('Role owner tidak bisa diubah.');
        $stmt = db()->prepare("UPDATE roles SET role_key=?, role_name=?, is_active=1 WHERE id=?");
        $stmt->execute([$roleKey, $roleName, $id]);
      } else {
        $stmt = db()->prepare("INSERT INTO roles (role_key, role_name, is_system, is_owner_locked, is_active) VALUES (?,?,?,?,1)");
        $stmt->execute([$roleKey, $roleName, 0, 0]);
      }
      seed_default_roles_and_permissions();
      redirect(base_url('admin/roles.php'));
    }

    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      $role = role_by_id($id);
      if (!$role) throw new Exception('Role tidak ditemukan.');
      if ((int)$role['is_owner_locked'] === 1 || ($role['role_key'] ?? '') === 'owner') throw new Exception('Role owner tidak bisa dihapus.');
      $stmtCount = db()->prepare("SELECT COUNT(*) c FROM users WHERE role_id=?");
      $stmtCount->execute([$id]);
      $count = (int)($stmtCount->fetch()['c'] ?? 0);
      if ($count > 0) throw new Exception('Role masih dipakai user. Ubah user ke role lain dulu.');
      $stmt = db()->prepare("DELETE FROM role_permissions WHERE role_id=?");
      $stmt->execute([$id]);
      $stmt = db()->prepare("DELETE FROM roles WHERE id=?");
      $stmt->execute([$id]);
      redirect(base_url('admin/roles.php'));
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$roles = db()->query("SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id=r.id) AS user_count FROM roles r ORDER BY r.id ASC")->fetchAll();
$editId = (int)($_GET['edit_id'] ?? 0);
$editRole = $editId > 0 ? role_by_id($editId) : null;
$customCss = setting('custom_css','');
?>
<!doctype html><html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Role Management</title>
<link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?></style>
</head><body><div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><div class="badge">Role Management</div></div>
<div class="content"><div class="grid cols-2">
<div class="card"><h3 style="margin-top:0"><?php echo $editRole ? 'Edit Role' : 'Tambah Role'; ?></h3>
<?php if ($err): ?><div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo e((string)($editRole['id'] ?? 0)); ?>">
<div class="row"><label>Role Key</label><input name="role_key" required value="<?php echo e((string)($editRole['role_key'] ?? '')); ?>"></div>
<div class="row"><label>Role Name</label><input name="role_name" required value="<?php echo e((string)($editRole['role_name'] ?? '')); ?>"></div>
<button class="btn" type="submit">Simpan</button></form></div>
<div class="card"><h3 style="margin-top:0">Daftar Role</h3><table class="table"><thead><tr><th>Key</th><th>Nama</th><th>User</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($roles as $role): ?><tr><td><?php echo e($role['role_key']); ?></td><td><?php echo e($role['role_name']); ?></td><td><?php echo e((string)$role['user_count']); ?></td><td><?php echo ((int)$role['is_owner_locked']===1) ? '<span class="badge">Locked</span>' : '<span class="badge">Editable</span>'; ?></td><td>
<a class="btn" href="<?php echo e(base_url('admin/roles.php?edit_id=' . (int)$role['id'])); ?>">Edit</a>
<a class="btn" href="<?php echo e(base_url('admin/permissions.php?role_id=' . (int)$role['id'])); ?>">Permission</a>
<?php if ((int)$role['is_owner_locked'] !== 1): ?><form style="display:inline" method="post" data-confirm="Hapus role ini?"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo e((string)$role['id']); ?>"><button class="btn" type="submit">Hapus</button></form><?php endif; ?>
</td></tr><?php endforeach; ?>
</tbody></table></div></div></div></div></div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
