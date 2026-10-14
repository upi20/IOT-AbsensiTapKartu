# Riwayat perubahan

Semua perubahan penting proyek **Absensi RFID Terintegrasi**. Versi firmware mengikuti `VERSI_FIRMWARE` di [firmware/absensi/config.h](firmware/absensi/config.h). Server (`api/`) dan contoh integrasi tidak punya nomor versi terpisah; perubahannya dicatat bersama versi firmware yang membutuhkannya.

Kompatibilitas: setiap versi firmware tetap bekerja dengan server lama. Fitur baru hanya aktif kalau server mengirim pengaturannya. Begitu juga sebaliknya: server baru tetap melayani firmware lama.

---

## Firmware 1.11.4 — 14 Oktober 2026

- **Menu "Tes buzzer & LED" menyesuaikan perangkat.** Alat dengan modul jam RTC tidak punya LED RGB (kabel header LED dipakai RTC), tapi menunya masih bertuliskan "Tes buzzer & LED" sehingga membingungkan. Sekarang tombolnya bernama **Tes buzzer** kalau RTC terpasang, dan tesnya hanya bunyi (4 bip, lalu 2 bip pendek). Alat dengan LED tetap "Tes buzzer & LED" seperti biasa.
- **Info alat → Perangkat: baris "Tegangan baterai (bacaan langsung)".** Angka baterai di layar utama sengaja dihaluskan (dirata-rata dan tidak naik saat listrik padam), jadi bukan bacaan saat itu. Baris ini menampilkan tegangan apa adanya tiap detik, persen menurut tegangan itu, dan rata-ratanya, contoh "3,68 V sekarang = 28% (rata-rata 3,72 V)". Kalau UPS tidak terdeteksi, tegangan yang terbaca di pin tetap tampil untuk memeriksa kabel pembagi.
- **Panduan rakit versi UPS:** [doc/rakit-dotmatrix-ups.html](doc/rakit-dotmatrix-ups.html). Sama dengan versi modul jam (RTC + modul UPS + saklar), ditambah pantau baterai: dua pembagi 10k + 10k disolder di modul UPS (+ baterai dan 5V+ charger), kabel sinyal lewat header **JBAT** (BAT 2d-036 → D34 1k-027, PLN 2d-037 → VP 1k-025), cek multimeter sebelum dicolok, dan tes Info alat. Templat: `hardware/dotmatrix/hasil/templat-1-1-ups.pdf`. `python3 hardware/dotmatrix/tata_letak.py` sekarang membuat ketiga versi.

## Firmware 1.11.3 — 14 Oktober 2026

- **Persen baterai seperti di HP.** Selama listrik padam angkanya hanya boleh turun. Di 1.11.2 angkanya bisa naik sendiri tanpa listrik (contoh 20% saat baru padam, lalu 40% beberapa menit kemudian): tegangan baterai turun saat beban berat (layar terang, WiFi sibuk) lalu naik lagi saat beban ringan, padahal isinya tidak bertambah.
  - Tegangan dirata-rata lebih lama (± 16 detik, sebelumnya ± 4 detik).
  - 30 detik pertama setelah listrik padam tidak dipakai menghitung persen (beban paling berat). Selama itu layar masih menunjukkan angka terakhir saat dicas.
  - Angka saat listrik padam disimpan di memori alat. Kalau alat dimatikan lewat saklar lalu dinyalakan lagi (listrik masih padam), layar langsung memakai angka itu dan tetap tidak naik, walau tegangan baterai sempat pulih saat alat mati. Angka tersimpan dihapus begitu listrik ada lagi. Kalau belum ada angka tersimpan, 30 detik pertama angkanya mengikuti tegangan.
  - "Baterai lemah" tetap tampil sampai listrik kembali (tidak hilang sendiri saat tegangan naik).
- **Info alat dibagi 3 halaman** (sebelumnya 17 baris berdesakan di satu layar): **Ringkasan** (ID, firmware, error, mode absen, jam, daya), **Jaringan** (WiFi, sinyal, IP, Base URL, heartbeat, pengumuman), **Perangkat** (RFID, antrean, layar, restart, RAM). Dibalik dengan tombol panah atas / bawah di kanan. Nama di atas, nilai di bawah selebar layar, jadi Base URL panjang tidak terpotong. Titik di antara panah menunjukkan halaman; titik merah = ada masalah di halaman itu.

## Firmware 1.11.2 — 14 Oktober 2026

- **Indikator baterai UPS** (opsional, modul LX-2BUPS + 2 × 18650). Dua pembagi tegangan 10k + 10k: + baterai → D34 (GPIO34), input 5V+ charger → VP (GPIO36).
  - Terdeteksi otomatis. Kalau tegangan baterai tidak masuk akal (tidak terpasang), ikon tidak tampil dan heartbeat mengirim `raw.power: null`.
  - Bilah status layar utama menampilkan ikon baterai. Listrik ada = ikon dengan petir (sedang dicas). Listrik padam = isi baterai dan persennya.
  - Baris bawah layar: "Listrik padam - baterai 68%" (kuning), dan "Baterai lemah - nyalakan listrik" (merah) di bawah 3,4 V.
  - Heartbeat langsung dikirim saat listrik padam/kembali dan saat baterai mulai lemah. Info alat menampilkan baris **Daya**.
  - `BATERAI_KALIBRASI` di config.h untuk menyamakan tegangan dengan multimeter.
- **Heartbeat** mengirim `raw.power` (lihat [doc/spesifikasi-api.md](doc/spesifikasi-api.md) bagian 5): `{"source":"mains"|"battery","battery_mv":4105,"battery_pct":92,"battery_low":false}`, atau `null` kalau pemantau baterai tidak terpasang. Opsional untuk aplikasi.
- **Server:**
  - Kolom baru di tabel `devices`: `power_source` (`mains` / `battery` / `none` = tanpa UPS), `battery_mv`, `battery_pct`, `battery_low`. Jalankan `php artisan migrate`. Sebelum migrasi, server tetap jalan seperti biasa, hanya `raw.power` tidak disimpan.
  - Heartbeat tanpa `power` (firmware lama) tidak mengubah data daya. Nilai yang tidak sah diabaikan, tegangan dan persen dibatasi ke rentang wajar.
  - Riwayat kejadian mencatat **Listrik padam**, **Listrik kembali**, dan **Baterai lemah** (sekali per perubahan, tidak berulang tiap heartbeat).
  - Halaman Alat menampilkan baris **Daya**: "Listrik PLN · baterai 92%", "Baterai 18% (3,62 V)" (kuning, listrik padam), "Baterai lemah" (merah), "Tanpa UPS", atau "—" kalau belum dilaporkan. Listrik padam dan baterai lemah juga muncul sebagai peringatan di halaman Alat dan dasbor.
  - Catatan: saat listrik padam, router WiFi biasanya ikut mati, jadi alat tampil Offline. Laporan "listrik padam" hanya sampai kalau router juga memakai UPS.

## Firmware 1.11.1 — 14 Oktober 2026

- **Jadwal mode Pilih selalu menang.** Alat menyamakan pilihan DATANG/PULANG dengan jadwal setiap 5 detik. Pilihan manual yang berbeda dari jadwal hanya bertahan 3 menit sejak tombol terakhir ditekan, lalu kembali ke jadwal. Di 1.11.0 pilihan manual bertahan sampai periode berikutnya, sehingga alat yang sempat dipilih DATANG tetap DATANG setelah jam pulang lewat.

## Firmware 1.11.0 — 14 Oktober 2026

- **Jadwal otomatis mode Pilih** (`config.tap_schedule`, diatur per alat dari aplikasi). Contoh `{"check_in":"06:00","check_out":"17:00"}`: pukul 06:00 alat memilih **DATANG** sendiri, pukul 17:00 memilih **PULANG**. Petugas tidak perlu menekan tombol tiap hari.
  - Jadwal memilih sekali di awal tiap periode, termasuk saat alat baru menyala di tengah periode (diubah di 1.11.1).
  - Sebelum jam masuk (tengah malam sampai `check_in`) pilihan kosong, seperti sebelumnya.
  - `null` mematikan jadwal; kalau server tidak mengirimnya, alat memakai jadwal terakhir. Hanya berlaku di mode `select`.
  - Tombol yang belum dipilih menampilkan jamnya ("otomatis 17:00"). Info alat dan heartbeat (`raw.tap_schedule`) menunjukkan jadwal yang dipakai.

## Firmware 1.10.0 — 8 Oktober 2026

- **RTC DS3231 (opsional)** sebagai cadangan jam, di kabel header LED RGB (R = SDA/D25, G = SCL/D26, B = VCC 3,3 V, K = GND).
  - Masalah yang diselesaikan: alat yang menyala ulang tanpa internet tidak punya jam. Tap selama itu tersimpan tanpa jam dan tercatat dengan jam kirim. Contoh: listrik & internet mati 09:00, listrik menyala 12:00, internet 17:00 → tap 12:00–17:00 tercatat ± 17:00. Di mode Pilih, pilihan DATANG/PULANG kemarin juga tidak dikosongkan.
  - Jam internet (`server_time`, lalu NTP) tetap diutamakan. Saat menyala, jam RTC dipakai sementara menunggu internet. Begitu jam internet didapat, RTC disetel ulang darinya, lalu tiap 6 jam.
  - Terdeteksi otomatis. Kalau RTC terpasang, LED RGB tidak dipakai (hasil tap tetap ditandai warna layar dan buzzer). Kalau yang terpasang LED, alat bekerja seperti 1.9.1.
  - Heartbeat mengirim `raw.rtc` (`none` / `ok` / `lost`). Info alat dan layar boot menunjukkan asal jam: server, NTP, atau RTC.
  - Panduan rakit versi modul jam: [doc/rakit-dotmatrix-rtc.html](doc/rakit-dotmatrix-rtc.html) (tanpa LED dan R1–R3; header JRTC SDA/SCL/VCC/GND di 2d-036…039), sekaligus dengan **baterai cadangan**: modul UPS LX-2BUPS 5V + 2 × 18650 → saklar KCD11 → header JDAYA (+5V 2d-032, GND 2d-033) → VIN/GND ESP32. Kabel +5V baterai berwarna **kuning** supaya tidak tertukar dengan merah (3V3). Dibuat dari sumber yang sama dengan versi LED: `python3 hardware/dotmatrix/tata_letak.py` membuat kedua versi.

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
