-- Patch Riwayat Transaksi POS + optimasi filter cabang/periode.
-- Aman dijalankan ulang melalui phpMyAdmin.

SET @db_name := DATABASE();
SET @index_exists := (
  SELECT COUNT(1)
  FROM information_schema.statistics
  WHERE table_schema = @db_name
    AND table_name = 'sales'
    AND index_name = 'idx_sales_pos_history'
);
SET @sql := IF(
  @index_exists = 0,
  'CREATE INDEX idx_sales_pos_history ON sales (branch_id, is_active_revision, sold_at, transaction_code)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
