<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

function ensure_roles_permissions_schema(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  try {
    db()->exec("CREATE TABLE IF NOT EXISTS roles (
      id INT AUTO_INCREMENT PRIMARY KEY,
      role_key VARCHAR(40) NOT NULL,
      role_name VARCHAR(80) NOT NULL,
      is_system TINYINT(1) NOT NULL DEFAULT 0,
      is_owner_locked TINYINT(1) NOT NULL DEFAULT 0,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_role_key (role_key)
    ) ENGINE=InnoDB");
  } catch (Throwable $e) {
  }

  try {
    db()->exec("CREATE TABLE IF NOT EXISTS role_permissions (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      role_id INT NOT NULL,
      menu_key VARCHAR(60) NOT NULL,
      can_view TINYINT(1) NOT NULL DEFAULT 0,
      can_create TINYINT(1) NOT NULL DEFAULT 0,
      can_edit TINYINT(1) NOT NULL DEFAULT 0,
      can_delete TINYINT(1) NOT NULL DEFAULT 0,
      can_print TINYINT(1) NOT NULL DEFAULT 0,
      can_export TINYINT(1) NOT NULL DEFAULT 0,
      can_approve TINYINT(1) NOT NULL DEFAULT 0,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_role_menu (role_id, menu_key),
      KEY idx_role_permissions_role (role_id)
    ) ENGINE=InnoDB");
  } catch (Throwable $e) {
  }

  try {
    $stmt = db()->query("SHOW COLUMNS FROM users LIKE 'role_id'");
    if (!$stmt->fetch()) {
      db()->exec("ALTER TABLE users ADD COLUMN role_id INT NULL AFTER role");
    }
  } catch (Throwable $e) {
  }

  seed_default_roles_and_permissions();
}

function available_menu_permissions(): array {
  return [
    'dashboard' => ['label' => 'Dashboard', 'parent' => null],
    'pos' => ['label' => 'POS', 'parent' => null],
    'admin' => ['label' => 'Admin', 'parent' => null],
    'admin.users' => ['label' => 'User', 'parent' => 'admin'],
    'admin.roles' => ['label' => 'Role & Permission', 'parent' => 'admin'],
    'products' => ['label' => 'Produk', 'parent' => null],
    'inventory' => ['label' => 'Inventori', 'parent' => null],
    'inventory.stocks' => ['label' => 'Daftar Stok', 'parent' => 'inventory'],
    'stock_opname' => ['label' => 'Stok Opname', 'parent' => 'inventory'],
    'sales' => ['label' => 'Penjualan', 'parent' => null],
  ];
}

function permission_actions(): array {
  return ['view', 'create', 'edit', 'delete', 'print', 'export', 'approve'];
}

function seed_default_roles_and_permissions(): void {
  $defaults = [
    ['owner', 'Owner', 1, 1],
    ['admin', 'Admin', 1, 0],
    ['manager', 'Manager', 1, 0],
    ['kasir', 'Kasir', 1, 0],
    ['gudang', 'Gudang', 1, 0],
  ];

  foreach ($defaults as $r) {
    $stmt = db()->prepare("INSERT INTO roles (role_key, role_name, is_system, is_owner_locked, is_active)
      VALUES (?,?,?,?,1)
      ON DUPLICATE KEY UPDATE role_name=VALUES(role_name), is_system=VALUES(is_system), is_owner_locked=VALUES(is_owner_locked), is_active=1");
    $stmt->execute([$r[0], $r[1], $r[2], $r[3]]);
  }

  try {
    db()->exec("UPDATE users SET role='kasir' WHERE role='pegawai'");
    db()->exec("UPDATE users SET role='kasir' WHERE role='user'");
    db()->exec("UPDATE users SET role='owner' WHERE role='superadmin'");
  } catch (Throwable $e) {
  }

  $roles = roles_all(true);
  $roleByKey = [];
  foreach ($roles as $role) {
    $roleByKey[$role['role_key']] = (int)$role['id'];
  }

  if (!empty($roleByKey)) {
    $stmtUsers = db()->query("SELECT id, role, role_id FROM users");
    $rows = $stmtUsers->fetchAll();
    $update = db()->prepare("UPDATE users SET role=?, role_id=? WHERE id=?");
    foreach ($rows as $u) {
      $role = strtolower(trim((string)($u['role'] ?? '')));
      if ($role === 'superadmin') $role = 'owner';
      if ($role === 'pegawai' || $role === 'user' || $role === '') $role = 'kasir';
      if (!isset($roleByKey[$role])) $role = 'kasir';
      if ((int)($u['role_id'] ?? 0) !== (int)$roleByKey[$role] || (string)$u['role'] !== $role) {
        $update->execute([$role, (int)$roleByKey[$role], (int)$u['id']]);
      }
    }
  }

  seed_default_permissions_by_role();
}

function seed_default_permissions_by_role(): void {
  $menus = array_keys(available_menu_permissions());
  $roles = roles_all(true);
  $upsert = db()->prepare("INSERT INTO role_permissions
    (role_id, menu_key, can_view, can_create, can_edit, can_delete, can_print, can_export, can_approve)
    VALUES (?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
      can_view=VALUES(can_view), can_create=VALUES(can_create), can_edit=VALUES(can_edit),
      can_delete=VALUES(can_delete), can_print=VALUES(can_print), can_export=VALUES(can_export), can_approve=VALUES(can_approve)");

  foreach ($roles as $role) {
    $key = (string)$role['role_key'];
    foreach ($menus as $menu) {
      $perm = [0,0,0,0,0,0,0];
      if ($key === 'owner') {
        $perm = [1,1,1,1,1,1,1];
      } elseif ($key === 'admin') {
        $perm[0] = in_array($menu, ['dashboard','pos','products','inventory','inventory.stocks','stock_opname','sales','admin','admin.users'], true) ? 1 : 0;
        $perm[1] = $perm[0]; $perm[2] = $perm[0];
        $perm[3] = in_array($menu, ['sales'], true) ? 0 : $perm[0];
      } elseif ($key === 'manager') {
        $perm[0] = in_array($menu, ['dashboard','stock_opname'], true) ? 1 : 0;
        $perm[1] = $menu === 'stock_opname' ? 1 : 0;
        $perm[2] = $menu === 'stock_opname' ? 1 : 0;
        $perm[6] = $menu === 'stock_opname' ? 1 : 0;
      } elseif ($key === 'kasir') {
        $perm[0] = in_array($menu, ['dashboard','pos','sales'], true) ? 1 : 0;
        $perm[1] = $menu === 'pos' ? 1 : 0;
      } elseif ($key === 'gudang') {
        $perm[0] = in_array($menu, ['dashboard','inventory','inventory.stocks','stock_opname'], true) ? 1 : 0;
        $perm[1] = $menu === 'stock_opname' ? 1 : 0;
        $perm[2] = $menu === 'stock_opname' ? 1 : 0;
      }
      $upsert->execute([(int)$role['id'], $menu, $perm[0],$perm[1],$perm[2],$perm[3],$perm[4],$perm[5],$perm[6]]);
    }
  }
}

function roles_all(bool $onlyActive = false): array {
  ensure_roles_permissions_schema();
  $sql = "SELECT * FROM roles" . ($onlyActive ? " WHERE is_active=1" : "") . " ORDER BY id ASC";
  return db()->query($sql)->fetchAll();
}

function role_by_id(int $id): ?array {
  $stmt = db()->prepare("SELECT * FROM roles WHERE id=? LIMIT 1");
  $stmt->execute([$id]);
  $row = $stmt->fetch();
  return $row ?: null;
}

function role_by_key(string $roleKey): ?array {
  $stmt = db()->prepare("SELECT * FROM roles WHERE role_key=? LIMIT 1");
  $stmt->execute([$roleKey]);
  $row = $stmt->fetch();
  return $row ?: null;
}

function normalize_role_key(string $role): string {
  $role = strtolower(trim($role));
  if ($role === 'superadmin') return 'owner';
  if ($role === 'pegawai' || $role === 'user' || $role === '') return 'kasir';
  return $role;
}

function current_user_role_key(): string {
  $u = current_user() ?? [];
  $role = normalize_role_key((string)($u['role_key'] ?? $u['role'] ?? ''));
  return $role !== '' ? $role : 'kasir';
}

function current_user_is_owner(): bool {
  return current_user_role_key() === 'owner';
}

function current_user_role_id(): int {
  $u = current_user() ?? [];
  $rid = (int)($u['role_id'] ?? 0);
  if ($rid > 0) return $rid;
  $role = role_by_key(current_user_role_key());
  return (int)($role['id'] ?? 0);
}

function role_permissions_map(int $roleId): array {
  $stmt = db()->prepare("SELECT * FROM role_permissions WHERE role_id=?");
  $stmt->execute([$roleId]);
  $out = [];
  foreach ($stmt->fetchAll() as $r) {
    $out[(string)$r['menu_key']] = $r;
  }
  return $out;
}

function has_role_permission(int $roleId, string $menuKey, string $action = 'view'): bool {
  $role = role_by_id($roleId);
  if (($role['role_key'] ?? '') === 'owner') return true;
  $allowedActions = permission_actions();
  if (!in_array($action, $allowedActions, true)) $action = 'view';
  $column = 'can_' . $action;
  $stmt = db()->prepare("SELECT {$column} AS allowed FROM role_permissions WHERE role_id=? AND menu_key=? LIMIT 1");
  $stmt->execute([$roleId, $menuKey]);
  $row = $stmt->fetch();
  return (int)($row['allowed'] ?? 0) === 1;
}

function has_menu_access(int $userId, string $menuKey, string $action = 'view'): bool {
  $stmt = db()->prepare("SELECT u.id, u.role_id, u.role, r.role_key
    FROM users u
    LEFT JOIN roles r ON r.id=u.role_id
    WHERE u.id=? LIMIT 1");
  $stmt->execute([$userId]);
  $u = $stmt->fetch();
  if (!$u) return false;
  $roleKey = normalize_role_key((string)($u['role_key'] ?? $u['role'] ?? ''));
  if ($roleKey === 'owner') return true;
  $roleId = (int)($u['role_id'] ?? 0);
  if ($roleId <= 0) {
    $role = role_by_key($roleKey);
    $roleId = (int)($role['id'] ?? 0);
  }
  if ($roleId <= 0) return false;
  return has_role_permission($roleId, $menuKey, $action);
}

function require_menu_access(string $menuKey, string $action = 'view'): void {
  require_login();
  ensure_roles_permissions_schema();
  $u = current_user();
  if (!$u) {
    http_response_code(403);
    exit('Forbidden');
  }
  if (has_menu_access((int)$u['id'], $menuKey, $action)) {
    return;
  }
  http_response_code(403);
  exit('Forbidden');
}
