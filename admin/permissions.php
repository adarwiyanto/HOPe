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
$roles = roles_all(true);
$roleId = (int)($_GET['role_id'] ?? 0);
if ($roleId <= 0 && !empty($roles)) $roleId = (int)$roles[0]['id'];
$role = role_by_id($roleId);
if (!$role) {
  http_response_code(404);
  exit('Role tidak ditemukan.');
}
$menus = available_menu_permissions();
$actions = permission_actions();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  try {
    $postRoleId = (int)($_POST['role_id'] ?? 0);
    $roleToSave = role_by_id($postRoleId);
    if (!$roleToSave) throw new Exception('Role tidak ditemukan.');
    if (($roleToSave['role_key'] ?? '') === 'owner' || (int)$roleToSave['is_owner_locked'] === 1) {
      throw new Exception('Permission owner dikunci permanen.');
    }
    $upsert = db()->prepare("INSERT INTO role_permissions
      (role_id, menu_key, can_view, can_create, can_edit, can_delete, can_print, can_export, can_approve)
      VALUES (?,?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE
      can_view=VALUES(can_view), can_create=VALUES(can_create), can_edit=VALUES(can_edit),
      can_delete=VALUES(can_delete), can_print=VALUES(can_print), can_export=VALUES(can_export), can_approve=VALUES(can_approve)");
    foreach ($menus as $menuKey => $meta) {
      $vals = [];
      foreach ($actions as $a) {
        $vals[$a] = !empty($_POST['perm'][$menuKey][$a]) ? 1 : 0;
      }
      $upsert->execute([$postRoleId, $menuKey, $vals['view'], $vals['create'], $vals['edit'], $vals['delete'], $vals['print'], $vals['export'], $vals['approve']]);
    }
    redirect(base_url('admin/permissions.php?role_id=' . $postRoleId));
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$permMap = role_permissions_map((int)$role['id']);
$customCss = setting('custom_css','');
?>
<!doctype html><html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Permission Management</title>
<link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?></style>
</head><body><div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><div class="badge">Permission Management</div></div>
<div class="content"><div class="card"><h3 style="margin-top:0">Role Permission</h3>
<?php if ($err): ?><div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div><?php endif; ?>
<form method="get" style="margin-bottom:12px"><label>Pilih Role</label> <select name="role_id" onchange="this.form.submit()">
<?php foreach ($roles as $r): ?><option value="<?php echo e((string)$r['id']); ?>" <?php echo ((int)$r['id']===(int)$role['id'])?'selected':''; ?>><?php echo e($r['role_name']); ?> (<?php echo e($r['role_key']); ?>)</option><?php endforeach; ?>
</select></form>
<form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="role_id" value="<?php echo e((string)$role['id']); ?>">
<table class="table"><thead><tr><th>Menu Tree</th><?php foreach ($actions as $a): ?><th><?php echo e(ucfirst($a)); ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($menus as $menuKey => $meta): ?>
  <?php $p = $permMap[$menuKey] ?? []; $locked = (($role['role_key'] ?? '') === 'owner' || (int)$role['is_owner_locked']===1); ?>
  <tr>
    <td><?php echo e(($meta['parent'] ? '↳ ' : '') . $meta['label']); ?><br><small><?php echo e($menuKey); ?></small></td>
    <?php foreach ($actions as $a): $checked = (int)($p['can_' . $a] ?? (($locked) ? 1 : 0)) === 1; ?>
      <td><input type="checkbox" name="perm[<?php echo e($menuKey); ?>][<?php echo e($a); ?>]" value="1" <?php echo $checked ? 'checked' : ''; ?> <?php echo $locked ? 'disabled' : ''; ?>></td>
    <?php endforeach; ?>
  </tr>
<?php endforeach; ?>
</tbody></table>
<?php if (($role['role_key'] ?? '') !== 'owner' && (int)$role['is_owner_locked'] !== 1): ?><button class="btn" type="submit">Simpan Permission</button><?php else: ?><p><small>Permission owner full access dan dikunci permanen.</small></p><?php endif; ?>
</form></div></div></div></div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
