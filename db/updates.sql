-- Tambahan kolom untuk pembayaran & retur transaksi
ALTER TABLE sales
  ADD COLUMN payment_method VARCHAR(20) NOT NULL DEFAULT 'cash' AFTER total,
  ADD COLUMN payment_proof_path VARCHAR(255) NULL AFTER payment_method,
  ADD COLUMN return_reason VARCHAR(255) NULL AFTER payment_proof_path,
  ADD COLUMN returned_at TIMESTAMP NULL DEFAULT NULL AFTER return_reason;
