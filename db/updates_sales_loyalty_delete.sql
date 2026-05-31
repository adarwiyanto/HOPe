-- Patch: metadata loyalty transaksi penjualan untuk rollback poin saat owner menghapus transaksi.
-- Aman dijalankan pada MariaDB/MySQL yang mendukung ADD COLUMN IF NOT EXISTS.
-- Aplikasi juga memiliki schema guard ensure_sales_loyalty_columns() sehingga patch tetap berjalan tanpa eksekusi manual file ini.

ALTER TABLE sales
  ADD COLUMN IF NOT EXISTS order_id INT NULL,
  ADD COLUMN IF NOT EXISTS customer_id INT NULL,
  ADD COLUMN IF NOT EXISTS loyalty_points_earned INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS loyalty_points_redeemed INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS loyalty_remainder_before INT NULL,
  ADD COLUMN IF NOT EXISTS loyalty_remainder_after INT NULL;

-- Index idx_sales_order_customer dibuat otomatis oleh aplikasi bila database mendukung.
