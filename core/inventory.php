<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

function ensure_inventory_module_schema(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  ensure_branches_table();
  ensure_products_inventory_columns();
  ensure_suppliers_table();
  ensure_purchase_tables();
  ensure_purchase_revision_audit_table();
  ensure_bom_tables();
  ensure_production_tables();
  ensure_stock_ledger_table();
  ensure_sales_inventory_columns();
  ensure_sales_production_links_table();
  ensure_inventory_settings_defaults();
}

function ensure_branches_table(): void {
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS branches (
      id INT AUTO_INCREMENT PRIMARY KEY,
      branch_code VARCHAR(40) NOT NULL,
      branch_name VARCHAR(120) NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_branch_code (branch_code)
    ) ENGINE=InnoDB");
    db()->exec("INSERT INTO branches (branch_code, branch_name, is_active)
      SELECT 'MAIN','Cabang Utama',1 FROM DUAL
      WHERE NOT EXISTS (SELECT 1 FROM branches LIMIT 1)");
  } catch (Throwable $e) {
    // no-op
  }
}

function ensure_products_inventory_columns(): void {
  $defs = [
    "ALTER TABLE products ADD COLUMN product_type ENUM('finished_good','raw_material','service') NOT NULL DEFAULT 'finished_good' AFTER price",
    "ALTER TABLE products ADD COLUMN track_stock TINYINT(1) NOT NULL DEFAULT 1 AFTER product_type",
    "ALTER TABLE products ADD COLUMN allow_direct_purchase TINYINT(1) NOT NULL DEFAULT 0 AFTER track_stock",
    "ALTER TABLE products ADD COLUMN allow_bom TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_direct_purchase",
    "ALTER TABLE products ADD KEY idx_product_type (product_type)",
  ];
  foreach ($defs as $sql) {
    try { db()->exec($sql); } catch (Throwable $e) { /* no-op */ }
  }
}

function ensure_suppliers_table(): void {
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS suppliers (
      id INT AUTO_INCREMENT PRIMARY KEY,
      supplier_code VARCHAR(40) NOT NULL,
      supplier_name VARCHAR(160) NOT NULL,
      phone VARCHAR(40) NULL,
      email VARCHAR(190) NULL,
      address TEXT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_supplier_code (supplier_code)
    ) ENGINE=InnoDB");
  } catch (Throwable $e) {
  }
}

function ensure_purchase_tables(): void {
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS purchase_headers (
      id INT AUTO_INCREMENT PRIMARY KEY,
      branch_id INT NOT NULL,
      supplier_id INT NOT NULL,
      purchase_no VARCHAR(50) NOT NULL,
      purchase_date DATE NOT NULL,
      status ENUM('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
      subtotal DECIMAL(18,2) NOT NULL DEFAULT 0,
      discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
      tax_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
      grand_total DECIMAL(18,2) NOT NULL DEFAULT 0,
      notes TEXT NULL,
      created_by INT NULL,
      posted_by INT NULL,
      posted_at TIMESTAMP NULL DEFAULT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_purchase_no (purchase_no),
      KEY idx_purchase_branch_status_date (branch_id,status,purchase_date),
      KEY idx_purchase_supplier (supplier_id)
    ) ENGINE=InnoDB");

    db()->exec("CREATE TABLE IF NOT EXISTS purchase_items (
      id INT AUTO_INCREMENT PRIMARY KEY,
      purchase_id INT NOT NULL,
      product_id INT NOT NULL,
      qty DECIMAL(18,4) NOT NULL,
      unit_cost DECIMAL(18,2) NOT NULL,
      line_total DECIMAL(18,2) NOT NULL,
      notes VARCHAR(255) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_purchase_items_purchase (purchase_id),
      KEY idx_purchase_items_product (product_id),
      CONSTRAINT fk_purchase_items_purchase FOREIGN KEY (purchase_id) REFERENCES purchase_headers(id) ON DELETE CASCADE,
      CONSTRAINT fk_purchase_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB");
  } catch (Throwable $e) {
  }
}

function ensure_purchase_revision_audit_table(): void {
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS purchase_revision_audit (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      purchase_id INT NOT NULL,
      purchase_no VARCHAR(50) NOT NULL,
      edited_by INT NULL,
      edited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      edit_reason TEXT NULL,
      snapshot_before LONGTEXT NULL,
      snapshot_after LONGTEXT NULL,
      change_summary LONGTEXT NULL,
      KEY idx_purchase_revision_purchase (purchase_id, edited_at),
      KEY idx_purchase_revision_no (purchase_no)
    ) ENGINE=InnoDB");
  } catch (Throwable $e) {
  }
}

function ensure_bom_tables(): void {
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS bom_headers (
      id INT AUTO_INCREMENT PRIMARY KEY,
      branch_id INT NULL,
      finished_product_id INT NOT NULL,
      bom_code VARCHAR(50) NOT NULL,
      bom_name VARCHAR(160) NOT NULL,
      yield_qty DECIMAL(18,4) NOT NULL DEFAULT 1,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      notes TEXT NULL,
      created_by INT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_bom_code (bom_code),
      KEY idx_bom_finished_active (finished_product_id,is_active)
    ) ENGINE=InnoDB");

    db()->exec("CREATE TABLE IF NOT EXISTS bom_items (
      id INT AUTO_INCREMENT PRIMARY KEY,
      bom_id INT NOT NULL,
      material_product_id INT NOT NULL,
      qty_per_yield DECIMAL(18,4) NOT NULL,
      unit_note VARCHAR(120) NULL,
      wastage_pct DECIMAL(8,4) NOT NULL DEFAULT 0,
      sort_order INT NOT NULL DEFAULT 0,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_bom_material (bom_id, material_product_id),
      KEY idx_bom_items_lookup (bom_id,material_product_id),
      CONSTRAINT fk_bom_items_header FOREIGN KEY (bom_id) REFERENCES bom_headers(id) ON DELETE CASCADE,
      CONSTRAINT fk_bom_items_material FOREIGN KEY (material_product_id) REFERENCES products(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB");
  } catch (Throwable $e) {
  }
}

function ensure_production_tables(): void {
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS production_headers (
      id INT AUTO_INCREMENT PRIMARY KEY,
      production_no VARCHAR(50) NOT NULL,
      branch_id INT NOT NULL,
      bom_id INT NOT NULL,
      finished_product_id INT NOT NULL,
      production_date DATE NOT NULL,
      qty_to_produce DECIMAL(18,4) NOT NULL,
      status ENUM('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
      mode_source ENUM('manual_menu','pos_auto') NOT NULL DEFAULT 'manual_menu',
      notes TEXT NULL,
      created_by INT NULL,
      posted_by INT NULL,
      posted_at TIMESTAMP NULL DEFAULT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_production_no (production_no),
      KEY idx_production_branch_status_date (branch_id,status,production_date)
    ) ENGINE=InnoDB");

    db()->exec("CREATE TABLE IF NOT EXISTS production_items (
      id INT AUTO_INCREMENT PRIMARY KEY,
      production_id INT NOT NULL,
      material_product_id INT NOT NULL,
      required_qty DECIMAL(18,4) NOT NULL,
      actual_qty DECIMAL(18,4) NOT NULL,
      unit_cost DECIMAL(18,2) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_production_items_prod (production_id),
      KEY idx_production_items_material (material_product_id),
      CONSTRAINT fk_production_items_header FOREIGN KEY (production_id) REFERENCES production_headers(id) ON DELETE CASCADE,
      CONSTRAINT fk_production_items_material FOREIGN KEY (material_product_id) REFERENCES products(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB");
  } catch (Throwable $e) {
  }
}

function ensure_stock_ledger_table(): void {
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS stock_ledger (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      branch_id INT NOT NULL,
      product_id INT NOT NULL,
      trans_type VARCHAR(60) NOT NULL,
      ref_table VARCHAR(60) NOT NULL,
      ref_id BIGINT NOT NULL,
      qty_in DECIMAL(18,4) NOT NULL DEFAULT 0,
      qty_out DECIMAL(18,4) NOT NULL DEFAULT 0,
      unit_cost DECIMAL(18,2) NULL,
      note VARCHAR(255) NULL,
      created_by INT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      KEY idx_stock_ledger_main (branch_id,product_id,created_at),
      KEY idx_stock_ledger_ref (ref_table,ref_id)
    ) ENGINE=InnoDB");
  } catch (Throwable $e) {
  }
}

function ensure_sales_inventory_columns(): void {
  $defs = [
    "ALTER TABLE sales ADD COLUMN branch_id INT NULL AFTER product_id",
  ];
  foreach ($defs as $sql) {
    try { db()->exec($sql); } catch (Throwable $e) { }
  }
}

function ensure_sales_production_links_table(): void {
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS sales_production_links (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      transaction_code VARCHAR(40) NOT NULL,
      production_id INT NOT NULL,
      branch_id INT NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      KEY idx_sales_prod_tx (transaction_code),
      KEY idx_sales_prod_prod (production_id)
    ) ENGINE=InnoDB");
  } catch (Throwable $e) {
  }
}

function ensure_inventory_settings_defaults(): void {
  $defaults = [
    'production_mode' => 'auto',
    'pos_autoproduction_enabled' => '1',
    'pos_autoproduction_allow_negative' => '0',
    'bom_require_exact_material_stock' => '1',
    'purchase_raw_material_only' => '1',
    'active_branch_id' => '1',
  ];
  foreach ($defaults as $k => $v) {
    set_setting($k, setting($k, $v));
  }
}

function inventory_branches(): array {
  $stmt = db()->query("SELECT id, branch_code, branch_name, is_active FROM branches WHERE is_active=1 ORDER BY branch_name ASC");
  return $stmt->fetchAll();
}

function active_branch_id(): int {
  $id = (int)setting('active_branch_id', '1');
  if ($id > 0) return $id;
  return 1;
}

function branch_stock(int $branchId, int $productId): float {
  $stmt = db()->prepare("SELECT COALESCE(SUM(qty_in - qty_out),0) AS qty FROM stock_ledger WHERE branch_id=? AND product_id=?");
  $stmt->execute([$branchId, $productId]);
  $row = $stmt->fetch();
  return (float)($row['qty'] ?? 0);
}

function add_stock_ledger(array $row): void {
  $stmt = db()->prepare("INSERT INTO stock_ledger (branch_id,product_id,trans_type,ref_table,ref_id,qty_in,qty_out,unit_cost,note,created_by)
    VALUES (?,?,?,?,?,?,?,?,?,?)");
  $stmt->execute([
    (int)$row['branch_id'],
    (int)$row['product_id'],
    (string)$row['trans_type'],
    (string)$row['ref_table'],
    (int)$row['ref_id'],
    (float)($row['qty_in'] ?? 0),
    (float)($row['qty_out'] ?? 0),
    isset($row['unit_cost']) ? (float)$row['unit_cost'] : null,
    $row['note'] ?? null,
    isset($row['created_by']) ? (int)$row['created_by'] : null,
  ]);
}

function get_active_bom_for_product(int $productId, int $branchId): ?array {
  $stmt = db()->prepare("SELECT * FROM bom_headers
    WHERE finished_product_id=? AND is_active=1 AND (branch_id IS NULL OR branch_id=?)
    ORDER BY CASE WHEN branch_id=? THEN 0 ELSE 1 END, id DESC LIMIT 1");
  $stmt->execute([$productId, $branchId, $branchId]);
  $bom = $stmt->fetch();
  return $bom ?: null;
}

function explode_bom_requirements(int $bomId, float $qtyToProduce): array {
  $stmt = db()->prepare("SELECT bi.*, p.name AS material_name, p.product_type
    FROM bom_items bi
    JOIN products p ON p.id = bi.material_product_id
    WHERE bi.bom_id=?
    ORDER BY bi.sort_order ASC, bi.id ASC");
  $stmt->execute([$bomId]);
  $items = $stmt->fetchAll();

  $stmt = db()->prepare("SELECT yield_qty FROM bom_headers WHERE id=? LIMIT 1");
  $stmt->execute([$bomId]);
  $h = $stmt->fetch();
  $yield = max(0.0001, (float)($h['yield_qty'] ?? 1));
  $factor = $qtyToProduce / $yield;

  $out = [];
  foreach ($items as $it) {
    if (($it['product_type'] ?? '') !== 'raw_material') {
      throw new Exception('BOM item wajib produk raw material.');
    }
    $req = $factor * (float)$it['qty_per_yield'];
    $wastage = (float)$it['wastage_pct'];
    if ($wastage > 0) {
      $req *= (1 + ($wastage / 100));
    }
    $out[] = [
      'material_product_id' => (int)$it['material_product_id'],
      'required_qty' => round($req, 4),
      'actual_qty' => round($req, 4),
      'unit_cost' => null,
    ];
  }
  return $out;
}

function create_production_with_items(PDO $db, array $header, array $items): int {
  $stmt = $db->prepare("INSERT INTO production_headers
    (production_no, branch_id, bom_id, finished_product_id, production_date, qty_to_produce, status, mode_source, notes, created_by)
    VALUES (?,?,?,?,?,?,?,?,?,?)");
  $stmt->execute([
    $header['production_no'],
    (int)$header['branch_id'],
    (int)$header['bom_id'],
    (int)$header['finished_product_id'],
    $header['production_date'],
    (float)$header['qty_to_produce'],
    $header['status'] ?? 'draft',
    $header['mode_source'] ?? 'manual_menu',
    $header['notes'] ?? null,
    isset($header['created_by']) ? (int)$header['created_by'] : null,
  ]);
  $id = (int)$db->lastInsertId();

  $stmtItem = $db->prepare("INSERT INTO production_items (production_id, material_product_id, required_qty, actual_qty, unit_cost)
    VALUES (?,?,?,?,?)");
  foreach ($items as $it) {
    $stmtItem->execute([$id, (int)$it['material_product_id'], (float)$it['required_qty'], (float)$it['actual_qty'], $it['unit_cost']]);
  }
  return $id;
}

function post_production(int $productionId, int $userId, string $consumeTransType = 'production_consume', string $outputTransType = 'production_output'): void {
  $db = db();
  $stmt = $db->prepare("SELECT * FROM production_headers WHERE id=? LIMIT 1");
  $stmt->execute([$productionId]);
  $h = $stmt->fetch();
  if (!$h) throw new Exception('Dokumen produksi tidak ditemukan.');
  if ($h['status'] !== 'draft') throw new Exception('Hanya dokumen draft yang dapat diposting.');

  $stmt = $db->prepare("SELECT * FROM production_items WHERE production_id=?");
  $stmt->execute([$productionId]);
  $items = $stmt->fetchAll();
  if (empty($items)) throw new Exception('Item produksi kosong.');

  foreach ($items as $it) {
    $stock = branch_stock((int)$h['branch_id'], (int)$it['material_product_id']);
    if ($stock < (float)$it['actual_qty']) {
      throw new Exception('Stok bahan baku tidak cukup untuk posting produksi.');
    }
  }

  foreach ($items as $it) {
    add_stock_ledger([
      'branch_id' => (int)$h['branch_id'],
      'product_id' => (int)$it['material_product_id'],
      'trans_type' => $consumeTransType,
      'ref_table' => 'production_headers',
      'ref_id' => $productionId,
      'qty_in' => 0,
      'qty_out' => (float)$it['actual_qty'],
      'unit_cost' => $it['unit_cost'],
      'note' => 'Konsumsi produksi',
      'created_by' => $userId,
    ]);
  }

  add_stock_ledger([
    'branch_id' => (int)$h['branch_id'],
    'product_id' => (int)$h['finished_product_id'],
    'trans_type' => $outputTransType,
    'ref_table' => 'production_headers',
    'ref_id' => $productionId,
    'qty_in' => (float)$h['qty_to_produce'],
    'qty_out' => 0,
    'unit_cost' => null,
    'note' => 'Hasil produksi',
    'created_by' => $userId,
  ]);

  $stmt = $db->prepare("UPDATE production_headers SET status='posted', posted_by=?, posted_at=NOW() WHERE id=?");
  $stmt->execute([$userId, $productionId]);
}
