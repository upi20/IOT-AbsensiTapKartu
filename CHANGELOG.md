# Riwayat perubahan

Semua perubahan penting proyek **Absensi RFID Terintegrasi**. Versi firmware mengikuti `VERSI_FIRMWARE` di [firmware/absensi/config.h](firmware/absensi/config.h). Server (`api/`) dan contoh integrasi tidak punya nomor versi terpisah; perubahannya dicatat bersama versi firmware yang membutuhkannya.

Kompatibilitas: setiap versi firmware tetap bekerja dengan server lama. Fitur baru hanya aktif kalau server mengirim pengaturannya. Begitu juga sebaliknya: server baru tetap melayani firmware lama.

---

## Firmware 1.9.1 — 2 Oktober 2026

Rilis uji update manual. Kodenya sama dengan 1.9.0. Diuji dari website ke alat uji lewat menu **Update firmware**: buzzer diam dan LED biru selama update.

## Firmware 1.9.0 — 2 Oktober 2026

- **Perbaikan:** buzzer bisa tertahan menyala selama update firmware. Bunyi "klik" tombol Pasang tidak sempat dimatikan, karena selama mengunduh alat tidak mengurus buzzer. Sekarang buzzer dimatikan seketika saat update dimulai (`bipMati()`), dan LED menyala **biru tetap** selama update.

## Firmware 1.8.0 — 2 Oktober 2026

Rilis uji update manual. Kodenya sama dengan 1.7.0. Update berhasil, tapi menemukan masalah buzzer yang diperbaiki di 1.9.0.

## Firmware 1.7.0 — 2 Oktober 2026

- **Menu Pengaturan → Update firmware.** Alat langsung bertanya ke server (`GET /ping`) apakah ada versi yang dijadwalkan untuk alat itu.
  - Kalau ada, tampil versi dan ukurannya, dengan tombol **Pasang sekarang**. Pemasangan langsung berjalan, tanpa menunggu alat diam dan tanpa jeda 30 menit setelah gagal.
  - Kalau gagal, alasannya tampil di layar.
- Saat update gagal, log serial mencatat RAM bebas dan blok RAM terbesar, untuk mendiagnosis kegagalan koneksi HTTPS setelah alat lama menyala.

## Firmware 1.6.0 — 2 Oktober 2026

- **Mode absen** (menu **Mode absen**, atau dari website lewat `config.tap_mode`):
  - **Otomatis** (bawaan): semua tap ke `POST /tap`, server yang menentukan datang atau pulang. Sama seperti sebelumnya.
  - **Pilih Datang / Pulang**: layar utama menampilkan tombol **DATANG** dan **PULANG**.
    - Petugas memilih sekali; semua tap berikutnya dikirim ke `POST /check-in` atau `POST /check-out` sampai pilihannya diganti.
    - Pilihan dikosongkan otomatis saat tanggal berganti.
    - Tap sebelum memilih tidak dikirim, dan layar menampilkan "PILIH MODE DULU".
  - Antrean offline ikut menyimpan mode tiap tap. Baris antrean lama tetap terbaca.
  - Heartbeat mengirim `raw.tap_mode` dan `raw.tap_select`.
  - Menu Info alat menambah baris **Mode absen**.
- **Buzzer langsung ke pin D17** tanpa transistor kini didukung. Arus pin D17 dinaikkan ke kekuatan maksimum (± 40 mA) supaya bunyinya tidak serak.
- Menu Pengaturan ditata ulang untuk 12 tombol.

## Firmware 1.5.0 — 2 Oktober 2026

- **Kode error di layar:** E10–E13 (WiFi), E20–E26 (server), E30 (RFID), dan E31 (jam), di baris bawah layar utama, langkah boot, dan Info alat. Juga dikirim di heartbeat (`raw.error`).
- **Ringkasan crash** dari coredump (task, PC, backtrace, id build), dikirim di heartbeat (`raw.crash`) lalu dihapus. Heartbeat juga mengirim `min_free_heap`.
- **Update firmware jarak jauh (OTA)** dari `config.firmware_update`:
  - Alat mengunduh saat diam, memeriksa ukuran dan MD5, lalu restart.
  - Firmware baru berstatus percobaan. Kalau crash sebelum dinyatakan sehat, alat kembali ke versi lama dan melaporkan `raw.ota_failed`.
  - Restart karena listrik padam atau tombol EN di masa percobaan diulang sampai 3 kali, tidak langsung dianggap gagal.
- **Partisi `min_spiffs`:** 2 slot aplikasi 1,9 MB plus coredump. Alat dengan firmware ≤ 1.4.0 harus di-upload sekali lewat USB.
- **ID alat disimpan** di NVS "identitas". Build OTA (`./upload.sh -b`, flag `OTA_BUILD`) memakai ID tersimpan, sehingga satu file `.bin` aman dikirim ke semua alat.
- Header layar boot dipusatkan supaya nomor versi tidak terpotong.

## Firmware 1.4.0 — 1 Oktober 2026

- **Watchdog 60 detik:** alat restart sendiri kalau program macet.
- **Restart harian terjadwal** (`config.restart_at`, bawaan 03:00), hanya saat alat diam.
- Alasan restart terakhir tampil di monitor serial dan Info alat, dan dikirim lewat heartbeat (`raw.reset_reason`).
- Identitas baru **"Absensi RFID Terintegrasi"** di layar boot, monitor serial, dan User-Agent.

## Firmware 1.3.0 — rilis open source pertama

Tap kartu dengan hasil di layar, antrean offline 200 tap, screensaver pengumuman, layar meredup, PIN dan judul dari server, panduan penyetelan di layar sentuh, kalibrasi layar, dan reset pabrik.

---

## Server acuan Laravel (`api/`)

| Untuk firmware | Perubahan | Migrasi |
|---|---|---|
| 1.4.0 | Jam restart harian per alat (`config.restart_at`), diatur di halaman Alat | `2026_10_01_000011_add_restart_at_to_devices_table` |
| 1.5.0 | **Pemantauan alat:** kolom kesehatan, riwayat kejadian (restart, crash, ganti firmware, OTA gagal), peringatan di halaman Alat & dasbor | `…000013_add_health_columns_to_devices_table`, `…000014_create_device_events_table` |
| 1.5.0 | **Menu Firmware:** unggah `.bin` (validasi ukuran, byte `0xE9`, versi di dalam file), jadwalkan per alat / semua alat, `config.firmware_update`, unduhan `GET /api/absensi/firmware/{id}` | `…000012_create_firmware_releases_table` |
| 1.6.0 | **Mode absen:** `POST /api/absensi/check-in` & `/check-out`, `config.tap_mode` per alat, tampilan mode & pilihan alat di halaman Alat | `2026_10_02_000001_add_tap_mode_columns_to_devices_table` |

Semua kode baru aman dijalankan **sebelum** migrasinya: fitur yang butuh kolom baru otomatis nonaktif sampai `php artisan migrate` dijalankan. Tes otomatis: 160 tes lulus.

## Contoh integrasi (`contoh-integrasi/`) & dokumentasi

- **[doc/integrasi-aplikasi.md](doc/integrasi-aplikasi.md):** panduan integrasi ke aplikasi lain dengan **6 fitur wajib** (Kartu belum terdaftar, Rekap, Alat, Firmware, Pengumuman, Pengaturan), urutan kerja, model data, Postman/Newman, checklist, dan masalah umum.
- **Server contoh PHP & Node** menjalankan keenam fitur lewat API admin JSON (`X-Admin-Key`), dengan perilaku identik, ditambah endpoint `/check-in` & `/check-out`.
- **Postman** disusun ulang: folder **1. API alat** (uji kepatuhan untuk aplikasi mana pun) dan **2. Admin contoh**, plus file `contoh-firmware.bin` untuk menguji alur firmware. Bisa dijalankan otomatis dengan Newman.
- **[doc/spesifikasi-api.md](doc/spesifikasi-api.md):** bagian 4.1 Mode pilih, field heartbeat baru, `config.firmware_update`, dan `config.tap_mode`.
- **[doc/panduan-pengguna.md](doc/panduan-pengguna.md):** panduan untuk pengguna/klien.

## Perangkat keras

- **PCB dot matrix** dirancang ulang untuk papan **1 sisi 30 × 50 lubang** (label `1a`–`2d`, `001`–`050`), dengan posisi LCD, RC522, dan ESP32 diukur langsung di papan:
  - semua komponen di depan, kabel di belakang, disolder langsung ke kaki;
  - LED lewat kabel; buzzer di papan dan langsung ke D17;
  - baris 042–050 tidak dipakai.
  - Panduan interaktif: [doc/rakit-dotmatrix.html](doc/rakit-dotmatrix.html).
- Alat pertama dirakit dengan tata letak ini dan berjalan, termasuk update jarak jauh 1.5.0 → 1.9.1.
