PATCH RIWAYAT TRANSAKSI POS
===========================

Fitur:
- Filter Hari Ini, Kemarin, 7 Hari Terakhir, dan Custom.
- Tab Detail Transaksi dan Rekap Penjualan.
- Detail transaksi read-only langsung dari POS.
- Cetak ulang struk transaksi 58 mm.
- Rekap transaksi, tunai, QRIS, retur, penjualan kotor/bersih.
- Rekap produk, rekap harian, dan rekap per kasir.
- Print laporan rekap khusus printer thermal 58 mm.
- Formatter Android membedakan struk transaksi dengan laporan penjualan.

INSTALASI WEB
1. Backup file dan database.
2. Upload seluruh isi patch mengikuti struktur folder, lalu timpa file lama.
3. Jalankan db/updates_pos_sales_history.sql melalui phpMyAdmin.
4. Logout/login kembali atau refresh POS.
5. Tombol "Riwayat Transaksi" muncul di topbar POS.

INSTALASI ANDROID
- Perubahan web langsung terbaca oleh APK lama setelah refresh.
- Untuk print rekap Bluetooth 58 mm dengan format laporan khusus, build dan install APK dari folder:
  android/hope-pos-print-bridge
- APK dinaikkan menjadi versionCode 3 / versionName 1.0.2-pos-history.

CATATAN
- Riwayat hanya menampilkan transaksi POS (kode TRX-) pada cabang aktif.
- Transaksi revisi lama tidak dihitung; hanya revisi aktif.
- Transaksi retur tetap terlihat dan dikurangi dari penjualan bersih.
- Modul checkout, stok, BOM, loyalty, dan pesanan online tidak diubah.
