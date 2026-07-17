# Patch HOPe POS — Total Transaksi Multi-item

## Perbaikan

1. Riwayat dan rekap POS kini menghitung total dari `SUM(total)` seluruh item, lalu menerapkan diskon, pajak, dan biaya transaksi satu kali.
2. Detail transaksi tidak lagi memakai `grand_total` dari baris item pertama.
3. Checkout baru menyimpan `grand_total` yang sama pada seluruh item dalam satu `transaction_code`.
4. Modul revisi penjualan menggunakan total hasil rekonstruksi seluruh item.
5. SQL koreksi data lama tersedia di `db/fix_pos_transaction_totals_20260717.sql`.

## Instalasi

1. Backup file website dan database.
2. Timpa folder/file patch ke root HOPe.
3. Jalankan `db/fix_pos_transaction_totals_20260717.sql` melalui phpMyAdmin.
4. Buka ulang Riwayat Transaksi dan periksa transaksi `TRX-20260716220457-01A1`; total harus menjadi Rp55.000.

## File berubah

- `core/pos_sales_history.php`
- `core/sales_revision.php`
- `pos/index.php`
- `db/updates_roles_sales_revision.sql`
- `db/fix_pos_transaction_totals_20260717.sql`
