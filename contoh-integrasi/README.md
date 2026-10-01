# Contoh Integrasi Alat Absensi Tap

Folder ini berisi **contoh server** yang bisa langsung dihubungkan ke alat absensi RFID, plus **skrip pemeriksa** untuk menguji server buatan Anda sendiri. Tujuannya supaya Anda paham cara kerjanya dalam beberapa menit, lalu menyalin bagian yang dibutuhkan ke aplikasi Anda.

Tanpa framework, tanpa `npm install`/`composer`, tanpa langkah build.

```
contoh-integrasi/
├── php/index.php        ← PHP 8 + SQLite, satu file
├── node/server.js       ← Node.js 18+, hanya modul bawaan, data di file JSON
├── tes/cek-server.sh    ← pemeriksa kesesuaian API (bash + curl + python3)
└── postman/             ← koleksi + environment Postman (semua endpoint & kasus)
```

Spesifikasi lengkap (acuan resmi): [../doc/spesifikasi-api.md](../doc/spesifikasi-api.md)

Request dan respons lengkap untuk **setiap endpoint dan setiap kasus**: [bagian 7](#7-daftar-endpoint--kasus). Uji dengan Postman: [bagian 8](#8-menguji-dengan-postman).

---

## 1. Ringkasan: apa yang dikirim dan diharapkan alat

Alat memanggil 3 endpoint wajib (plus 1 opsional untuk screensaver) di bawah satu **Base URL**. Setiap permintaan membawa header:

| Header | Isi |
|---|---|
| `X-API-Key` | API key yang diisi di alat. Kunci salah → server wajib membalas **HTTP 401** |
| `X-Device-ID` | ID alat, contoh `ABS-1A2B3C`. Kalau berbeda dengan `device_id` di body, yang dipakai header |
| `X-Spec-Version` | Versi spesifikasi, saat ini `1` (boleh diabaikan) |

| Endpoint | Kapan | Balasan minimal |
|---|---|---|
| `GET {base}/ping` | Tombol "Tes koneksi" & sinkron jam | `{"ok": true, "server_time": "2026-09-30T07:45:12+07:00"}` |
| `POST {base}/heartbeat` | Tiap 60 detik | `{"ok": true, "server_time": "..."}` |
| `POST {base}/tap` | Kartu ditempelkan | `{"ok": true, "status": "check_in", "name": "Budi", "message": "Selamat datang"}` |
| `GET {base}/announcements` *(opsional)* | Pengumuman untuk screensaver | `{"ok": true, "interval": 3, "idle": 30, "items": [{"id": "1", "title": "Rapat Guru", "description": "…", "icon": "rapat"}]}` |

Isi `POST /tap` dari alat:

```json
{ "device_id": "ABS-1A2B3C", "tap_id": "1A2B3C-5F3A9C21", "rfid": "0218893066",
  "tapped_at": "2026-09-30T07:45:12+07:00", "queued": false, "raw": { ... } }
```

Nilai `status` yang dikenali alat:

| `status` | `ok` | Tampilan di alat |
|---|---|---|
| `check_in` | `true` | **MASUK** (hijau) |
| `check_out` | `true` | **PULANG** (biru) |
| `duplicate` | `true` | **SUDAH TERCATAT** (kuning) |
| `unknown` | `false` | **KARTU TIDAK TERDAFTAR** + nomor kartu (merah) |
| `rejected` | `false` | **DITOLAK**, alasan di `message` (merah) |

Hal penting lainnya:
- Semua hasil bisnis (termasuk kartu tidak dikenal dan `duplicate`) dibalas **HTTP 200**. Request yang formatnya salah (misal `rfid` kosong, body bukan JSON, atau tanpa header `X-Device-ID`) dibalas **HTTP 400** dengan `message`. Kode lain dianggap galat. Server boleh membalas **429** kalau request terlalu sering; alat mencoba lagi nanti.
- Alat menunggu maksimal **8 detik**.
- Kalau server tidak bisa dihubungi, alat menyimpan tap lalu mengirimnya belakangan dengan `"queued": true`. Untuk tap seperti ini, pakai **`tapped_at`** sebagai waktu absen. Kalau `tapped_at` bernilai `null`, pakai waktu diterima.
- **`tap_id` unik per tap** dan tetap sama kalau tap itu dikirim ulang (misalnya balasan server lewat 8 detik padahal tap sudah tersimpan). Simpan `tap_id`. Kalau `tap_id` sudah ada, jangan catat lagi, cukup balas hasil yang sama (atau `duplicate`).
- `raw` bisa berupa objek atau `null` dan boleh diabaikan.
- Opsional: `photo_url` (JPEG baseline, maks. 160×160 px, maks. 30 KB), `info` (maks. 2 baris), dan `config` (`title` maks. 30 karakter, `pin` per alat, `dim_after` & `dim_level` untuk layar redup, `announcements_rev`, `restart_at` jam restart harian `"HH:MM"` atau `""` = tidak restart otomatis) di balasan `/ping` dan `/heartbeat`.
- Opsional: **pengumuman screensaver** lewat `GET /announcements`. Saat alat tidak dipakai, layar menampilkan pengumuman bergantian (maks. 10; judul maks. 40 karakter, deskripsi maks. 160; ikon salah satu dari `info`, `pengumuman`, `kalender`, `jam`, `peringatan`, `rapat`, `libur`, `selamat`, `kesehatan`, `buku`). Alat mengambil ulang daftar setiap `config.announcements_rev` berubah (atau paling lambat tiap 10 menit). Kalau endpoint ini tidak ada (404), screensaver tidak tampil.

## 2. Menjalankan contoh

Kedua contoh memakai API key `ganti-dengan-kunci-anda` (ubah konstanta `API_KEY` di bagian atas file) dan port **8080**.

### PHP (PHP 8)

```bash
cd contoh-integrasi/php
php -S 0.0.0.0:8080 index.php
```

`data.sqlite` beserta tabel `karyawan`, `absensi`, dan `alat` dibuat otomatis, lengkap dengan 3 karyawan contoh (1 nonaktif).

### Node.js (versi 18 ke atas)

```bash
cd contoh-integrasi/node
node server.js                # atau: PORT=9000 node server.js
```

`data.json` dibuat otomatis dengan 3 karyawan contoh (1 nonaktif).

### Karyawan contoh

| rfid | nama |
|---|---|
| `0218893066` | Budi Santoso |
| `0012345678` | Siti Aminah |
| `0055555555` | Rina Kurnia (**nonaktif**, untuk contoh `rejected`) |

### Aturan bisnis di contoh

Aturan ini ada di fungsi `tentukanStatus()`. Silakan ubah sesuai kebutuhan.

1. Kartu tidak ada di tabel karyawan → `unknown`
2. Karyawan `aktif = 0/false` → `rejected` dengan pesan "Kartu nonaktif"
3. Tap ulang dalam 60 detik sejak tap terakhir yang diterima → `duplicate`
4. Tap pertama hari itu → `check_in`
5. Tap berikutnya di hari yang sama → `check_out` (dengan `info` "Masuk tadi HH:MM")

### Pengumuman di contoh

Daftar pengumuman screensaver ditulis langsung di konstanta `PENGUMUMAN` (2 contoh: "Rapat Guru" dan "Libur Nasional"), dengan `interval` 3 detik dan `idle` 30 detik. Setelah mengubah daftar itu, **ganti juga nilai `PENGUMUMAN_REV`** (teks bebas, misalnya `'2'`) supaya alat tahu ada perubahan dan mengambil ulang dalam ±1 menit. Di aplikasi sungguhan, daftar ini biasanya diambil dari database dan `announcements_rev` dibuat dari jumlah data + waktu perubahan terakhir.

### PIN per alat di contoh

`config.pin` dikirim per alat lewat konstanta `PIN_ALAT` (kunci = `X-Device-ID` yang tampil di layar alat, nilai = PIN 4–8 digit). Bawaannya kosong, jadi PIN alat tidak diubah (tetap `2026` atau yang diganti di alat). Contoh: `'ABS-79438C' => '4321'` (PHP) atau `'ABS-79438C': '4321'` (Node).

### Jam restart harian di contoh

`config.restart_at` = jam (menurut jam di layar alat, format 24 jam `"HH:MM"`) saat alat me-restart dirinya sekali sehari ketika sedang tidak dipakai. Contoh mengirim `'03:00'` untuk semua alat; isi `''` (teks kosong) supaya alat tidak restart otomatis. Di aplikasi sungguhan, nilai ini sebaiknya diatur per alat dan dipilih jam sepi.

Semua tap dicatat di `absensi`, termasuk yang `unknown`, jadi nomor kartu baru mudah dicari. Respons tiap tap juga disimpan, sehingga kalau `tap_id` yang sama datang lagi, server cukup mengirim respons lama tanpa menambah baris.

## 3. Menghubungkan alat ke server

1. Pastikan laptop dan alat berada di **jaringan WiFi yang sama** (alat hanya bisa memakai WiFi **2.4 GHz**).
2. Cari IP laptop:
   - macOS: `ipconfig getifaddr en0`
   - Linux: `hostname -I`
   - Windows: `ipconfig` (lihat "IPv4 Address")
3. Di alat, buka **Pengaturan** (PIN bawaan `2026`) → **Server**, lalu isi:
   - **Base URL**: `http://<ip-laptop>:8080`, contoh `http://192.168.1.10:8080`
   - **API key**: `ganti-dengan-kunci-anda`
4. Tekan **Tes koneksi**. Layar akan menampilkan "Terhubung ke Aplikasi Contoh", dan judul layar utama berubah mengikuti `config.title`.
5. Tempelkan kartu.

Kalau tes koneksi gagal, periksa firewall laptop (izinkan port 8080) dan pastikan Base URL tidak diakhiri `/ping`.

## 4. Mendaftarkan kartu

**Cara termudah untuk tahu nomor kartu:** tempelkan kartu ke alat. Kartu yang belum terdaftar tampil sebagai **KARTU TIDAK TERDAFTAR** beserta nomornya, dan nomor itu juga tercatat di data absensi.

### PHP (SQLite)

```bash
cd contoh-integrasi/php

# Nomor kartu yang pernah ditempel tapi belum terdaftar
sqlite3 data.sqlite "SELECT DISTINCT rfid FROM absensi WHERE jenis = 'unknown';"

# Tambah karyawan
sqlite3 data.sqlite "INSERT INTO karyawan (rfid, nama) VALUES ('1234567890', 'Andi Wijaya');"

# Tambah foto (opsional, JPEG baseline maks. 160x160 px & 30 KB)
sqlite3 data.sqlite "UPDATE karyawan SET foto_url = 'http://192.168.1.10/foto/andi.jpg' WHERE rfid = '1234567890';"

# Nonaktifkan kartu (tap akan DITOLAK)
sqlite3 data.sqlite "UPDATE karyawan SET aktif = 0 WHERE rfid = '1234567890';"

# Lihat 20 absensi terakhir dan status alat
sqlite3 -header -column data.sqlite "SELECT waktu, rfid, jenis, queued FROM absensi ORDER BY id DESC LIMIT 20;"
sqlite3 -header -column data.sqlite "SELECT device_id, terakhir_terlihat, firmware, ip, rssi FROM alat;"
```

Anda juga bisa memakai aplikasi grafis seperti *DB Browser for SQLite*.

### Node.js (JSON)

Edit `node/data.json`, lalu tambahkan objek ke `karyawan`:

```json
{ "rfid": "1234567890", "nama": "Andi Wijaya", "foto_url": null, "aktif": true }
```

File ini dibaca ulang di setiap permintaan, jadi server tidak perlu di-restart.

## 5. Menguji server Anda dengan `cek-server.sh`

Skrip ini berperan seperti alat. Ia memanggil `/ping`, `/heartbeat`, `/tap`, dan `/announcements`, lalu memeriksa kode HTTP serta field JSON. Tap yang diuji:
- tap biasa;
- tap yang sama dikirim ulang dengan `tap_id` yang sama (balasan harus sama persis atau `duplicate`);
- tap antrean `queued: true` dengan `tapped_at` kemarin 07:30;
- tap antrean `queued: true` dengan `tapped_at: null` dan `raw: null` (jam alat belum tersinkron);
- kartu tak terdaftar (harus `unknown`).

Skrip juga memeriksa bahwa `ok` sesuai `status`, `config.title` (kalau ada) maks. 30 karakter, `config.dim_after` (kalau ada) 0 atau 10–3600, `config.dim_level` (kalau ada) 0–100, `config.restart_at` (kalau ada) teks `""` atau `"HH:MM"` 00:00–23:59, API key yang salah dibalas **401**, dan request yang formatnya salah dibalas **400**.

`GET /announcements` bersifat opsional: kalau server membalas **404**, skrip hanya menampilkan ⚠️ "tidak disediakan (opsional)". Kalau dibalas **200**, formatnya diperiksa (`items` berupa daftar, `title` wajib, `id` berupa teks, `interval` 2–60, `idle` 5–600).

```bash
cd contoh-integrasi/tes
./cek-server.sh BASE_URL API_KEY [RFID_TERDAFTAR]

# contoh
./cek-server.sh http://localhost:8080 ganti-dengan-kunci-anda 0218893066
./cek-server.sh https://app.contoh.com/api/absensi rahasia-kantor-123 0012345678
```

Untuk memastikan kiriman ulang benar-benar **tidak menambah baris** di database, isi `HITUNG_DATA` dengan perintah yang mencetak jumlah baris absensi di server Anda:

```bash
# server PHP bawaan
HITUNG_DATA="sqlite3 ../php/data.sqlite 'SELECT COUNT(*) FROM absensi'" \
  ./cek-server.sh http://localhost:8080 ganti-dengan-kunci-anda

# server Node bawaan
HITUNG_DATA="python3 -c \"import json; print(len(json.load(open('../node/data.json'))['absensi']))\"" \
  ./cek-server.sh http://localhost:8080 ganti-dengan-kunci-anda

# aplikasi Anda (contoh MySQL)
HITUNG_DATA="mysql -N -e 'SELECT COUNT(*) FROM absensi' nama_db" ./cek-server.sh ...
```

Kebutuhannya hanya `bash`, `curl`, dan `python3` (tidak perlu `jq`). Setiap pemeriksaan ditandai ✅ atau ❌, sedangkan ⚠️ berarti saran yang tidak wajib. Kode keluar skrip sama dengan jumlah pemeriksaan yang gagal (`0` = lulus semua), jadi bisa dipakai di CI.

> Skrip ini mengirim tap sungguhan (kartu `9999999990` dan RFID yang Anda berikan). Jalankan di server uji, bukan di data produksi.

## 6. Checklist integrasi ke aplikasi Anda

- [ ] **Salin 3 handler** (`ping`, `heartbeat`, `tap`, plus `announcements` kalau perlu) dari salah satu contoh ke route aplikasi Anda, di bawah satu Base URL, misalnya `/api/absensi/ping`, `/api/absensi/tap`, `/api/absensi/heartbeat`.
- [ ] **Periksa `X-API-Key`** di awal setiap handler. Kalau salah, balas **HTTP 401**. Simpan kunci di konfigurasi, bukan di kode.
- [ ] **Tambahkan kolom nomor kartu** (teks 10 digit, contoh `"0218893066"`) di tabel karyawan/siswa Anda, lalu cocokkan dengan `rfid`. Simpan sebagai **teks**, bukan angka, supaya nol di depan tidak hilang.
- [ ] **Tentukan aturan masuk/pulang** (jam kerja, shift, terlambat, jeda duplikat) di satu fungsi seperti `tentukanStatus()`.
- [ ] **Balas `status`** dengan salah satu nilai `check_in`, `check_out`, `duplicate` (`ok: true`) atau `unknown`, `rejected` (`ok: false`), ditambah `message` (±32 karakter) dan `name`. Semua dengan **HTTP 200**.
- [ ] **Simpan `tap_id`** (kolom unik). Kalau `tap_id` sudah pernah dicatat, jangan simpan lagi, cukup balas hasil sebelumnya (atau `duplicate`).
- [ ] **Pakai `tapped_at`** untuk tap `queued: true`. Kalau `tapped_at` bernilai `null`, pakai waktu diterima.
- [ ] Ambil ID alat dari header `X-Device-ID`. Request yang formatnya salah dibalas **HTTP 400** dengan `message`.
- [ ] Kirim `server_time` (ISO 8601 dengan zona waktu) di `/ping` dan `/heartbeat` supaya jam alat tepat.
- [ ] Pastikan balasan selalu **di bawah 8 detik**.
- [ ] (Opsional) `photo_url`, `info`, `config.title`, `config.pin` per alat (sesuai `X-Device-ID`), `config.dim_after` / `config.dim_level` (layar redup), `config.restart_at` (jam restart harian per alat, `""` = tidak restart otomatis), dan status "alat aktif" (heartbeat terakhir kurang dari ±3 menit).
- [ ] (Opsional) `GET /announcements` untuk screensaver pengumuman, plus `config.announcements_rev` yang berubah setiap daftarnya berubah.
- [ ] Jalankan `tes/cek-server.sh` terhadap server Anda sampai semua ✅.

### Catatan saat memasang contoh PHP di hosting (Apache/Nginx)

- Handler mengenali endpoint dari bagian **terakhir** path, jadi Base URL seperti `https://domain.com/absensi/index.php` juga bisa dipakai (alat akan memanggil `.../index.php/ping`).
- Letakkan `data.sqlite` **di luar folder publik** (ubah path di `new PDO(...)`) atau blokir aksesnya, supaya file database tidak bisa diunduh orang lain.
- Folder tempat `data.sqlite` berada harus bisa ditulis oleh web server.

## 7. Daftar endpoint & kasus

Semua request dan respons di bawah ini **persis** seperti yang dikirim alat dan dibalas server contoh (PHP/Node), dengan:
- Base URL `http://localhost:8080`, API key `ganti-dengan-kunci-anda`, ID alat `ABS-1A2B3C`;
- `tap_id` selalu baru untuk setiap tap (kecuali kasus kirim ulang), `tapped_at` = jam alat saat kartu ditempel;
- teks `message` bebas (server acuan `api/` memakai teks sedikit berbeda, misal "Sampai jumpa", "Kartu belum terdaftar"). Urutan kunci JSON bebas.

Urutan kasus `/tap` sama dengan urutan di koleksi Postman, karena hasilnya saling bergantung (masuk → kirim ulang → duplicate → pulang).

### `GET /ping`

#### 1. Ping – berhasil

Tombol "Tes koneksi" di alat. `config.pin` hanya dikirim kalau server mengatur PIN untuk alat ini. Contoh 429: server boleh membatasi jumlah request; alat menganggapnya gagal dan mencoba lagi nanti (tidak dipicu oleh koleksi ini).

Request:

```http
GET http://localhost:8080/ping
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-1A2B3C
X-Spec-Version: 1
Accept: application/json
```

Respons – Berhasil:

```http
HTTP 200 OK
Content-Type: application/json

{
  "ok": true,
  "message": "Terhubung ke Aplikasi Contoh",
  "server_time": "2026-10-01T07:45:12+07:00",
  "config": {
    "title": "Aplikasi Contoh",
    "dim_after": 60,
    "dim_level": 20,
    "announcements_rev": "1",
    "restart_at": "03:00",
    "pin": "4321"
  }
}
```

Respons – Terlalu banyak request (429):

```http
HTTP 429 Too Many Requests
Retry-After: 30
Content-Type: application/json

{
  "ok": false,
  "message": "Terlalu banyak permintaan"
}
```

#### 2. Ping – API key salah (401)

API key salah wajib dibalas HTTP 401. Tap yang ditolak 401 tidak masuk antrean alat.

Request:

```http
GET http://localhost:8080/ping
X-API-Key: kunci-salah-xyz
X-Device-ID: ABS-1A2B3C
X-Spec-Version: 1
Accept: application/json
```

Respons:

```http
HTTP 401 Unauthorized
Content-Type: application/json

{
  "ok": false,
  "message": "API key salah"
}
```

#### 3. Ping – tanpa X-Device-ID (400)

Alat selalu mengirim X-Device-ID. Request tanpa header ini dijawab HTTP 400.

Request:

```http
GET http://localhost:8080/ping
X-API-Key: ganti-dengan-kunci-anda
X-Spec-Version: 1
Accept: application/json
```

Respons:

```http
HTTP 400 Bad Request
Content-Type: application/json

{
  "ok": false,
  "message": "Header X-Device-ID wajib"
}
```

### `POST /tap`

#### 4. Tap – masuk (check_in)

Tap pertama hari itu untuk kartu terdaftar. Tap biasa (`queued: false`) memakai jam server.

Request:

```http
POST http://localhost:8080/tap
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-1A2B3C
X-Spec-Version: 1
Accept: application/json
Content-Type: application/json

{
  "device_id": "ABS-1A2B3C",
  "tap_id": "1A2B3C-5F3A9C21",
  "rfid": "0218893066",
  "tapped_at": "2026-10-01T07:45:12+07:00",
  "queued": false,
  "raw": {
    "uid_hex": "0A0B0C0D",
    "wifi_ssid": "Kantor-2.4G",
    "rssi": -52,
    "ip": "192.168.1.23",
    "firmware": "1.1.0",
    "uptime_s": 3600,
    "server_status": "online"
  }
}
```

Respons:

```http
HTTP 200 OK
Content-Type: application/json

{
  "ok": true,
  "status": "check_in",
  "message": "Selamat datang",
  "name": "Budi Santoso",
  "time": "07:45",
  "photo_url": null
}
```

#### 5. Tap – kirim ulang tap_id yang sama (tidak dicatat dua kali)

Tap yang sama terkirim lagi dari antrean (misal balasan pertama lewat 8 detik). `tap_id` sama dengan kasus "Tap – masuk". Server tidak boleh mencatat baris baru: balas respons yang sama, atau `duplicate`.

Request:

```http
POST http://localhost:8080/tap
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-1A2B3C
X-Spec-Version: 1
Accept: application/json
Content-Type: application/json

{
  "device_id": "ABS-1A2B3C",
  "tap_id": "1A2B3C-5F3A9C21",
  "rfid": "0218893066",
  "tapped_at": "2026-10-01T07:45:12+07:00",
  "queued": true,
  "raw": {
    "uid_hex": "0A0B0C0D",
    "wifi_ssid": "Kantor-2.4G",
    "rssi": -52,
    "ip": "192.168.1.23",
    "firmware": "1.1.0",
    "uptime_s": 3600,
    "server_status": "online"
  }
}
```

Respons – Sama seperti tap awal:

```http
HTTP 200 OK
Content-Type: application/json

{
  "ok": true,
  "status": "check_in",
  "message": "Selamat datang",
  "name": "Budi Santoso",
  "time": "07:45",
  "photo_url": null
}
```

Respons – Atau duplicate (server acuan):

```http
HTTP 200 OK
Content-Type: application/json

{
  "ok": true,
  "status": "duplicate",
  "message": "Sudah tercatat",
  "name": "Budi Santoso",
  "time": "07:45"
}
```

#### 6. Tap – sudah tercatat (duplicate, < 60 detik)

Kartu yang sama ditempel lagi kurang dari 60 detik setelah tap yang diterima. `time` = jam tap sebelumnya. (Jeda 60 detik adalah aturan contoh, boleh diubah.)

Request:

```http
POST http://localhost:8080/tap
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-1A2B3C
X-Spec-Version: 1
Accept: application/json
Content-Type: application/json

{
  "device_id": "ABS-1A2B3C",
  "tap_id": "1A2B3C-5F3A9C22",
  "rfid": "0218893066",
  "tapped_at": "2026-10-01T07:45:40+07:00",
  "queued": false,
  "raw": {
    "uid_hex": "0A0B0C0D",
    "wifi_ssid": "Kantor-2.4G",
    "rssi": -52,
    "ip": "192.168.1.23",
    "firmware": "1.1.0",
    "uptime_s": 3600,
    "server_status": "online"
  }
}
```

Respons:

```http
HTTP 200 OK
Content-Type: application/json

{
  "ok": true,
  "status": "duplicate",
  "message": "Sudah tercatat",
  "name": "Budi Santoso",
  "time": "07:45",
  "photo_url": null
}
```

#### 7. Tap – pulang (check_out), menunggu 61 detik

Tap berikutnya di hari yang sama (lebih dari 60 detik setelah tap diterima). `info` maks. 2 baris. Di Postman, request ini menunggu 61 detik sebelum dikirim.

Request:

```http
POST http://localhost:8080/tap
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-1A2B3C
X-Spec-Version: 1
Accept: application/json
Content-Type: application/json

{
  "device_id": "ABS-1A2B3C",
  "tap_id": "1A2B3C-5F3AA001",
  "rfid": "0218893066",
  "tapped_at": "2026-10-01T16:05:03+07:00",
  "queued": false,
  "raw": {
    "uid_hex": "0A0B0C0D",
    "wifi_ssid": "Kantor-2.4G",
    "rssi": -52,
    "ip": "192.168.1.23",
    "firmware": "1.1.0",
    "uptime_s": 3600,
    "server_status": "online"
  }
}
```

Respons:

```http
HTTP 200 OK
Content-Type: application/json

{
  "ok": true,
  "status": "check_out",
  "message": "Hati-hati di jalan",
  "name": "Budi Santoso",
  "time": "16:05",
  "photo_url": null,
  "info": [
    "Masuk tadi 07:45"
  ]
}
```

#### 8. Tap – antrean dengan tapped_at (queued)

Tap yang tersimpan di alat saat server mati, dikirim belakangan dengan `queued: true`. Pakai `tapped_at` (di sini kemarin 07:30) sebagai waktu absen. Respons tap antrean tidak ditampilkan alat; alat hanya melihat kode HTTP (2xx/3xx/4xx = dihapus dari antrean, 5xx/timeout = dicoba lagi). Server boleh memakai waktu diterima kalau `tapped_at` lebih dari 5 menit di masa depan atau lebih lama dari 30 hari.

Request:

```http
POST http://localhost:8080/tap
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-1A2B3C
X-Spec-Version: 1
Accept: application/json
Content-Type: application/json

{
  "device_id": "ABS-1A2B3C",
  "tap_id": "1A2B3C-5F3A8F10",
  "rfid": "0218893066",
  "tapped_at": "2026-09-30T07:30:00+07:00",
  "queued": true,
  "raw": {
    "uid_hex": "0A0B0C0D",
    "wifi_ssid": "Kantor-2.4G",
    "rssi": -52,
    "ip": "192.168.1.23",
    "firmware": "1.1.0",
    "uptime_s": 3600,
    "server_status": "online"
  }
}
```

Respons:

```http
HTTP 200 OK
Content-Type: application/json

{
  "ok": true,
  "status": "check_in",
  "message": "Selamat datang",
  "name": "Budi Santoso",
  "time": "07:30",
  "photo_url": null
}
```

#### 9. Tap – antrean dengan tapped_at null

Jam alat belum tersinkron saat kartu ditempel, jadi `tapped_at` = `null`. Server memakai waktu diterima. Status bisa apa saja sesuai aturan bisnis.

Request:

```http
POST http://localhost:8080/tap
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-1A2B3C
X-Spec-Version: 1
Accept: application/json
Content-Type: application/json

{
  "device_id": "ABS-1A2B3C",
  "tap_id": "1A2B3C-00000A31",
  "rfid": "0218893066",
  "tapped_at": null,
  "queued": true,
  "raw": {
    "uid_hex": "0A0B0C0D",
    "wifi_ssid": "Kantor-2.4G",
    "rssi": -52,
    "ip": "192.168.1.23",
    "firmware": "1.1.0",
    "uptime_s": 3600,
    "server_status": "online"
  }
}
```

Respons:

```http
HTTP 200 OK
Content-Type: application/json

{
  "ok": true,
  "status": "duplicate",
  "message": "Sudah tercatat",
  "name": "Budi Santoso",
  "time": "16:05",
  "photo_url": null
}
```

#### 10. Tap – kartu tidak terdaftar (unknown)

Tetap HTTP 200. Alat menampilkan KARTU TIDAK TERDAFTAR beserta nomor kartu.

Request:

```http
POST http://localhost:8080/tap
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-1A2B3C
X-Spec-Version: 1
Accept: application/json
Content-Type: application/json

{
  "device_id": "ABS-1A2B3C",
  "tap_id": "1A2B3C-5F3A9C30",
  "rfid": "9999999990",
  "tapped_at": "2026-10-01T07:47:02+07:00",
  "queued": false,
  "raw": {
    "uid_hex": "0A0B0C0D",
    "wifi_ssid": "Kantor-2.4G",
    "rssi": -52,
    "ip": "192.168.1.23",
    "firmware": "1.1.0",
    "uptime_s": 3600,
    "server_status": "online"
  }
}
```

Respons:

```http
HTTP 200 OK
Content-Type: application/json

{
  "ok": false,
  "status": "unknown",
  "message": "Kartu tidak terdaftar",
  "time": "07:47"
}
```

#### 11. Tap – kartu nonaktif (rejected)

Tetap HTTP 200. Alasan penolakan di `message` (misal "Kartu nonaktif", "Di luar jam kerja").

Request:

```http
POST http://localhost:8080/tap
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-1A2B3C
X-Spec-Version: 1
Accept: application/json
Content-Type: application/json

{
  "device_id": "ABS-1A2B3C",
  "tap_id": "1A2B3C-5F3A9C40",
  "rfid": "0055555555",
  "tapped_at": "2026-10-01T07:48:10+07:00",
  "queued": false,
  "raw": {
    "uid_hex": "0A0B0C0D",
    "wifi_ssid": "Kantor-2.4G",
    "rssi": -52,
    "ip": "192.168.1.23",
    "firmware": "1.1.0",
    "uptime_s": 3600,
    "server_status": "online"
  }
}
```

Respons:

```http
HTTP 200 OK
Content-Type: application/json

{
  "ok": false,
  "status": "rejected",
  "message": "Kartu nonaktif",
  "name": "Rina Kurnia",
  "time": "07:48",
  "photo_url": null
}
```

#### 12. Tap – raw null

`raw` boleh `null` (atau tidak ada). Server tidak boleh gagal karena itu.

Request:

```http
POST http://localhost:8080/tap
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-1A2B3C
X-Spec-Version: 1
Accept: application/json
Content-Type: application/json

{
  "device_id": "ABS-1A2B3C",
  "tap_id": "1A2B3C-5F3A9C50",
  "rfid": "9999999990",
  "tapped_at": "2026-10-01T07:49:00+07:00",
  "queued": false,
  "raw": null
}
```

Respons:

```http
HTTP 200 OK
Content-Type: application/json

{
  "ok": false,
  "status": "unknown",
  "message": "Kartu tidak terdaftar",
  "time": "07:49"
}
```

#### 13. Tap – rfid kosong (400)

Format salah dijawab HTTP 400 dengan `message`. Server acuan membalas "Nomor kartu tidak valid".

Request:

```http
POST http://localhost:8080/tap
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-1A2B3C
X-Spec-Version: 1
Accept: application/json
Content-Type: application/json

{
  "device_id": "ABS-1A2B3C",
  "tap_id": "1A2B3C-5F3A9C60",
  "rfid": "",
  "tapped_at": "2026-10-01T07:50:00+07:00",
  "queued": false,
  "raw": null
}
```

Respons:

```http
HTTP 400 Bad Request
Content-Type: application/json

{
  "ok": false,
  "message": "rfid kosong"
}
```

#### 14. Tap – body bukan JSON (400)

Body yang bukan JSON dijawab HTTP 400. Contoh PHP/Node membalas "rfid kosong", server acuan "Body harus berupa JSON".

Request:

```http
POST http://localhost:8080/tap
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-1A2B3C
X-Spec-Version: 1
Accept: application/json
Content-Type: application/json

ini-bukan-json
```

Respons:

```http
HTTP 400 Bad Request
Content-Type: application/json

{
  "ok": false,
  "message": "Body harus berupa JSON"
}
```

### `POST /heartbeat`

#### 15. Heartbeat – berhasil

Dikirim alat tiap 60 detik. Server cukup mencatat "terakhir terlihat" untuk X-Device-ID ini. Alat dianggap tidak aktif kalau tidak ada heartbeat lebih dari ±3 menit.

Request:

```http
POST http://localhost:8080/heartbeat
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-1A2B3C
X-Spec-Version: 1
Accept: application/json
Content-Type: application/json

{
  "device_id": "ABS-1A2B3C",
  "firmware": "1.1.0",
  "time": "2026-10-01T07:46:00+07:00",
  "raw": {
    "wifi_ssid": "Kantor-2.4G",
    "rssi": -52,
    "ip": "192.168.1.23",
    "uptime_s": 3660,
    "free_heap": 180000,
    "rfid_ok": true,
    "queue": 0
  }
}
```

Respons – Berhasil:

```http
HTTP 200 OK
Content-Type: application/json

{
  "ok": true,
  "server_time": "2026-10-01T07:46:01+07:00",
  "config": {
    "title": "Aplikasi Contoh",
    "dim_after": 60,
    "dim_level": 20,
    "announcements_rev": "1",
    "restart_at": "03:00",
    "pin": "4321"
  }
}
```

Respons – Terlalu banyak request (429):

```http
HTTP 429 Too Many Requests
Retry-After: 30
Content-Type: application/json

{
  "ok": false,
  "message": "Terlalu banyak permintaan"
}
```

#### 16. Heartbeat – time null dan raw null

`time` = `null` kalau jam alat belum tersinkron; `raw` boleh `null`. Kirim `server_time` supaya jam alat disetel.

Request:

```http
POST http://localhost:8080/heartbeat
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-1A2B3C
X-Spec-Version: 1
Accept: application/json
Content-Type: application/json

{
  "device_id": "ABS-1A2B3C",
  "firmware": "1.1.0",
  "time": null,
  "raw": null
}
```

Respons:

```http
HTTP 200 OK
Content-Type: application/json

{
  "ok": true,
  "server_time": "2026-10-01T07:47:01+07:00",
  "config": {
    "title": "Aplikasi Contoh",
    "dim_after": 60,
    "dim_level": 20,
    "announcements_rev": "1",
    "restart_at": "03:00",
    "pin": "4321"
  }
}
```

### `GET /announcements`

#### 17. Pengumuman (opsional)

Opsional, untuk screensaver. Isi balasan tergantung data di server, jadi request ini punya 3 contoh balasan: ada pengumuman, daftar kosong, dan 404 (tidak disediakan). Maks. 10 item, `title` maks. 40 karakter, `description` maks. 160. Kode ikon: info, pengumuman, kalender, jam, peringatan, rapat, libur, selamat, kesehatan, buku.

Request:

```http
GET http://localhost:8080/announcements
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-1A2B3C
X-Spec-Version: 1
Accept: application/json
```

Respons – Ada pengumuman:

```http
HTTP 200 OK
Content-Type: application/json

{
  "ok": true,
  "interval": 3,
  "idle": 30,
  "items": [
    {
      "id": "1",
      "title": "Rapat Guru",
      "description": "Hari ini pukul 13.00 di aula lantai 2.",
      "icon": "rapat"
    },
    {
      "id": "2",
      "title": "Libur Nasional",
      "description": "Kamis, 2 Oktober 2026 kantor tutup.",
      "icon": "libur"
    }
  ]
}
```

Respons – Daftar kosong (screensaver tidak tampil):

```http
HTTP 200 OK
Content-Type: application/json

{
  "ok": true,
  "interval": 3,
  "idle": 30,
  "items": []
}
```

Respons – Tidak disediakan (404):

```http
HTTP 404 Not Found
Content-Type: application/json

{
  "ok": false,
  "message": "Endpoint tidak ada"
}
```

## 8. Menguji dengan Postman

File di folder `postman/`:
- `AbsensiRFID.postman_collection.json`: semua kasus di bagian 7, satu request per kasus. Setiap request punya **contoh respons** (tab *Examples*) dan **tes otomatis** (kode HTTP dan field wajib). `tap_id` dibuat baru otomatis sebelum setiap request; kasus "kirim ulang" memakai `tap_id` dari kasus "Tap – masuk".
- `AbsensiRFID.postman_environment.json`: variabel `base_url`, `api_key`, `device_id`, `rfid_terdaftar`, `rfid_tidak_terdaftar`, `rfid_nonaktif`.

Cara pakai:
1. Postman → **Import** → pilih **kedua** file.
2. Pilih environment **Absensi RFID Terintegrasi** (pojok kanan atas), lalu sesuaikan nilainya.
3. Klik kanan koleksi → **Run collection** → **Run**. Jalankan seluruh koleksi dari atas (kasus "pulang" sengaja menunggu 61 detik).

Nilai bawaan environment langsung cocok dengan server contoh PHP/Node di bagian 2:

| Variabel | Nilai bawaan | Keterangan |
|---|---|---|
| `base_url` | `http://localhost:8080` | Base URL server, tanpa `/ping` di akhir |
| `api_key` | `ganti-dengan-kunci-anda` | sama dengan konstanta `API_KEY` di contoh |
| `device_id` | `ABS-TES001` | ID alat pura-pura; bebas |
| `rfid_terdaftar` / `rfid_tidak_terdaftar` / `rfid_nonaktif` | `0218893066` / `9999999990` / `0055555555` | karyawan contoh aktif / kartu asing / karyawan contoh nonaktif |

> ⚠️ Kasus tap membuat **baris absensi sungguhan** dan heartbeat mengubah status alat `device_id`. Untuk menguji server lain (misalnya server acuan `api/` di `http://localhost:8133/api/absensi`, atau aplikasi Anda sendiri), ganti `base_url` dan `api_key`, isi `rfid_terdaftar` dengan kartu anggota aktif, dan `rfid_nonaktif` dengan kartu anggota yang dinonaktifkan. Pakai server uji, bukan data produksi.

Catatan:
- Kasus **"Tap – masuk"** hanya lulus kalau kartu `rfid_terdaftar` **belum tap hari ini**. Untuk mengulang di server contoh, hentikan server, hapus `php/data.sqlite` atau `node/data.json`, lalu jalankan lagi.
- Balasan **429** dan **404 pengumuman** tidak dipicu oleh koleksi; bentuknya ada sebagai contoh respons.
- Dari terminal (opsional, dijalankan di folder `contoh-integrasi/`): `npx newman run postman/AbsensiRFID.postman_collection.json -e postman/AbsensiRFID.postman_environment.json` (nilai bisa ditimpa, misal `--env-var base_url=http://localhost:9000`).
