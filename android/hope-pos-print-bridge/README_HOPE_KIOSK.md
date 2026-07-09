# Hope Native Kiosk Mode

Patch ini menambahkan fitur kiosk native di dalam APK Hope, tanpa aplikasi kiosk pihak ketiga.

## Fitur

- Setup awal saat aplikasi Hope pertama kali dibuka.
- Mode Normal.
- Mode Kunci hanya aplikasi Hope.
- Mode Kunci Hope + maksimal 2 aplikasi tambahan.
- PIN keluar kiosk.
- Triple tap layar untuk membuka dialog PIN.
- Permintaan izin `Tampil di atas aplikasi lain` (`SYSTEM_ALERT_WINDOW`) saat setup.
- Device Admin Receiver agar Hope bisa dijadikan Device Owner.
- Lock Task Mode bila perangkat sudah Device Owner.

## Aktivasi kiosk penuh

Android hanya mengizinkan kiosk penuh bila aplikasi menjadi Device Owner. Jalankan setelah factory reset dan sebelum akun Google/policy lain aktif:

```bash
adb shell dpm set-device-owner id.my.hopenoodles.hopepos/.kiosk.HopeDeviceAdminReceiver
```

Setelah itu buka aplikasi Hope, aktifkan izin tampil di atas aplikasi lain, pilih mode kiosk, dan simpan PIN.

## Catatan

Tanpa Device Owner, aplikasi tetap bisa mencoba `startLockTask()`, tetapi kekuatannya bergantung pengaturan Android/Infinix. Untuk tablet kasir/dapur, Device Owner adalah mode yang disarankan.
