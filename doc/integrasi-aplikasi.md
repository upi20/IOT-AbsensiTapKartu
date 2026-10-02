# Panduan Integrasi Aplikasi

Panduan ini untuk **pengembang aplikasi lain** (sistem sekolah, HRIS, ERP, dan sebagainya) yang ingin memakai alat **Absensi RFID Terintegrasi**. Isinya: apa yang wajib dibangun, dari mana datanya, dan cara mengujinya sampai siap dipakai.

Panduan ini dipakai bersama tiga sumber lain:

| Dokumen | Isi |
|---|---|
| [spesifikasi-api.md](spesifikasi-api.md) | **Kontrak teknis** antara alat dan aplikasi: endpoint, header, format JSON, kode HTTP |
| [contoh-integrasi/](../contoh-integrasi/README.md) | Server contoh **PHP** dan **Node.js** yang sudah menjalankan semua fitur di panduan ini |
| [contoh-integrasi/postman/](../contoh-integrasi/postman/) | Koleksi **Postman** untuk menguji aplikasi Anda (bisa dijalankan otomatis dengan Newman) |

Server acuan Laravel di [api/](../api/README.md) juga menjalankan semua fitur ini lengkap dengan panel admin. Bisa dipakai langsung, atau dijadikan contoh.

---

## Daftar isi

1. [Gambaran besar](#1-gambaran-besar)
2. [Fitur wajib](#2-fitur-wajib)
3. [Urutan kerja integrasi](#3-urutan-kerja-integrasi)
4. [Data yang perlu disimpan](#4-data-yang-perlu-disimpan)
5. [API alat (yang dipanggil alat)](#5-api-alat-yang-dipanggil-alat)
6. [Fitur wajib satu per satu](#6-fitur-wajib-satu-per-satu)
7. [Server contoh & API admin contoh](#7-server-contoh--api-admin-contoh)
8. [Menguji dengan Postman & Newman](#8-menguji-dengan-postman--newman)
9. [Checklist siap pakai](#9-checklist-siap-pakai)
10. [Masalah yang sering terjadi](#10-masalah-yang-sering-terjadi)

---

## 1. Gambaran besar

```
                    ┌──────────────────────── Aplikasi Anda ────────────────────────┐
[Kartu] ─tap─▶ [Alat] ──▶  API alat  (/ping, /tap, /heartbeat,       ──▶  Database
                 ▲          /announcements, /firmware/{versi})            (anggota, absensi,
                 │                                                          alat, pengumuman,
                 └── config (judul, PIN, layar redup, restart,              firmware, pengaturan)
                     pengumuman, update firmware)                              │
                                                                               ▼
                                              Halaman admin: Kartu belum terdaftar, Rekap,
                                              Alat, Firmware, Pengumuman, Pengaturan
```

- **Alat selalu memulai komunikasi.** Aplikasi tidak pernah menghubungi alat. Semua perintah ke alat (judul, PIN, restart, update firmware) dititipkan di objek `config` pada balasan `/ping` dan `/heartbeat`.
- **Alat tidak menyimpan data absensi permanen.** Setiap tap langsung dikirim. Kalau server mati, tap ditampung di antrean alat (maks. 200) lalu dikirim ulang.
- **Logika bisnis milik aplikasi**: siapa pemilik kartu, kapan dihitung masuk atau pulang, terlambat atau tidak.

---

## 2. Fitur wajib

Selain **API alat** (bagian 5) yang membuat alat bisa dipakai absen, aplikasi Anda wajib punya **enam fitur** berikut supaya alat bisa dikelola tanpa membuka kode atau database.

| Fitur | Untuk apa | Data dari alat | Yang dikirim ke alat |
|---|---|---|---|
| **Kartu belum terdaftar** | Melihat nomor kartu yang ditempel tapi belum punya pemilik, lalu mendaftarkannya | `/tap` dengan kartu tak dikenal | Balasan `status: "unknown"` |
| **Rekap** | Laporan masuk/pulang per orang per hari, bisa diunduh (CSV/Excel) | `/tap` (`tapped_at`, `queued`, `tap_id`) | – |
| **Alat** | Daftar alat, online/offline, kesehatan, riwayat restart & crash, pengaturan per alat (PIN, jam restart, versi firmware tujuan) | `/heartbeat` (`firmware`, `raw.*`), header `X-Device-ID` | `config.pin`, `config.restart_at` |
| **Firmware** | Mengunggah firmware baru dan mengirimnya ke alat tanpa kabel | `firmware`, `raw.ota_failed` di heartbeat | `config.firmware_update` + unduhan file `.bin` |
| **Pengumuman** | Isi screensaver alat (judul, isi, ikon) | – | `GET /announcements`, `config.announcements_rev` |
| **Pengaturan** | API key, judul layar, layar redup, waktu screensaver, jeda tap ganda, jam restart bawaan | – | `config.title`, `config.dim_after`, `config.dim_level`, isi `/announcements` |

> Di spesifikasi teknis, pengumuman dan update firmware tertulis "opsional" **bagi alat**: alat tetap jalan tanpa keduanya. Untuk **produk yang dikirim ke pelanggan**, keenam fitur di atas kami jadikan wajib supaya alat bisa dirawat dari jauh.

Fitur pendukung yang hampir pasti juga dibutuhkan: **Anggota** (karyawan/siswa beserta nomor kartunya) dan **daftar kehadiran harian**. Keduanya ada di server contoh dan server acuan.

---

## 3. Urutan kerja integrasi

1. **Pelajari kontraknya:** baca [spesifikasi-api.md](spesifikasi-api.md) bagian 1–6 (± 20 menit).
2. **Jalankan server contoh** (PHP atau Node, bagian 7) dan sambungkan satu alat ke laptop. Dengan begitu Anda tahu persis apa yang dikirim alat sebelum menulis kode sendiri.
3. **Buat tabel** di database aplikasi Anda (bagian 4).
4. **Buat API alat**: `/ping`, `/tap`, `/heartbeat`, `/announcements`, `/firmware/{versi}` (bagian 5).
5. **Uji API alat** dengan `contoh-integrasi/tes/cek-server.sh` dan folder **"1. API alat"** di Postman (bagian 8). Semua harus lulus sebelum lanjut.
6. **Bangun enam fitur wajib** di halaman admin aplikasi Anda (bagian 6).
7. **Sambungkan alat sungguhan:** di alat, buka Pengaturan → Server, isi **Base URL** dan **API key**, lalu tekan **Tes koneksi**.
8. **Uji di alat** memakai [checklist siap pakai](#9-checklist-siap-pakai).

---

## 4. Data yang perlu disimpan

Nama tabel dan kolom bebas. Berikut isi minimal yang dibutuhkan keenam fitur.

| Tabel | Kolom penting | Dipakai oleh |
|---|---|---|
| **anggota** | `rfid` (unik, teks), `nama`, `foto_url`, `aktif` | `/tap`, Kartu belum terdaftar, Rekap |
| **absensi** (satu baris per tap) | `tap_id` (unik per alat), `rfid`, `device_id`, `status` (`check_in`/`check_out`/`duplicate`/`unknown`/`rejected`), `waktu`, `queued`, `payload` (JSON asli), `balasan` (JSON yang dikirim, untuk tap yang terkirim ulang) | `/tap`, Rekap, Kartu belum terdaftar |
| **alat** | `device_id` (unik, dari header `X-Device-ID`), `nama`, `last_seen_at`, `firmware`, `ip`, `rssi`, `wifi_ssid`, `uptime_s`, `reset_reason`, `rfid_ok`, `queue`, `free_heap`, `min_free_heap`, `error`, `ota_failed`, `pin`, `restart_at`, `firmware_target` | Alat, Firmware, `config` |
| **kejadian_alat** | `device_id`, `jenis` (`boot`, `crash`, `firmware`, `ota_failed`), `pesan`, `detail` (JSON), `waktu` | Alat (riwayat) |
| **firmware** | `versi` (unik), `path` file, `ukuran`, `md5`, `catatan`, `diunggah_at` | Firmware |
| **pengumuman** | `id`, `judul` (≤ 40), `isi` (≤ 160), `ikon`, `aktif`, `urutan` | Pengumuman |
| **pengaturan** | `api_key`, `judul`, `dim_after`, `dim_level`, `screensaver_interval`, `screensaver_idle`, `jeda_ganda`, `restart_bawaan` | Pengaturan, `config` |

Tips:
- **Nomor kartu disimpan sebagai teks**, bukan angka: `0218893066` harus tetap diawali nol.
- **Indeks unik `(device_id, tap_id)`** di tabel absensi adalah cara termudah mencegah tap ganda dari antrean.
- **Simpan waktu lengkap dengan zona waktu**, atau di zona waktu lokal yang konsisten. Alat mengirim waktu ISO 8601 dengan offset, contoh `2026-10-02T07:15:00+07:00`.

---

## 5. API alat (yang dipanggil alat)

Kontrak lengkapnya ada di [spesifikasi-api.md](spesifikasi-api.md). Ringkasannya:

| Endpoint | Kapan dipanggil | Aplikasi harus |
|---|---|---|
| `GET {base}/ping` | Saat alat menyala & tombol "Tes koneksi" | Balas `ok`, `message`, `server_time`, `config` |
| `POST {base}/tap` | Setiap kartu ditempel (dan saat antrean dikirim ulang) | Tentukan status (`check_in`, `check_out`, `duplicate`, `unknown`, `rejected`), balas **dalam < 1 detik** |
| `POST {base}/heartbeat` | Tiap 60 detik | Catat `last_seen_at` + kesehatan dari `raw`, balas `server_time` & `config` |
| `GET {base}/announcements` | Saat tersambung, saat `announcements_rev` berubah, paling lambat tiap 10 menit | Balas daftar pengumuman aktif (maks. 10) |
| `GET {base}/firmware/{versi}` (URL bebas) | Saat `config.firmware_update` dikirim | Kirim file `.bin` mentah dengan `Content-Length` |

**Aturan yang paling sering terlewat:**
1. **API key salah → HTTP 401.** Alat menampilkan **E21** dan tidak memasukkan tap ke antrean.
2. **Header `X-Device-ID` wajib.** Kalau tidak ada → 400. Daftarkan alat baru secara otomatis saat ID baru pertama kali muncul.
3. **Semua hasil bisnis memakai HTTP 200**, termasuk kartu tak dikenal dan `duplicate`. HTTP 5xx/429 membuat alat menyimpan tap di antrean dan mencoba lagi.
4. **`tap_id` yang sama dikirim ulang → jangan dicatat dua kali.** Balas lagi respons yang dulu.
5. **Tap antrean (`queued: true`) memakai `tapped_at`**, bukan waktu diterima. Kalau `tapped_at` bernilai `null`, pakai waktu diterima.
6. **Balas `/tap` secepatnya.** Lebih dari 3 detik, alat menganggap gagal dan menyimpan tap di antrean.

### Mode absen "Pilih Datang/Pulang" (opsional)

**Kapan dipakai:** kalau instansi ingin petugas yang menentukan DATANG atau PULANG (misalnya shift tidak tetap, atau aturan "tap kedua = pulang" tidak cocok), bukan server. Kalau aturan otomatis di `/tap` sudah cukup, lewati bagian ini.

**Di alat** (firmware 1.6.0 ke atas) ada pengaturan **Mode absen**:
- **Otomatis** (`auto`, bawaan): semua tap ke `POST {base}/tap`, seperti biasa.
- **Pilih Datang/Pulang** (`select`): layar utama menampilkan tombol **DATANG** dan **PULANG**. Petugas memilih **sekali**; semua tap berikutnya dikirim ke endpoint sesuai pilihan sampai diganti. Pilihan disimpan di alat dan dikosongkan otomatis saat tanggal berganti. Kalau belum memilih lalu kartu ditempel, alat menampilkan "Pilih DATANG atau PULANG dulu" dan tidak mengirim apa pun.

**Endpoint** (dipanggil alat hanya di mode `select`):

| Endpoint | Body | Aplikasi harus |
|---|---|---|
| `POST {base}/check-in` | sama dengan `/tap` + `"mode": "check_in"` | Balas seperti `/tap`. Contoh aturan: sudah datang hari itu → `duplicate`, selain itu `check_in` |
| `POST {base}/check-out` | sama dengan `/tap` + `"mode": "check_out"` | Balas seperti `/tap`. Contoh aturan: `check_out` (boleh berkali-kali), `duplicate` kalau masih dalam jeda tap ganda |

- Header, format respons, kode HTTP, dan antrean (`queued` + `tapped_at`) **sama persis** dengan `/tap`. Kartu tak dikenal tetap `unknown`, nonaktif tetap `rejected`.
- **`tap_id` idempoten lintas endpoint:** `tap_id` yang sudah dicatat di `/tap`, `/check-in`, atau `/check-out` → balas lagi respons yang dulu.
- Simpan tap dari ketiga endpoint di **tabel absensi yang sama**, supaya Rekap tetap satu.
- Endpoint ini opsional. Aplikasi yang tidak menyediakannya cukup tidak memakai mode `select`: alat akan mendapat 404 dan menampilkan **E23**.

**Diatur dari aplikasi:** kirim `config.tap_mode` (`"auto"` atau `"select"`) di `/ping` dan `/heartbeat` untuk alat tertentu. Tidak dikirim (atau `null`) = alat memakai pengaturan di menunya sendiri. Heartbeat firmware 1.6.0 melaporkan `raw.tap_mode` (mode yang sedang dipakai) dan `raw.tap_select` (`"check_in"`, `"check_out"`, atau `null` = belum memilih); tampilkan di halaman Alat (6.3).

Server contoh PHP/Node dan server acuan sudah menyediakan kedua endpoint ini; contoh request & respons ada di [contoh-integrasi/README.md](../contoh-integrasi/README.md#mode-absen-pilih-datangpulang-check-in--check-out).

---

## 6. Fitur wajib satu per satu

### 6.1 Kartu belum terdaftar

**Tujuan:** saat kartu baru ditempel di alat, admin bisa melihat nomornya lalu mendaftarkannya ke seseorang tanpa mengetik nomor kartu.

**Logika:**
1. Di `/tap`, kalau `rfid` tidak ada di tabel anggota, balas `"status": "unknown"` (HTTP 200) dan **tetap catat tap-nya**.
2. Daftar "kartu belum terdaftar" = nomor kartu dengan tap berstatus `unknown` yang **sampai sekarang** belum ada di tabel anggota. Kartu yang sudah didaftarkan otomatis hilang dari daftar.
3. Tampilkan per kartu: nomor, jumlah tap, pertama & terakhir terlihat, alat terakhir.
4. Sediakan tombol **"Daftarkan"** yang membuka form anggota dengan nomor kartu sudah terisi.

**Alur kerja pengguna:** minta orangnya menempelkan kartu di alat (layar menampilkan "tidak terdaftar"), buka halaman Kartu belum terdaftar, klik **Daftarkan**, isi nama, simpan. Tap berikutnya langsung dikenali.

### 6.2 Rekap

**Tujuan:** laporan kehadiran yang bisa diunduh.

**Logika (bisa disesuaikan aturan instansi Anda):**
- Per anggota per tanggal: **masuk** = tap `check_in` pertama, **pulang** = tap `check_out` terakhir, **durasi** = selisihnya, **jumlah tap** = tap berstatus `check_in`/`check_out`/`duplicate`.
- Filter rentang tanggal (contoh server membatasi 92 hari supaya ringan).
- Unduhan **CSV** (bisa dibuka di Excel). Kolom contoh: `tanggal, rfid, nama, masuk, pulang, durasi_menit, jumlah_tap`.
- Tap antrean memakai waktu tap asli, jadi rekap tetap benar walau internet sempat putus.

Aturan seperti terlambat, lembur, shift malam, atau hari libur sepenuhnya ditentukan aplikasi Anda. Alat hanya mengirim fakta: kartu X ditempel di alat Y pada jam Z.

### 6.3 Alat

**Tujuan:** tahu kondisi setiap alat dari jauh, sebelum pelanggan menelepon.

**Yang ditampilkan per alat:**

| Data | Sumber | Keterangan |
|---|---|---|
| ID & nama | header `X-Device-ID`, nama diisi admin | ID tertanam di firmware dan tidak berubah saat reset pabrik |
| **Online / Offline** | `last_seen_at` dari request apa pun | Online kalau ada kontak < 3 menit terakhir (heartbeat tiap 60 detik) |
| Firmware, IP, WiFi, sinyal | `firmware`, `raw.ip`, `raw.wifi_ssid`, `raw.rssi` | Sinyal < −80 dBm = lemah |
| Menyala sejak, alasan restart | `raw.uptime_s`, `raw.reset_reason` | |
| RFID, antrean, RAM | `raw.rfid_ok`, `raw.queue`, `raw.free_heap`, `raw.min_free_heap` | |
| **Kode error di layar** | `raw.error` | Tabel E10–E31 di [spesifikasi bagian 5](spesifikasi-api.md#5-post-baseheartbeat) |
| Mode absen & pilihan saat ini | `raw.tap_mode`, `raw.tap_select` | Firmware 1.6.0 ke atas. Mis. "Pilih Datang/Pulang: DATANG" atau "belum memilih" (bagian 5) |

**Peringatan otomatis** (tampilkan mencolok, misalnya di dasbor "N alat perlu diperiksa"):

| Kondisi | Pesan contoh | Biasanya berarti |
|---|---|---|
| Tidak ada kontak > 10 menit | Offline sejak 07:15 | Listrik/WiFi lokasi mati |
| `rfid_ok` = false | Pembaca RFID tidak terdeteksi (E30) | Kabel RC522 longgar |
| `rssi` < −80 | Sinyal WiFi lemah (−85 dBm) | Router terlalu jauh |
| `queue` > 20 | 35 tap menunggu di antrean alat | Server tidak terjangkau dari lokasi |
| `error` terisi | E11 Password WiFi salah | Lihat tabel kode error |
| ≥ 3 restart `watchdog`/`panic`/`brownout` dalam 24 jam | Sering restart tidak normal | Adaptor/kabel daya bermasalah (brownout) atau bug firmware |
| `min_free_heap` < 20000 | RAM hampir habis | Laporkan ke pengembang firmware |
| `ota_failed` terisi | Update firmware 1.6.0 gagal | Lihat 6.4 |

**Riwayat kejadian** (dicatat dari heartbeat):
- **boot:** `uptime_s` lebih kecil dari heartbeat sebelumnya, atau lebih kecil dari jarak waktu sejak heartbeat sebelumnya. Simpan `reset_reason`.
- **crash:** `raw.crash` dikirim (task, alamat PC, backtrace, id build). Alat mengirimnya berulang sampai heartbeat berhasil, jadi **abaikan duplikat** dengan PC & backtrace yang sama dalam 10 menit. Alamatnya bisa diterjemahkan pengembang firmware dengan file `.elf` (lihat README utama, bagian 2.8).
- **firmware:** versi firmware berubah.
- **ota_failed:** update firmware gagal.

**Pengaturan per alat:**

| Pengaturan | Dikirim sebagai | Aturan |
|---|---|---|
| PIN menu | `config.pin` | 4–8 angka. Jangan kirim kalau alat tidak punya PIN khusus |
| Jam restart harian | `config.restart_at` | `"HH:MM"`, atau `""` = tidak restart otomatis. Bawaan `"03:00"` |
| Firmware tujuan | `config.firmware_update` | Lihat 6.4 |
| Mode absen | `config.tap_mode` | `"auto"` / `"select"`. Jangan kirim (atau `null`) = ikuti pengaturan di alat. `select` hanya kalau aplikasi menyediakan `/check-in` & `/check-out` (bagian 5) |

### 6.4 Firmware

**Tujuan:** memperbarui firmware alat di lokasi tanpa kabel USB.

**Unggah:**
- File `.bin` hasil `./upload.sh -b` (README utama, bagian 2.7).
- Validasi:
  - ukuran ≤ **1 966 080 byte**;
  - byte pertama **`0xE9`** (tanda image ESP32);
  - teks versi (mis. `1.6.0`) **ada di dalam file** (mencegah salah label);
  - versi belum pernah diunggah.
- Simpan **ukuran** dan **MD5**.

**Menjadwalkan:**
- Admin memilih versi tujuan per alat (`firmware_target`), atau "terapkan ke semua alat". **Selalu coba di satu alat dulu.**
- Di `/ping` dan `/heartbeat`, kirim `config.firmware_update` = `{version, url, size, md5}` **hanya kalau** alat punya target, versi target ≠ `firmware` alat, **dan** ≠ `raw.ota_failed`.
- `url` harus **absolut** dan **satu origin dengan Base URL** alat, supaya alat ikut mengirim API key. Contoh: `https://app.anda.com/api/absensi/firmware/1.6.0`.

**Unduhan `GET url`:**
- Cek API key seperti endpoint lain.
- Hanya layani alat yang memang dijadwalkan ke versi itu (selain itu 404).
- Balas file mentah dengan `Content-Type: application/octet-stream`, **`Content-Length`** yang benar, dan tanpa redirect. Header `x-MD5` boleh ditambahkan.

**Status yang ditampilkan:**

| Kondisi | Status |
|---|---|
| target ada, `firmware` ≠ target | Menunggu alat mengunduh |
| `firmware` = target | Sudah terpasang |
| `raw.ota_failed` = target | Gagal, kembali ke versi lama. Perbaiki firmware, naikkan versi, unggah ulang |

**Keamanan:** alat tidak memeriksa sertifikat HTTPS. Aktifkan update hanya saat dibutuhkan dan hanya untuk alat yang dipilih (spesifikasi bagian 6.1).

### 6.5 Pengumuman

**Tujuan:** isi screensaver alat saat tidak dipakai.

- **Data per pengumuman:** judul (≤ 40 huruf), isi (≤ 160 huruf), ikon (`info`, `pengumuman`, `kalender`, `jam`, `peringatan`, `rapat`, `libur`, `selamat`, `kesehatan`, `buku`), aktif/tidak, urutan.
- **`GET /announcements`:** kirim **maks. 10** pengumuman aktif sesuai urutan, ditambah `interval` (detik per pengumuman, 2–60) dan `idle` (screensaver muncul setelah diam, 5–600) dari Pengaturan.
- **`config.announcements_rev`:** teks bebas yang **berubah setiap pengumuman diubah**, misalnya `"<jumlah aktif>-<waktu ubah terakhir>"`. Alat membandingkannya di setiap heartbeat dan mengambil ulang daftar dalam ± 1 menit.
- Pakai huruf latin biasa. Font alat tidak mendukung emoji.

### 6.6 Pengaturan

| Pengaturan | Dikirim sebagai | Aturan | Bawaan |
|---|---|---|---|
| **API key** | (dicek di header `X-API-Key`) | 8–64 karakter. **Mengganti API key memutus semua alat** sampai API key di setiap alat ikut diganti | – |
| Judul layar utama | `config.title` | 1–30 karakter | nama instansi |
| Layar meredup setelah | `config.dim_after` | `0` (tidak pernah) atau 10–3600 detik | 60 |
| Kecerahan saat redup | `config.dim_level` | 0–100 % | 20 |
| Lama tiap pengumuman | `interval` di `/announcements` | 2–60 detik | 3 |
| Screensaver muncul setelah | `idle` di `/announcements` | 5–600 detik | 30 |
| Jeda tap ganda | (aturan bisnis `/tap`) | tap ulang dalam jeda ini = `duplicate` | 60 detik |
| Jam restart bawaan | `config.restart_at` untuk alat tanpa jam khusus | `"HH:MM"` atau `""` | `"03:00"` |

---

## 7. Server contoh & API admin contoh

### 7.1 Menjalankan

| | PHP 8 + SQLite | Node.js 18+ (tanpa `npm install`) |
|---|---|---|
| File | [contoh-integrasi/php/index.php](../contoh-integrasi/php/index.php) | [contoh-integrasi/node/server.js](../contoh-integrasi/node/server.js) |
| Jalankan | `cd contoh-integrasi/php && php -S 0.0.0.0:8080 index.php` | `cd contoh-integrasi/node && node server.js` |
| Data | `data.sqlite` (dibuat otomatis) | `data.json` (dibuat otomatis) |

Di alat: Base URL `http://<IP-laptop>:8080`, API key `ganti-dengan-kunci-anda`. Kedua server berperilaku **sama persis**, jadi pilih bahasa yang paling dekat dengan aplikasi Anda.

### 7.2 API admin contoh

Server contoh tidak punya halaman web. Keenam fitur wajib disediakan sebagai **API JSON** supaya mudah ditiru di framework apa pun, dan diuji lewat Postman. Semua request memakai header **`X-Admin-Key: ganti-kunci-admin`**. Ini **contoh, bukan kewajiban**: aplikasi Anda bebas memakai halaman web, API, atau keduanya.

| Fitur | Endpoint |
|---|---|
| Pengaturan | `GET /admin/settings` · `PUT /admin/settings` |
| Anggota | `GET /admin/members` · `POST /admin/members` · `PUT /admin/members/{rfid}` · `DELETE /admin/members/{rfid}` |
| **Kartu belum terdaftar** | `GET /admin/unknown-cards` |
| **Rekap** | `GET /admin/report?from=YYYY-MM-DD&to=YYYY-MM-DD` (tambah `&format=csv` untuk CSV) · `GET /admin/attendances?date=YYYY-MM-DD` |
| **Alat** | `GET /admin/devices` · `GET /admin/devices/{device_id}` (beserta riwayat) · `PUT /admin/devices/{device_id}` (nama, PIN, jam restart, firmware tujuan) |
| **Firmware** | `GET /admin/firmware` · `POST /admin/firmware?version=1.6.0&notes=…` (isi body = file `.bin`) · `DELETE /admin/firmware/{versi}` · `POST /admin/firmware/{versi}/apply-all` |
| **Pengumuman** | `GET /admin/announcements` · `POST /admin/announcements` · `PUT /admin/announcements/{id}` · `DELETE /admin/announcements/{id}` |

Aturan umum:
- **401:** admin key salah.
- **422:** validasi gagal, berisi `message` dan `errors` per field.
- **404:** data tidak ada.
- **201:** data baru dibuat.

Rincian field dan contoh request/respons ada di [contoh-integrasi/README.md](../contoh-integrasi/README.md) dan di setiap request Postman.

### 7.3 Server acuan Laravel

Kalau Anda membangun di atas Laravel, atau ingin langsung memakai yang sudah jadi, [api/](../api/README.md) menyediakan semua fitur di atas dalam panel admin web:

| Fitur wajib | Menu di panel admin Laravel |
|---|---|
| Kartu belum terdaftar | **Kartu belum terdaftar** |
| Rekap | **Rekap** (+ unduh CSV), **Kehadiran** |
| Alat | **Alat** (status, kesehatan, riwayat, PIN, jam restart, update firmware) |
| Firmware | **Firmware** |
| Pengumuman | **Pengumuman** (termasuk lama tampil & waktu munculnya screensaver) |
| Pengaturan | **Pengaturan** (API key, judul layar, layar redup, password admin) |

---

## 8. Menguji dengan Postman & Newman

File di [contoh-integrasi/postman/](../contoh-integrasi/postman/):

| File | Isi |
|---|---|
| `AbsensiRFID.postman_collection.json` | Semua request beserta tes otomatis |
| `AbsensiRFID.postman_environment.json` | Variabel: `base_url`, `api_key`, `device_id`, `admin_url`, `admin_key`, nomor kartu contoh |
| `contoh-firmware.bin` | File firmware palsu kecil untuk menguji alur unggah/unduh firmware (bukan firmware sungguhan) |

**Struktur koleksi:**
- **1. API alat:** wajib lulus untuk **semua** aplikasi. Isinya `/ping`, `/tap` (semua status, tap ganda, antrean), `/heartbeat` (kesehatan, crash, OTA gagal), `/announcements`, dan unduh firmware.
- **2. Admin contoh:** menguji keenam fitur di server contoh PHP/Node. Untuk aplikasi Anda sendiri, folder ini menjadi gambaran skenario yang harus bisa dilakukan admin.

**Cara pakai di Postman:** Import kedua file → pilih environment **Absensi RFID Terintegrasi** → sesuaikan `base_url` dan `api_key` → klik kanan koleksi → **Run collection**. (Untuk uji unggah firmware di folder 2, atur *Settings → Working directory* Postman ke folder `contoh-integrasi/postman`.)

**Cara pakai otomatis (Newman, cocok untuk CI):**
```bash
npx newman run contoh-integrasi/postman/AbsensiRFID.postman_collection.json \
  -e contoh-integrasi/postman/AbsensiRFID.postman_environment.json \
  --env-var base_url=http://localhost:8080 --env-var api_key=ganti-dengan-kunci-anda \
  --working-dir contoh-integrasi/postman --delay-request 50
```
Untuk aplikasi Anda sendiri yang tidak memakai API admin contoh, jalankan folder 1 saja: tambahkan `--folder "1. API alat"`.

**Pemeriksa cepat tanpa Postman:** `bash contoh-integrasi/tes/cek-server.sh <BASE_URL> <API_KEY> <RFID_TERDAFTAR>`. Skrip ini berpura-pura menjadi alat dan memeriksa semua aturan wajib.

> Semua tes **mengirim data sungguhan** (tap, heartbeat). Jalankan di server uji, bukan di server produksi.

---

## 9. Checklist siap pakai

**API alat**
- [ ] `cek-server.sh` lulus semua, dan Postman folder **1. API alat** hijau semua.
- [ ] Balasan `/tap` < 1 detik dari jaringan lokasi (cek di monitor serial alat: `Tap: … ms`).
- [ ] Tap saat internet dicabut tampil "TERSIMPAN", lalu masuk ke rekap dengan **jam asli** setelah internet pulih.

**Enam fitur wajib**
- [ ] **Kartu belum terdaftar:** kartu baru muncul, bisa didaftarkan, lalu hilang dari daftar.
- [ ] **Rekap:** masuk/pulang benar, unduh CSV bisa dibuka di Excel.
- [ ] **Alat:** status online/offline benar. Saat RC522 dicabut, muncul peringatan E30 dalam ± 1 menit.
- [ ] **Firmware:** unggah versi baru, jadwalkan ke 1 alat, alat terpasang versi baru dan status menjadi "Sudah terpasang".
- [ ] **Pengumuman:** ubah judul pengumuman, alat menampilkan yang baru dalam ± 1 menit.
- [ ] **Pengaturan:** ubah judul layar, judul di alat berubah dalam ± 1 menit.

**Keamanan & operasional**
- [ ] HTTPS di server publik, dan API key bukan nilai contoh.
- [ ] Halaman/API admin dilindungi login.
- [ ] Backup database terjadwal.
- [ ] Zona waktu server benar (WIB/WITA/WIT).

---

## 10. Masalah yang sering terjadi

| Gejala di alat | Kemungkinan penyebab di aplikasi |
|---|---|
| **E21** API key salah | API key di alat ≠ di aplikasi, atau header `X-API-Key` tidak dibaca (beberapa server membuang header ber-underscore/tertentu) |
| **E23** Alamat API salah | Base URL salah. Harus diakhiri jalur API tanpa `/` di akhir, misalnya `https://app.anda.com/api/absensi`. Atau redirect http→https: alat tidak mengikuti redirect, jadi pakai `https://` langsung |
| **E25** Balasan tidak valid | Aplikasi membalas HTML (halaman login, error PHP) atau JSON tanpa `"ok": true` |
| **E26** Server terlalu lama | `/tap` lambat. Cek query, indeks `rfid` & `tap_id`, atau server yang tidur (cold start) |
| Tap tercatat dua kali | `tap_id` tidak dicek |
| Jam di rekap salah setelah internet putus | `tapped_at` untuk `queued: true` tidak dipakai |
| Pengumuman tidak berubah | `config.announcements_rev` tidak ikut berubah |
| Update firmware tidak jalan | `url` beda origin dengan Base URL (API key tidak terkirim → 401), `Content-Length` tidak ada, ada redirect, atau MD5/ukuran salah |
| Alat selalu "Offline" padahal jalan | `last_seen_at` hanya diperbarui di `/heartbeat` tapi heartbeat gagal (lihat E2x), atau perbedaan zona waktu saat membandingkan waktu |
