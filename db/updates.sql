-- Tambahan kolom untuk pembayaran & retur transaksi
ALTER TABLE sales
  ADD COLUMN payment_method VARCHAR(20) NOT NULL DEFAULT 'cash' AFTER total,
  ADD COLUMN payment_proof_path VARCHAR(255) NULL AFTER payment_method,
  ADD COLUMN return_reason VARCHAR(255) NULL AFTER payment_proof_path,
  ADD COLUMN returned_at TIMESTAMP NULL DEFAULT NULL AFTER return_reason;

-- Perubahan role superadmin menjadi owner + undangan user
UPDATE users SET role='owner' WHERE role='superadmin';
ALTER TABLE users
  MODIFY role ENUM('owner','admin','user','pegawai') NOT NULL DEFAULT 'admin';

CREATE TABLE IF NOT EXISTS user_invites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  role VARCHAR(30) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at TIMESTAMP NULL DEFAULT NULL,
  used_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_token_hash (token_hash),
  KEY idx_email (email)
) ENGINE=InnoDB;

ALTER TABLE users
  ADD COLUMN email VARCHAR(190) NULL AFTER username,
  ADD COLUMN avatar_path VARCHAR(255) NULL AFTER role;

CREATE TABLE IF NOT EXISTS password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at TIMESTAMP NULL DEFAULT NULL,
  used_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_token_hash (token_hash),
  KEY idx_user_id (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
