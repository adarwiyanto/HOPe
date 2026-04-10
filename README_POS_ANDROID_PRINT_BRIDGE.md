# HOPe POS Android Print Bridge (58mm)

## 1) Apply SQL migration
Jalankan file berikut ke database production/staging:

- `db/updates_pos_print_jobs.sql`

Contoh:

```bash
mysql -u <user> -p <db_name> < db/updates_pos_print_jobs.sql
```

## 2) Build APK bridge
Project Android ada di:

- `android/hope-pos-print-bridge/`

Langkah build (di mesin dengan Android SDK):

```bash
cd android/hope-pos-print-bridge
./gradlew assembleDebug
```

APK debug akan muncul di:

- `android/hope-pos-print-bridge/app/build/outputs/apk/debug/app-debug.apk`

## 3) Install APK ke HP Android
Aktifkan install from unknown sources, lalu install dengan:

```bash
adb install -r app-debug.apk
```

Atau kirim file APK ke HP dan install manual (sideload).

## 4) Pairing printer Panda PRJ-58D
1. Nyalakan printer Panda PRJ-58D.
2. Pair printer dari pengaturan Bluetooth Android.
3. Buka app **HOPe POS Print Bridge**.
4. Masuk **Pengaturan Printer** dan pilih device Panda (nama + MAC).

## 5) Test end-to-end
1. Buka POS web di Android dan lakukan transaksi sampai sukses.
2. Masuk halaman preview `pos/receipt.php`.
3. Klik **Print via App**.
4. Browser akan lempar deep link `hopepos://print?...` ke APK.
5. APK fetch payload via `pos/print_job_api.php?action=get&token=...`.
6. Preview 58mm muncul, klik **Cetak**.
7. Setelah sukses, APK call `mark_printed`.

## 6) Fallback bila app belum terpasang
Di halaman receipt, jika handoff app tidak merespons, user akan mendapatkan notice jelas dan tetap bisa klik **Print Browser** (fallback `window.print()`).
