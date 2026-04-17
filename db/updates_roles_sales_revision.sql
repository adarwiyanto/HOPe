-- Additive migration: role/permission extensible model + sales revisioning.

CREATE TABLE IF NOT EXISTS roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role_key VARCHAR(40) NOT NULL,
  role_name VARCHAR(80) NOT NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  is_owner_locked TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_role_key (role_key)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS role_permissions (
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
  UNIQUE KEY uniq_role_menu (role_id, menu_key)
) ENGINE=InnoDB;

ALTER TABLE users ADD COLUMN role_id INT NULL AFTER role;

INSERT INTO roles (role_key, role_name, is_system, is_owner_locked, is_active) VALUES
('owner','Owner',1,1,1),('admin','Admin',1,0,1),('manager','Manager',1,0,1),('kasir','Kasir',1,0,1),('gudang','Gudang',1,0,1)
ON DUPLICATE KEY UPDATE role_name=VALUES(role_name), is_active=1;

UPDATE users SET role='owner' WHERE role IN ('superadmin');
UPDATE users SET role='kasir' WHERE role IN ('pegawai','user') OR role IS NULL OR role='';
UPDATE users u JOIN roles r ON r.role_key=u.role SET u.role_id=r.id WHERE u.role_id IS NULL;

ALTER TABLE sales
  ADD COLUMN base_sale_code VARCHAR(60) NULL AFTER transaction_code,
  ADD COLUMN revision_suffix VARCHAR(10) NULL AFTER base_sale_code,
  ADD COLUMN revision_no INT NOT NULL DEFAULT 0 AFTER revision_suffix,
  ADD COLUMN is_active_revision TINYINT(1) NOT NULL DEFAULT 1 AFTER revision_no,
  ADD COLUMN revised_from_sale_id INT NULL AFTER is_active_revision,
  ADD COLUMN revision_reason_category VARCHAR(80) NULL AFTER revised_from_sale_id,
  ADD COLUMN revision_reason_text TEXT NULL AFTER revision_reason_category,
  ADD COLUMN revised_by_user_id INT NULL AFTER revision_reason_text,
  ADD COLUMN revised_at DATETIME NULL AFTER revised_by_user_id,
  ADD COLUMN revision_status VARCHAR(30) NOT NULL DEFAULT 'active' AFTER revised_at,
  ADD COLUMN original_sale_id INT NULL AFTER revision_status,
  ADD COLUMN customer_name VARCHAR(160) NULL AFTER payment_proof_path,
  ADD COLUMN discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER total,
  ADD COLUMN tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER discount_amount,
  ADD COLUMN extra_fee DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER tax_amount,
  ADD COLUMN grand_total DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER extra_fee,
  ADD COLUMN notes TEXT NULL AFTER grand_total,
  ADD COLUMN sale_status VARCHAR(30) NOT NULL DEFAULT 'completed' AFTER notes;

UPDATE sales SET base_sale_code=COALESCE(NULLIF(transaction_code,''), CONCAT('LEGACY-',id)) WHERE base_sale_code IS NULL OR base_sale_code='';
UPDATE sales SET grand_total=total WHERE grand_total IS NULL OR grand_total=0;
UPDATE sales SET revision_status='active' WHERE revision_status IS NULL OR revision_status='';

CREATE INDEX idx_sales_revision_active ON sales (base_sale_code, is_active_revision, revision_no);
CREATE INDEX idx_sales_original ON sales (original_sale_id);
