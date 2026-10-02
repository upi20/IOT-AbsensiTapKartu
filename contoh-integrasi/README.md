# Contoh Integrasi Absensi RFID Terintegrasi

Folder ini untuk **pengembang aplikasi** (sistem sekolah, HRIS, ERP, dan sebagainya) yang ingin memakai alat absensi RFID. Isinya server contoh yang **sudah lengkap** (API alat + 6 fitur wajib), skrip pemeriksa, dan koleksi Postman. Jalankan dulu, lihat cara kerjanya, lalu salin polanya ke aplikasi Anda.

Tanpa framework, tanpa `npm install`/`composer`, tanpa langkah build.

```
contoh-integrasi/
├── php/index.php        ← PHP 8 + SQLite, satu file
├── node/server.js       ← Node.js 18+, hanya modul bawaan, data di file JSON
├── tes/cek-server.sh    ← pemeriksa API alat (bash + curl + python3)
└── postman/             ← koleksi + environment Postman, plus contoh-firmware.bin (firmware palsu untuk uji)
```

Baca juga:

| Dokumen | Isi |
|---|---|
| [doc/integrasi-aplikasi.md](../doc/integrasi-aplikasi.md) | **Panduan integrasi**: 6 fitur wajib, data yang perlu disimpan, urutan kerja, checklist |
| [doc/spesifikasi-api.md](../doc/spesifikasi-api.md) | **Kontrak teknis** alat ↔ aplikasi: endpoint, header, format JSON, kode HTTP |

## Daftar isi

1. [Langkah cepat](#1-langkah-cepat)
2. [Menjalankan server contoh](#2-menjalankan-server-contoh)
3. [Menghubungkan alat](#3-menghubungkan-alat)
4. [Enam fitur wajib di server contoh](#4-enam-fitur-wajib-di-server-contoh)
5. [Referensi API admin](#5-referensi-api-admin)
6. [Menguji API alat dengan `cek-server.sh`](#6-menguji-api-alat-dengan-cek-serversh)
7. [Menguji dengan Postman & Newman](#7-menguji-dengan-postman--newman)
8. [Checklist integrasi](#8-checklist-integrasi)
9. [Catatan hosting](#9-catatan-hosting)
10. [Daftar endpoint & kasus API alat](#10-daftar-endpoint--kasus-api-alat)

---

## 1. Langkah cepat

```bash
# 1) Jalankan salah satu server contoh (dari folder utama repo)
cd contoh-integrasi/php && php -S 0.0.0.0:8080 index.php
#    atau:  cd contoh-integrasi/node && node server.js

# 2) Di terminal lain: tes koneksi seperti yang dilakukan alat
curl -H "X-API-Key: ganti-dengan-kunci-anda" -H "X-Device-ID: ABS-TES001" http://localhost:8080/ping

# 3) Lihat pengaturan lewat API admin
curl -H "X-Admin-Key: ganti-kunci-admin" http://localhost:8080/admin/settings

# 4) Uji semuanya (API alat + 6 fitur wajib), dari folder utama repo
npx newman run contoh-integrasi/postman/AbsensiRFID.postman_collection.json \
  -e contoh-integrasi/postman/AbsensiRFID.postman_environment.json \
  --env-var base_url=http://localhost:8080 --working-dir contoh-integrasi/postman
```

Lalu sambungkan alat sungguhan ([bagian 3](#3-menghubungkan-alat)) dan tempelkan kartu.

---

## 2. Menjalankan server contoh

Kedua server berperilaku sama: endpoint, status HTTP, dan bentuk JSON-nya identik (hanya teks pesan galat yang sedikit berbeda). Pilih bahasa yang paling dekat dengan aplikasi Anda.

### PHP 8 + SQLite

```bash
cd contoh-integrasi/php
php -S 0.0.0.0:8080 index.php
```

Butuh ekstensi `pdo_sqlite` (bawaan di hampir semua instalasi PHP). Database `data.sqlite` dan tabelnya dibuat otomatis; database versi lama dilengkapi kolom barunya saat server jalan.

### Node.js 18+

```bash
cd contoh-integrasi/node
node server.js                # port lain: PORT=9000 node server.js
```

Data disimpan di `data.json` (dibuat otomatis, dibaca ulang setiap request, jadi boleh diedit tanpa restart).

### Yang dibuat otomatis

| Data | Isi awal |
|---|---|
| Anggota | `0218893066` Budi Santoso (aktif), `0012345678` Siti Aminah (aktif), `0055555555` Rina Kurnia (**nonaktif**, untuk contoh `rejected`) |
| Pengumuman | "Rapat Guru" (ikon `rapat`) dan "Libur Nasional" (ikon `libur`), keduanya aktif |
| Pengaturan | `api_key` `ganti-dengan-kunci-anda`, `title` "Aplikasi Contoh", `dim_after` 60, `dim_level` 20, `screensaver_interval` 3, `screensaver_idle` 30, `duplicate_window` 60, `default_restart_at` "03:00" |
| Alat | terdaftar otomatis saat sebuah `X-Device-ID` pertama kali menghubungi server |
| Firmware | file hasil unggah disimpan di folder `firmware/` di samping server |

`data.sqlite`, `data.json`, dan folder `firmware/` diabaikan git. Untuk mengulang dari awal: hentikan server, hapus file/folder itu, jalankan lagi.

### Yang perlu Anda ganti

| Konstanta (di bagian atas file) | Bawaan | Keterangan |
|---|---|---|
| `ADMIN_KEY` | `ganti-kunci-admin` | Kunci API admin (header `X-Admin-Key`). **Ganti** sebelum dipasang di jaringan mana pun |
| Zona waktu | WIB | PHP: `date_default_timezone_set('Asia/Jakarta')`; Node: `ZONA_JAM = 7` (WITA 8, WIT 9) |
| `PORT` (Node) | 8080 | atau variabel lingkungan `PORT` |

Pengaturan lain (API key alat, judul layar, dan seterusnya) diubah lewat `PUT /admin/settings`, bukan di kode.

### Aturan bisnis `/tap` di contoh

Ada di fungsi `tentukanStatus()`. Silakan ubah sesuai aturan instansi Anda.

1. Kartu tidak ada di anggota → `unknown` (tap tetap dicatat, muncul di **Kartu belum terdaftar**)
2. Anggota nonaktif → `rejected` dengan pesan "Kartu nonaktif"
3. Tap ulang dalam `duplicate_window` detik (bawaan 60) sejak tap terakhir yang diterima → `duplicate`
4. Tap pertama hari itu → `check_in`
5. Tap berikutnya di hari yang sama → `check_out` (dengan `info` "Masuk tadi HH:MM")

Tap antrean (`queued: true`) memakai `tapped_at` dari alat. `tap_id` yang sama dikirim ulang → server membalas respons yang dulu tanpa mencatat baris baru.

---

## 3. Menghubungkan alat

1. Pastikan laptop dan alat berada di **jaringan WiFi yang sama** (alat hanya bisa memakai WiFi **2.4 GHz**).
2. Cari IP laptop:
   - macOS: `ipconfig getifaddr en0`
   - Linux: `hostname -I`
   - Windows: `ipconfig` (lihat "IPv4 Address")
3. Di alat, buka **Pengaturan** (PIN bawaan `2026`) → **Server**, lalu isi:
   - **Base URL**: `http://<ip-laptop>:8080`, contoh `http://192.168.1.10:8080` (tanpa `/` dan tanpa `/ping` di akhir)
   - **API key**: nilai `api_key` di pengaturan, awalnya `ganti-dengan-kunci-anda`
4. Tekan **Tes koneksi**. Layar menampilkan "Terhubung ke Aplikasi Contoh", dan judul layar utama mengikuti `config.title`.
5. Tempelkan kartu. Kartu yang belum terdaftar tampil **KARTU TIDAK TERDAFTAR** beserta nomornya, lalu muncul di `GET /admin/unknown-cards`.

Kalau tes koneksi gagal, periksa firewall laptop (izinkan port 8080). Server contoh juga menerima awalan path, jadi Base URL `http://192.168.1.10:8080/api/absensi` pun bisa dipakai.

---

## 4. Enam fitur wajib di server contoh

Server contoh tidak punya halaman web. Keenam fitur wajib (lihat [panduan integrasi bagian 2](../doc/integrasi-aplikasi.md#2-fitur-wajib)) disediakan sebagai **API JSON admin** supaya mudah ditiru di framework apa pun. Aplikasi Anda bebas memakai halaman web, API, atau keduanya.

| Fitur wajib | API admin contoh | Yang terlihat di alat |
|---|---|---|
| **Kartu belum terdaftar** | `GET /admin/unknown-cards` → daftarkan dengan `POST /admin/members` | Kartu tak dikenal: KARTU TIDAK TERDAFTAR; setelah didaftarkan: MASUK |
| **Rekap** | `GET /admin/report` (JSON atau `format=csv`), `GET /admin/attendances` | – |
| **Alat** | `GET /admin/devices`, `GET /admin/devices/{id}`, `PUT /admin/devices/{id}` | `config.pin`, `config.restart_at` |
| **Firmware** | `GET/POST /admin/firmware`, `DELETE /admin/firmware/{versi}`, `POST /admin/firmware/{versi}/apply-all`, target per alat lewat `PUT /admin/devices/{id}` | `config.firmware_update` + unduhan `GET /firmware/{versi}` |
| **Pengumuman** | `GET/POST /admin/announcements`, `PUT/DELETE /admin/announcements/{id}` | `config.announcements_rev`, isi `GET /announcements` |
| **Pengaturan** | `GET/PUT /admin/settings` | `config.title`, `config.dim_after`, `config.dim_level`, `interval`/`idle` di `/announcements`, API key |
| *(pendukung)* Anggota | `GET/POST /admin/members`, `PUT/DELETE /admin/members/{rfid}` | nama & foto di layar saat tap |

Koleksi Postman folder **"2. Admin contoh"** menjalankan semua fitur ini sebagai satu skenario berurutan (ubah pengaturan → kelola anggota → daftarkan kartu baru → rekap → kelola alat → update firmware → pengumuman → kembalikan pengaturan), lengkap dengan pemeriksaan bahwa perubahan benar-benar sampai ke alat.

---

## 5. Referensi API admin

### 5.0 Aturan umum

- **Alamat:** `http://<server>:8080/admin/...`. Awalan apa pun sebelum `/admin/` juga diterima, misalnya `/api/absensi/admin/settings`.
- **Header wajib:** `X-Admin-Key: ganti-kunci-admin` (konstanta `ADMIN_KEY`). Body request berupa JSON (`Content-Type: application/json`), kecuali unggah firmware.
- **Body sebagian:** `PUT` hanya mengubah field yang dikirim.
- **ID:** `id` pengumuman dan kehadiran di API admin berupa **angka**; di API alat (`GET /announcements`) `id` berupa **teks**.
- **Waktu:** ISO 8601 dengan offset, contoh `2026-10-02T07:15:00+07:00`.

| HTTP | Arti | Bentuk |
|---|---|---|
| 200 | Berhasil | `{"ok": true, ...}` |
| 201 | Data baru dibuat (`POST`) | `{"ok": true, ...}` |
| 400 | Body bukan objek JSON `{...}` | `{"ok": false, "message": "..."}` |
| 401 | `X-Admin-Key` salah atau kosong | `{"ok": false, "message": "Admin key salah"}` |
| 404 | Data atau endpoint tidak ada | `{"ok": false, "message": "..."}` |
| 413 | Body JSON lebih dari 1 MB | `{"ok": false, "message": "..."}` |
| 422 | Validasi gagal. `message` = galat field pertama, `errors` = semua galat per field | `{"ok": false, "message": "...", "errors": {"field": "..."}}` |

Contoh galat umum (respons asli server contoh PHP):

```bash
curl http://localhost:8080/admin/settings                      # tanpa X-Admin-Key
```
```json
{
  "ok": false,
  "message": "Admin key salah"
}
```
```bash
curl -X PUT http://localhost:8080/admin/settings -H "X-Admin-Key: ganti-kunci-admin" \
  -H "Content-Type: application/json" -d '[1, 2, 3]'           # body bukan objek
```
```json
{
  "ok": false,
  "message": "Body harus JSON berupa objek {...}"
}
```
```bash
curl http://localhost:8080/admin/tidak-ada -H "X-Admin-Key: ganti-kunci-admin"
```
```json
{
  "ok": false,
  "message": "Endpoint tidak ada"
}
```

> Semua contoh respons di bagian ini adalah **keluaran asli** server contoh PHP saat koleksi Postman dijalankan (Node membalas bentuk yang sama). Daftar yang panjang dipotong.

### 5.1 Pengaturan

#### `GET /admin/settings`

```bash
curl http://localhost:8080/admin/settings -H "X-Admin-Key: ganti-kunci-admin"
```
```json
{
  "ok": true,
  "settings": {
    "api_key": "ganti-dengan-kunci-anda",
    "title": "Aplikasi Contoh",
    "dim_after": 60,
    "dim_level": 20,
    "screensaver_interval": 3,
    "screensaver_idle": 30,
    "duplicate_window": 60,
    "default_restart_at": "03:00"
  }
}
```

#### `PUT /admin/settings`

| Field | Aturan | Dikirim ke alat sebagai |
|---|---|---|
| `title` | teks 1-30 karakter | `config.title` (+ `message` di `/ping`) |
| `dim_after` | bilangan bulat `0` atau 10-3600 (detik) | `config.dim_after` |
| `dim_level` | bilangan bulat 0-100 (persen) | `config.dim_level` |
| `screensaver_interval` | bilangan bulat 2-60 (detik) | `interval` di `/announcements` |
| `screensaver_idle` | bilangan bulat 5-600 (detik) | `idle` di `/announcements` |
| `duplicate_window` | bilangan bulat 0-3600 (detik) | aturan `duplicate` di `/tap` |
| `default_restart_at` | `"HH:MM"` atau `""` (mati) | `config.restart_at` untuk alat tanpa jam restart sendiri |
| `api_key` | 8-64 karakter `A-Z a-z 0-9 . _ -` | dicek di header `X-API-Key`. **Mengganti ini memutus semua alat** sampai API key di setiap alat ikut diganti |

```bash
curl -X PUT http://localhost:8080/admin/settings -H "X-Admin-Key: ganti-kunci-admin" \
  -H "Content-Type: application/json" \
  -d '{"title": "Postman Uji", "dim_after": 120, "dim_level": 35, "screensaver_interval": 5, "screensaver_idle": 45, "duplicate_window": 0, "default_restart_at": "04:15"}'
```
```json
{
  "ok": true,
  "settings": {
    "api_key": "ganti-dengan-kunci-anda",
    "title": "Postman Uji",
    "dim_after": 120,
    "dim_level": 35,
    "screensaver_interval": 5,
    "screensaver_idle": 45,
    "duplicate_window": 0,
    "default_restart_at": "04:15"
  }
}
```

Satu field salah → tidak ada yang disimpan (422). Contoh body `{"title": "", "dim_after": 5, "dim_level": 101, "screensaver_interval": 1, "screensaver_idle": 700, "duplicate_window": -1, "default_restart_at": "25:00", "api_key": "pendek"}`:

```json
{
  "ok": false,
  "message": "Judul wajib diisi, 1-30 karakter",
  "errors": {
    "title": "Judul wajib diisi, 1-30 karakter",
    "dim_after": "dim_after harus 0 atau 10-3600 (detik)",
    "dim_level": "dim_level harus 0-100 (persen)",
    "screensaver_interval": "screensaver_interval harus 2-60 (detik)",
    "screensaver_idle": "screensaver_idle harus 5-600 (detik)",
    "duplicate_window": "duplicate_window harus 0-3600 (detik)",
    "default_restart_at": "default_restart_at harus \"HH:MM\" (00:00-23:59) atau \"\" (mati)",
    "api_key": "API key harus 8-64 karakter: huruf, angka, titik, garis bawah, atau tanda minus"
  }
}
```

Perubahan sampai ke alat di balasan `/ping` atau `/heartbeat` berikutnya:

```json
{
  "ok": true,
  "message": "Terhubung ke Postman Uji",
  "server_time": "2026-10-02T08:34:45+07:00",
  "config": {
    "title": "Postman Uji",
    "dim_after": 120,
    "dim_level": 35,
    "announcements_rev": "2-1790904822",
    "restart_at": "04:15"
  }
}
```

### 5.2 Anggota

Dipakai untuk mendaftarkan kartu (termasuk dari daftar Kartu belum terdaftar).

| Field | Aturan |
|---|---|
| `rfid` | wajib saat tambah; dinormalkan (spasi dibuang, huruf besar); maks. 32 karakter `0-9 A-F`; harus belum terdaftar. Tidak bisa diubah |
| `name` | wajib, 1-60 karakter |
| `photo_url` | URL `http(s)://`, `null`, atau `""` (= `null`). JPEG baseline maks. 160×160 px & 30 KB |
| `active` | `true`/`false` (bawaan `true`). `false` → tap dijawab `rejected` |

#### `GET /admin/members`

```bash
curl http://localhost:8080/admin/members -H "X-Admin-Key: ganti-kunci-admin"
```
```json
{
  "ok": true,
  "members": [
    {
      "rfid": "0218893066",
      "name": "Budi Santoso",
      "photo_url": null,
      "active": true
    },
    {
      "rfid": "0055555555",
      "name": "Rina Kurnia",
      "photo_url": null,
      "active": false
    },
    {
      "rfid": "0012345678",
      "name": "Siti Aminah",
      "photo_url": null,
      "active": true
    }
  ]
}
```

#### `POST /admin/members` → 201

```bash
curl -X POST http://localhost:8080/admin/members -H "X-Admin-Key: ganti-kunci-admin" \
  -H "Content-Type: application/json" \
  -d '{"rfid": "ab1a0fa3fdd3f", "name": "Postman Anggota", "photo_url": "https://contoh.com/foto/postman-160.jpg"}'
```
```json
{
  "ok": true,
  "member": {
    "rfid": "AB1A0FA3FDD3F",
    "name": "Postman Anggota",
    "photo_url": "https://contoh.com/foto/postman-160.jpg",
    "active": true
  }
}
```

Galat (422), body `{"rfid": "XYZ-123", "name": "", "photo_url": "ftp://contoh.com/a.jpg", "active": "ya"}`:

```json
{
  "ok": false,
  "message": "Nomor kartu hanya boleh angka 0-9 dan huruf A-F, maks. 32 karakter",
  "errors": {
    "rfid": "Nomor kartu hanya boleh angka 0-9 dan huruf A-F, maks. 32 karakter",
    "name": "Nama wajib diisi, maks. 60 karakter",
    "photo_url": "photo_url harus alamat http:// atau https:// (atau null)",
    "active": "active harus true atau false"
  }
}
```
```json
{
  "ok": false,
  "message": "Nomor kartu AB1A0FA3FDD3F sudah terdaftar",
  "errors": {
    "rfid": "Nomor kartu AB1A0FA3FDD3F sudah terdaftar"
  }
}
```

#### `PUT /admin/members/{rfid}`

```bash
curl -X PUT http://localhost:8080/admin/members/AB1A0FA3FDD3F -H "X-Admin-Key: ganti-kunci-admin" \
  -H "Content-Type: application/json" \
  -d '{"name": "Postman Anggota (diubah)", "photo_url": null, "active": false}'
```
```json
{
  "ok": true,
  "member": {
    "rfid": "AB1A0FA3FDD3F",
    "name": "Postman Anggota (diubah)",
    "photo_url": null,
    "active": false
  }
}
```

#### `DELETE /admin/members/{rfid}`

```bash
curl -X DELETE http://localhost:8080/admin/members/AB1A0FA3FDD3F -H "X-Admin-Key: ganti-kunci-admin"
```
```json
{
  "ok": true
}
```

Nomor kartu yang tidak ada → 404:

```json
{
  "ok": false,
  "message": "Anggota tidak ditemukan"
}
```

### 5.3 Kartu belum terdaftar

#### `GET /admin/unknown-cards`

Nomor kartu yang pernah di-tap dengan hasil `unknown` dan **sekarang** belum ada di anggota, urut `last_seen_at` terbaru. Begitu didaftarkan lewat `POST /admin/members`, kartu itu hilang dari daftar dan tap berikutnya langsung dikenali.

```bash
curl http://localhost:8080/admin/unknown-cards -H "X-Admin-Key: ganti-kunci-admin"
```
```json
{
  "ok": true,
  "cards": [
    {
      "rfid": "9904884543",
      "taps": 2,
      "first_seen_at": "2026-10-02T08:34:46+07:00",
      "last_seen_at": "2026-10-02T08:34:46+07:00",
      "last_device_id": "PM-MUQAJ0VZ"
    },
    {
      "rfid": "9999999990",
      "taps": 2,
      "first_seen_at": "2026-10-02T08:34:43+07:00",
      "last_seen_at": "2026-10-02T08:34:43+07:00",
      "last_device_id": "ABS-TES001"
    }
  ]
}
```

| Field | Isi |
|---|---|
| `rfid` | nomor kartu |
| `taps` | jumlah tap `unknown` |
| `first_seen_at`, `last_seen_at` | tap pertama & terakhir |
| `last_device_id` | alat tempat kartu terakhir ditempel |

Alur "Daftarkan" di halaman admin: ambil `rfid` dari daftar ini → `POST /admin/members {"rfid": "...", "name": "..."}`.

### 5.4 Rekap & kehadiran

#### `GET /admin/report?from=YYYY-MM-DD&to=YYYY-MM-DD[&format=csv]`

Per anggota per hari: `check_in` = tap `check_in` pertama, `check_out` = tap `check_out` terakhir, `duration_minutes` = selisihnya, `taps` = jumlah tap `check_in`/`check_out`/`duplicate`. Urut tanggal lalu nama. Tanpa `from`/`to` = hari ini. Rentang maks. **92 hari** (termasuk kedua ujung).

```bash
curl "http://localhost:8080/admin/report?from=2026-10-02&to=2026-10-02" -H "X-Admin-Key: ganti-kunci-admin"
```
```json
{
  "ok": true,
  "from": "2026-10-02",
  "to": "2026-10-02",
  "rows": [
    {
      "date": "2026-10-02",
      "rfid": "0218893066",
      "name": "Budi Santoso",
      "check_in": "08:33",
      "check_out": "08:34",
      "duration_minutes": 1,
      "taps": 4
    },
    {
      "date": "2026-10-02",
      "rfid": "9904884543",
      "name": "Postman Kartu Baru",
      "check_in": "08:34",
      "check_out": "08:34",
      "duration_minutes": 0,
      "taps": 2
    }
  ]
}
```

CSV (bisa dibuka di Excel):

```bash
curl -OJ "http://localhost:8080/admin/report?from=2026-10-02&to=2026-10-02&format=csv" -H "X-Admin-Key: ganti-kunci-admin"
# tersimpan sebagai rekap-2026-10-02-2026-10-02.csv
```
```csv
tanggal,rfid,nama,masuk,pulang,durasi_menit,jumlah_tap
2026-10-02,0218893066,Budi Santoso,08:33,08:34,1,4
2026-10-02,9904884543,Postman Kartu Baru,08:34,08:34,0,2
```

Galat (422):

```json
{
  "ok": false,
  "message": "Rentang rekap maksimal 92 hari",
  "errors": {
    "to": "Rentang rekap maksimal 92 hari"
  }
}
```
```json
{
  "ok": false,
  "message": "from harus tanggal YYYY-MM-DD",
  "errors": {
    "from": "from harus tanggal YYYY-MM-DD",
    "to": "to harus tanggal YYYY-MM-DD"
  }
}
```

#### `GET /admin/attendances?date=YYYY-MM-DD`

Semua tap pada satu tanggal (bawaan hari ini), termasuk `unknown`/`rejected`, terbaru dulu.

```bash
curl "http://localhost:8080/admin/attendances?date=2026-10-02" -H "X-Admin-Key: ganti-kunci-admin"
```
```json
{
  "ok": true,
  "date": "2026-10-02",
  "items": [
    {
      "id": 13,
      "time": "2026-10-02T08:34:46+07:00",
      "rfid": "9904884543",
      "name": "Postman Kartu Baru",
      "status": "check_out",
      "device_id": "PM-MUQAJ0VZ",
      "queued": false,
      "tap_id": "PM-1A0FA3FE512-A96D95"
    },
    {
      "id": 12,
      "time": "2026-10-02T08:34:46+07:00",
      "rfid": "9904884543",
      "name": "Postman Kartu Baru",
      "status": "check_in",
      "device_id": "PM-MUQAJ0VZ",
      "queued": false,
      "tap_id": "PM-1A0FA3FE4C2-537253"
    },
    {
      "id": 11,
      "time": "2026-10-02T08:34:46+07:00",
      "rfid": "9904884543",
      "name": "Postman Kartu Baru",
      "status": "unknown",
      "device_id": "PM-MUQAJ0VZ",
      "queued": false,
      "tap_id": "PM-1A0FA3FE38E-DBBB1F"
    }
  ]
}
```

`name` = nama anggota sekarang (kalau anggotanya sudah dihapus: nama saat tap, atau `null`). `queued: true` = tap dikirim belakangan dari antrean alat; `time`-nya jam tap asli.

### 5.5 Alat

Alat terdaftar otomatis saat `X-Device-ID` baru pertama kali memanggil API alat. Setiap request alat memperbarui `last_seen_at` (dan `firmware`/`ip`/`rssi`/`wifi_ssid` kalau dikirim); `/heartbeat` juga menyimpan kesehatan dari `raw`.

#### `GET /admin/devices`

Semua alat, urut `last_seen_at` terbaru.

```bash
curl http://localhost:8080/admin/devices -H "X-Admin-Key: ganti-kunci-admin"
```
```json
{
  "ok": true,
  "devices": [
    {
      "device_id": "PM-MUQAJ0VZ",
      "name": null,
      "online": true,
      "last_seen_at": "2026-10-02T08:34:47+07:00",
      "firmware": "1.5.0",
      "ip": "192.168.1.50",
      "rssi": -55,
      "wifi_ssid": "Kantor-2.4G",
      "uptime_s": 3600,
      "reset_reason": "poweron",
      "rfid_ok": true,
      "queue": 0,
      "free_heap": 181000,
      "min_free_heap": 150000,
      "error": null,
      "ota_failed": null,
      "pin": null,
      "restart_at": "04:15",
      "firmware_target": null,
      "firmware_state": null,
      "issues": []
    }
  ]
}
```

| Field | Isi |
|---|---|
| `device_id`, `name` | ID dari header `X-Device-ID`; nama bebas dari admin |
| `online` | ada request dari alat dalam 180 detik terakhir |
| `last_seen_at` | request terakhir |
| `firmware`, `ip`, `rssi`, `wifi_ssid` | dari body/`raw` request alat |
| `uptime_s`, `reset_reason`, `rfid_ok`, `queue`, `free_heap`, `min_free_heap`, `error`, `ota_failed` | dari `raw` heartbeat terakhir (tidak dikirim → `null`) |
| `pin` | PIN menu khusus alat ini (`null` = tidak dikirim ke alat) |
| `restart_at` | jam restart yang **berlaku** (milik alat, atau `default_restart_at`), `""` = mati |
| `firmware_target`, `firmware_state` | versi tujuan update; `null` / `pending` / `installed` / `failed` |
| `tap_mode` | mode absen yang dikirim server ke alat (`config.tap_mode`): `null` (ikuti pengaturan di alat), `"auto"`, atau `"select"` |
| `reported_tap_mode`, `tap_select` | dilaporkan alat lewat heartbeat (firmware ≥ 1.6.0): mode yang sedang dipakai dan pilihan saat ini (`check_in`/`check_out`/`null`) |
| `issues` | peringatan siap tampil (tabel di bawah) |

`issues` yang mungkin muncul:

| Kondisi | Pesan |
|---|---|
| tidak ada request > 600 detik | `Offline sejak <waktu>` |
| `rfid_ok` = `false` | `Pembaca RFID tidak terdeteksi (E30)` |
| `rssi` < −80 | `Sinyal WiFi lemah (-85 dBm)` |
| `queue` > 20 | `25 tap menunggu di antrean alat` |
| `error` terisi | `<kode> <arti>`, mis. `E31 Jam belum sinkron` (E10-E31, lihat spesifikasi bagian 5) |
| `ota_failed` terisi | `Update firmware 1.4.9 gagal, alat kembali ke 1.5.0` |
| ≥ 3 boot `watchdog`/`panic`/`brownout` dalam 24 jam | `Sering restart tidak normal: 3x dalam 24 jam` |
| `min_free_heap` < 20000 | `RAM hampir habis (15000 byte)` |

Contoh alat bermasalah (setelah heartbeat dengan `rfid_ok:false`, `rssi:-85`, `queue:25`, `error:"E31"`, `min_free_heap:15000`, `ota_failed:"1.4.9"`):

```json
{
  "ok": true,
  "device": {
    "device_id": "PM-MUQAJ0VZ",
    "name": "Lobi depan (Postman)",
    "online": true,
    "last_seen_at": "2026-10-02T08:34:48+07:00",
    "firmware": "1.5.0",
    "ip": "192.168.1.50",
    "rssi": -85,
    "wifi_ssid": "Kantor-2.4G",
    "uptime_s": 3660,
    "reset_reason": "poweron",
    "rfid_ok": false,
    "queue": 25,
    "free_heap": 30000,
    "min_free_heap": 15000,
    "error": "E31",
    "ota_failed": "1.4.9",
    "pin": null,
    "restart_at": "04:15",
    "firmware_target": null,
    "firmware_state": null,
    "issues": [
      "Pembaca RFID tidak terdeteksi (E30)",
      "Sinyal WiFi lemah (-85 dBm)",
      "25 tap menunggu di antrean alat",
      "E31 Jam belum sinkron",
      "Update firmware 1.4.9 gagal, alat kembali ke 1.5.0",
      "RAM hampir habis (15000 byte)"
    ]
  },
  "events": [
    {
      "type": "ota_failed",
      "message": "Update firmware 1.4.9 gagal, alat kembali ke 1.5.0",
      "details": {
        "version": "1.4.9",
        "firmware": "1.5.0"
      },
      "created_at": "2026-10-02T08:34:48+07:00"
    }
  ]
}
```

#### `GET /admin/devices/{device_id}`

Satu alat + `events` (20 kejadian terbaru, terbaru dulu). Jenis kejadian:

| `type` | Dicatat saat |
|---|---|
| `boot` | `uptime_s` lebih kecil dari heartbeat sebelumnya, atau lebih kecil dari jeda sejak heartbeat sebelumnya. Pesan: `Menyala ulang: <alasan>` |
| `crash` | `raw.crash` dikirim (yang sama dalam 10 menit tidak dicatat dua kali). `details` = isi `raw.crash` |
| `firmware` | versi firmware berubah: `Firmware 1.5.0 -> 9.9.9` |
| `ota_failed` | `raw.ota_failed` baru |

Maks. 200 kejadian disimpan per alat.

```bash
curl http://localhost:8080/admin/devices/PM-MUQAJ0VZ -H "X-Admin-Key: ganti-kunci-admin"
```
```json
{
  "ok": true,
  "device": {
    "device_id": "PM-MUQAJ0VZ",
    "name": "Lobi depan (Postman)",
    "online": true,
    "last_seen_at": "2026-10-02T08:34:48+07:00",
    "firmware": "1.5.0",
    "ip": "192.168.1.50",
    "rssi": -55,
    "wifi_ssid": "Kantor-2.4G",
    "uptime_s": 70,
    "reset_reason": "watchdog",
    "rfid_ok": true,
    "queue": 0,
    "free_heap": 181000,
    "min_free_heap": 150000,
    "error": null,
    "ota_failed": null,
    "pin": null,
    "restart_at": "04:15",
    "firmware_target": null,
    "firmware_state": null,
    "issues": []
  },
  "events": [
    {
      "type": "crash",
      "message": "Program crash di loopTask (PC 0x400d4634)",
      "details": {
        "task": "loopTask",
        "pc": "0x400d4634",
        "backtrace": "0x400d4634 0x400880ed",
        "elf": "3f2a9c1b"
      },
      "created_at": "2026-10-02T08:34:48+07:00"
    },
    {
      "type": "boot",
      "message": "Menyala ulang: watchdog (program macet)",
      "details": {
        "reset_reason": "watchdog",
        "uptime_s": 12
      },
      "created_at": "2026-10-02T08:34:48+07:00"
    },
    {
      "type": "ota_failed",
      "message": "Update firmware 1.4.9 gagal, alat kembali ke 1.5.0",
      "details": {
        "version": "1.4.9",
        "firmware": "1.5.0"
      },
      "created_at": "2026-10-02T08:34:48+07:00"
    }
  ]
}
```

`device_id` yang tidak ada → 404 `{"ok": false, "message": "Alat tidak ditemukan"}`.

#### `PUT /admin/devices/{device_id}`

| Field | Aturan | Dikirim ke alat |
|---|---|---|
| `name` | teks maks. 40 karakter; `""`/`null` = kosong | – |
| `pin` | teks 4-8 angka; `""`/`null` = hapus (alat memakai PIN terakhirnya) | `config.pin` |
| `restart_at` | `"HH:MM"`, `""` = tidak restart otomatis, `null` = ikut `default_restart_at` | `config.restart_at` |
| `firmware_target` | versi yang ada di `GET /admin/firmware`, atau `null` = tidak update | `config.firmware_update` |
| `tap_mode` | `null` (ikuti alat), `"auto"`, atau `"select"`; nilai lain → 422 | `config.tap_mode` |

```bash
curl -X PUT http://localhost:8080/admin/devices/PM-MUQAJ0VZ -H "X-Admin-Key: ganti-kunci-admin" \
  -H "Content-Type: application/json" \
  -d '{"name": "Lobi depan (Postman)", "pin": "4321", "restart_at": "05:30"}'
```
```json
{
  "ok": true,
  "device": {
    "device_id": "PM-MUQAJ0VZ",
    "name": "Lobi depan (Postman)",
    "online": true,
    "last_seen_at": "2026-10-02T08:34:47+07:00",
    "firmware": "1.5.0",
    "ip": "192.168.1.50",
    "rssi": -55,
    "wifi_ssid": "Kantor-2.4G",
    "uptime_s": 3600,
    "reset_reason": "poweron",
    "rfid_ok": true,
    "queue": 0,
    "free_heap": 181000,
    "min_free_heap": 150000,
    "error": null,
    "ota_failed": null,
    "pin": "4321",
    "restart_at": "05:30",
    "firmware_target": null,
    "firmware_state": null,
    "issues": []
  }
}
```

Galat (422), body `{"name": "<41 huruf>", "pin": "12", "restart_at": "25:00", "firmware_target": "0.0.1"}`:

```json
{
  "ok": false,
  "message": "Nama alat maks. 40 karakter",
  "errors": {
    "name": "Nama alat maks. 40 karakter",
    "pin": "PIN harus 4-8 angka (atau \"\" / null untuk menghapus)",
    "restart_at": "restart_at harus \"HH:MM\" (00:00-23:59), \"\" (mati), atau null (bawaan)",
    "firmware_target": "Versi firmware tidak ada. Unggah dulu lewat POST /admin/firmware"
  }
}
```

Balasan `/ping` alat itu sesudahnya:

```json
{
  "ok": true,
  "message": "Terhubung ke Postman Uji",
  "server_time": "2026-10-02T08:34:47+07:00",
  "config": {
    "title": "Postman Uji",
    "dim_after": 120,
    "dim_level": 35,
    "announcements_rev": "2-1790904822",
    "restart_at": "05:30",
    "pin": "4321"
  }
}
```

### 5.6 Firmware

Alur: unggah `.bin` → jadwalkan ke **satu** alat (`PUT /admin/devices/{id}` `firmware_target`) → alat menerima `config.firmware_update` di `/ping`/`/heartbeat` berikutnya → alat mengunduh, memasang, restart, lalu melapor versi baru di heartbeat → `firmware_state` = `installed`. Kalau berhasil, baru terapkan ke semua alat.

#### `POST /admin/firmware?version=X.Y.Z&notes=teks` → 201

Body = **isi file `.bin` mentah** (hasil `./upload.sh -b`, lihat README utama), `Content-Type: application/octet-stream`.

| Aturan | Galat |
|---|---|
| `version` format `angka.angka.angka` dan belum pernah diunggah | 422 `errors.version` |
| ukuran 1-1966080 byte (slot OTA `min_spiffs`) | 422 `errors.file` |
| byte pertama `0xE9` (image aplikasi ESP32, bukan file gabungan/bootloader) | 422 `errors.file` |
| teks versi ada di dalam file (mencegah salah label) | 422 `errors.file` |

```bash
curl -X POST "http://localhost:8080/admin/firmware?version=9.9.9&notes=Firmware%20contoh%20Postman" \
  -H "X-Admin-Key: ganti-kunci-admin" -H "Content-Type: application/octet-stream" \
  --data-binary @contoh-integrasi/postman/contoh-firmware.bin
```
```json
{
  "ok": true,
  "release": {
    "version": "9.9.9",
    "size": 384,
    "md5": "482f6ed9a8c9cce753c8e1c5767e2daf",
    "notes": "Firmware contoh Postman",
    "uploaded_at": "2026-10-02T08:34:48+07:00",
    "devices": 0
  }
}
```

(`contoh-firmware.bin` adalah firmware **palsu** 384 byte untuk uji, berisi teks `AbsensiRFID/9.9.9`. Jangan dipasang ke alat.)

Galat (422):

```json
{
  "ok": false,
  "message": "Versi 9.9.8 tidak ditemukan di dalam file. Pastikan VERSI_FIRMWARE di config.h sama.",
  "errors": {
    "file": "Versi 9.9.8 tidak ditemukan di dalam file. Pastikan VERSI_FIRMWARE di config.h sama."
  }
}
```
```json
{
  "ok": false,
  "message": "Bukan file firmware ESP32 (byte pertama harus 0xE9). Pakai file .bin aplikasi, bukan file gabungan/bootloader",
  "errors": {
    "file": "Bukan file firmware ESP32 (byte pertama harus 0xE9). Pakai file .bin aplikasi, bukan file gabungan/bootloader"
  }
}
```
```json
{
  "ok": false,
  "message": "Versi 9.9.9 sudah ada. Hapus dulu atau pakai nomor versi baru",
  "errors": {
    "version": "Versi 9.9.9 sudah ada. Hapus dulu atau pakai nomor versi baru"
  }
}
```

#### `GET /admin/firmware`

`devices` = jumlah alat yang `firmware_target`-nya versi ini.

```bash
curl http://localhost:8080/admin/firmware -H "X-Admin-Key: ganti-kunci-admin"
```
```json
{
  "ok": true,
  "releases": [
    {
      "version": "9.9.9",
      "size": 384,
      "md5": "482f6ed9a8c9cce753c8e1c5767e2daf",
      "notes": "Firmware contoh Postman",
      "uploaded_at": "2026-10-02T08:34:48+07:00",
      "devices": 1
    }
  ]
}
```

#### Menjadwalkan ke satu alat

```bash
curl -X PUT http://localhost:8080/admin/devices/PM-MUQAJ0VZ -H "X-Admin-Key: ganti-kunci-admin" \
  -H "Content-Type: application/json" -d '{"firmware_target": "9.9.9"}'
```

Balasan `/ping` alat itu kemudian membawa `firmware_update`. `url` = Base URL alat + `/firmware/{versi}` (satu origin, supaya alat ikut mengirim API key; di belakang reverse proxy skemanya mengikuti `X-Forwarded-Proto`):

```json
{
  "ok": true,
  "message": "Terhubung ke Postman Uji",
  "server_time": "2026-10-02T08:34:49+07:00",
  "config": {
    "title": "Postman Uji",
    "dim_after": 120,
    "dim_level": 35,
    "announcements_rev": "2-1790904822",
    "restart_at": "04:15",
    "firmware_update": {
      "version": "9.9.9",
      "url": "http://localhost:8080/firmware/9.9.9",
      "size": 384,
      "md5": "482f6ed9a8c9cce753c8e1c5767e2daf"
    }
  }
}
```

`firmware_update` hanya dikirim kalau alat punya target, versi target ≠ `firmware` alat, dan ≠ `ota_failed` alat.

#### `GET {base}/firmware/{versi}` (dipanggil alat)

Auth alat biasa (`X-API-Key` + `X-Device-ID`). Hanya alat yang dijadwalkan ke versi itu yang boleh mengunduh.

```bash
curl -o firmware.bin -D - http://localhost:8080/firmware/9.9.9 \
  -H "X-API-Key: ganti-dengan-kunci-anda" -H "X-Device-ID: PM-MUQAJ0VZ"
```
```http
HTTP/1.1 200 OK
Content-Type: application/octet-stream
Content-Length: 384
x-MD5: 482f6ed9a8c9cce753c8e1c5767e2daf

(isi file .bin mentah)
```

Alat lain / versi lain → 404:

```json
{
  "ok": false,
  "message": "Firmware tidak tersedia untuk alat ini"
}
```

Setelah alat melapor `"firmware": "9.9.9"` di heartbeat, `GET /admin/devices/{id}` menunjukkan `firmware_state: "installed"` dan kejadian `Firmware 1.5.0 -> 9.9.9`. Kalau alat melapor `raw.ota_failed: "9.9.9"`, `firmware_state` menjadi `failed` dan update tidak dikirim lagi untuk versi itu.

#### `POST /admin/firmware/{versi}/apply-all`

Jadwalkan versi ini ke **semua** alat terdaftar. `devices` = jumlah alat yang dijadwalkan.

```bash
curl -X POST http://localhost:8080/admin/firmware/9.9.9/apply-all -H "X-Admin-Key: ganti-kunci-admin"
```
```json
{
  "ok": true,
  "devices": 2
}
```

#### `DELETE /admin/firmware/{versi}`

Menghapus file & data. Alat yang dijadwalkan ke versi ini tidak jadi update (`firmware_target` → `null`).

```bash
curl -X DELETE http://localhost:8080/admin/firmware/9.9.9 -H "X-Admin-Key: ganti-kunci-admin"
```
```json
{
  "ok": true
}
```

Versi yang tidak ada → 404 `{"ok": false, "message": "Firmware tidak ditemukan"}`.

### 5.7 Pengumuman

| Field | Aturan |
|---|---|
| `title` | wajib, 1-40 karakter |
| `description` | 0-160 karakter (bawaan `""`) |
| `icon` | `info` (bawaan), `pengumuman`, `kalender`, `jam`, `peringatan`, `rapat`, `libur`, `selamat`, `kesehatan`, `buku` |
| `active` | `true`/`false` (bawaan `true`). Hanya yang aktif dikirim ke alat |
| `order` | bilangan bulat (bawaan 0). Alat menerima urut `order` lalu `id`, maks. 10 |

Setiap perubahan mengganti `rev` (`"<jumlah aktif>-<waktu>"`, selalu berbeda dari sebelumnya). Nilai ini dikirim sebagai `config.announcements_rev`; alat yang melihat rev berubah langsung mengambil ulang `GET /announcements` (dalam ±1 menit).

#### `GET /admin/announcements`

```bash
curl http://localhost:8080/admin/announcements -H "X-Admin-Key: ganti-kunci-admin"
```
```json
{
  "ok": true,
  "rev": "2-1790904822",
  "items": [
    {
      "id": 1,
      "title": "Rapat Guru",
      "description": "Hari ini pukul 13.00 di aula lantai 2.",
      "icon": "rapat",
      "active": true,
      "order": 0
    },
    {
      "id": 2,
      "title": "Libur Nasional",
      "description": "Kamis, 2 Oktober 2026 kantor tutup.",
      "icon": "libur",
      "active": true,
      "order": 0
    }
  ]
}
```

#### `POST /admin/announcements` → 201

```bash
curl -X POST http://localhost:8080/admin/announcements -H "X-Admin-Key: ganti-kunci-admin" \
  -H "Content-Type: application/json" \
  -d '{"title": "Tes Postman", "description": "Pengumuman ini dibuat oleh koleksi Postman.", "icon": "peringatan", "order": -100}'
```
```json
{
  "ok": true,
  "item": {
    "id": 3,
    "title": "Tes Postman",
    "description": "Pengumuman ini dibuat oleh koleksi Postman.",
    "icon": "peringatan",
    "active": true,
    "order": -100
  },
  "rev": "3-1790904890"
}
```

Yang diterima alat (`GET /announcements`, `id` berupa teks):

```json
{
  "ok": true,
  "interval": 5,
  "idle": 45,
  "items": [
    {
      "id": "3",
      "title": "Tes Postman",
      "description": "Pengumuman ini dibuat oleh koleksi Postman.",
      "icon": "peringatan"
    },
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

Galat (422):

```json
{
  "ok": false,
  "message": "Judul wajib diisi, maks. 40 karakter",
  "errors": {
    "title": "Judul wajib diisi, maks. 40 karakter",
    "description": "Deskripsi maks. 160 karakter",
    "icon": "Ikon harus salah satu dari: info, pengumuman, kalender, jam, peringatan, rapat, libur, selamat, kesehatan, buku",
    "active": "active harus true atau false",
    "order": "order harus bilangan bulat"
  }
}
```

#### `PUT /admin/announcements/{id}`

```bash
curl -X PUT http://localhost:8080/admin/announcements/3 -H "X-Admin-Key: ganti-kunci-admin" \
  -H "Content-Type: application/json" -d '{"title": "Tes Postman (diubah)"}'
```
```json
{
  "ok": true,
  "item": {
    "id": 3,
    "title": "Tes Postman (diubah)",
    "description": "Pengumuman ini dibuat oleh koleksi Postman.",
    "icon": "peringatan",
    "active": true,
    "order": -100
  },
  "rev": "3-1790904891"
}
```

#### `DELETE /admin/announcements/{id}`

```bash
curl -X DELETE http://localhost:8080/admin/announcements/3 -H "X-Admin-Key: ganti-kunci-admin"
```
```json
{
  "ok": true,
  "rev": "2-1790904893"
}
```

`id` yang tidak ada → 404 `{"ok": false, "message": "Pengumuman tidak ditemukan"}`.

---

## 6. Menguji API alat dengan `cek-server.sh`

Skrip ini berpura-pura menjadi alat dan memeriksa **API alat** server mana pun (server contoh, server acuan, atau aplikasi Anda). Butuh `bash`, `curl`, dan `python3` saja.

```bash
cd contoh-integrasi/tes
./cek-server.sh BASE_URL API_KEY [RFID_TERDAFTAR]

# contoh
./cek-server.sh http://localhost:8080 ganti-dengan-kunci-anda 0218893066
./cek-server.sh https://app.contoh.com/api/absensi rahasia-kantor-123 0012345678
```

Yang diperiksa:
1. `/ping`: `ok`, `server_time` ISO 8601, dan `config` (`title` teks 1-30, `dim_after` 0 atau 10-3600, `dim_level` 0-100, `restart_at` `"HH:MM"`/`""`, `announcements_rev` teks, `pin` 4-8 digit, `firmware_update` lengkap). Kunci `config` yang tidak dikirim diberi ⚠️.
2. API key salah → **401** (untuk `/ping` dan `/tap`).
3. `/heartbeat` dengan kesehatan lengkap (`min_free_heap`, `error`, `ota_failed`, `crash`) dan heartbeat biasa → 200.
4. `/tap`: kartu terdaftar, kirim ulang `tap_id` yang sama (balasan sama atau `duplicate`), antrean dengan `tapped_at` kemarin, antrean dengan `tapped_at: null` & `raw: null`, kartu tak terdaftar (`unknown`), `ok` sesuai `status`.
5. Format salah (body bukan JSON, `rfid` kosong) → **400**.
6. `/announcements`: 404 hanya ⚠️; kalau 200, formatnya diperiksa (maks. 10 item, `title`, `id` teks, ikon dikenal, `interval` 2-60, `idle` 5-600).
7. Kalau heartbeat membalas `config.firmware_update`: file di `url` diunduh (dengan header API kalau satu origin), lalu `Content-Length`, ukuran, dan MD5-nya dicocokkan.
8. Batas waktu: `/ping` < 8 detik, endpoint lain < 3 detik.

Setiap pemeriksaan ditandai ✅ atau ❌; ⚠️ = saran. Kode keluar = jumlah yang gagal (`0` = lulus semua), jadi bisa dipakai di CI. Potongan keluarannya:

```text
Memeriksa http://localhost:8080  (RFID uji: 0218893066)

1. Tes koneksi
→ GET /ping   (HTTP 200, 0.002112s)
    {"ok":true,"message":"Terhubung ke Aplikasi Contoh","server_time":"2026-10-02T08:36:12+07:00","config":{"title":"Aplikasi Contoh","dim_after":60," ...
  ✅ HTTP 200
  ✅ "ok" = true
  ✅ "server_time" format ISO 8601 + zona waktu (2026-10-02T08:36:12+07:00)
  ✅ "config" berupa objek
  ✅ "config.title" berupa teks, maks. 30 karakter (15)
  ✅ "config.announcements_rev" berupa teks ('2-1790904964')
  ✅ "config.dim_after" bilangan bulat 0 atau 10–3600 (60)
  ✅ "config.dim_level" bilangan bulat 0–100 (20)
  ✅ "config.restart_at" teks "" atau "HH:MM" 00:00–23:59 ('03:00')

...
12. Update firmware jarak jauh (opsional, hanya kalau heartbeat mengirim config.firmware_update)
  ⚠️  server tidak mengirim config.firmware_update untuk ABS-TES001 (wajar kalau alat ini tidak dijadwalkan update)

✅ SEMUA PEMERIKSAAN LULUS
```

Untuk memastikan kiriman ulang `tap_id` benar-benar **tidak menambah baris** di database, isi `HITUNG_DATA` dengan perintah yang mencetak jumlah baris absensi:

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

Untuk menguji alur unduh firmware dengan server contoh, jadwalkan dulu firmware ke alat uji skrip (`ABS-TES001`):

```bash
curl -X POST "http://localhost:8080/admin/firmware?version=9.9.9" -H "X-Admin-Key: ganti-kunci-admin" \
  -H "Content-Type: application/octet-stream" --data-binary @../postman/contoh-firmware.bin
curl -X PUT http://localhost:8080/admin/devices/ABS-TES001 -H "X-Admin-Key: ganti-kunci-admin" \
  -H "Content-Type: application/json" -d '{"firmware_target": "9.9.9"}'
./cek-server.sh http://localhost:8080 ganti-dengan-kunci-anda      # langkah 12 ikut mengunduh
curl -X DELETE http://localhost:8080/admin/firmware/9.9.9 -H "X-Admin-Key: ganti-kunci-admin"
```

> Skrip ini mengirim tap & heartbeat sungguhan (kartu `9999999990`, RFID yang Anda berikan, alat `ABS-TES001`). Jalankan di server uji, bukan di data produksi.

---

## 7. Menguji dengan Postman & Newman

File di folder `postman/`:

| File | Isi |
|---|---|
| `AbsensiRFID.postman_collection.json` | Semua request + tes otomatis + contoh respons asli (tab *Examples*) |
| `AbsensiRFID.postman_environment.json` | Variabel yang bisa Anda ubah (tabel di bawah) |
| `contoh-firmware.bin` | Firmware **palsu** 384 byte (byte pertama `0xE9`, berisi `AbsensiRFID/9.9.9`) untuk uji unggah/unduh |

Struktur koleksi:

| Folder | Isi | Untuk siapa |
|---|---|---|
| **1. API alat** | `/ping` (berhasil, 401, 400), `/tap` (masuk, kirim ulang `tap_id`, `duplicate`, pulang setelah 61 detik, antrean, `unknown`, `rejected`, `raw` null, 400), `/heartbeat` (biasa, kesehatan lengkap dengan `error`/`ota_failed`/`crash`, `raw` null), `/announcements`, unduh firmware (hanya kalau ada `config.firmware_update`; selain itu **dilewati**) | **Semua** aplikasi. Wajib hijau |
| **2. Admin contoh** | Skenario 6 fitur wajib terhadap API admin server contoh: 401/404/400/413 → Pengaturan → Anggota → Kartu belum terdaftar → Rekap & kehadiran → Alat → Firmware → Pengumuman → kembalikan pengaturan | Server contoh PHP/Node. Untuk aplikasi Anda: gambaran apa yang harus bisa dilakukan admin |

Variabel environment:

| Variabel | Nilai bawaan | Keterangan |
|---|---|---|
| `base_url` | `http://localhost:8080` | Base URL API alat, sama seperti yang diisi di alat |
| `api_key` | `ganti-dengan-kunci-anda` | API key alat |
| `device_id` | `ABS-TES001` | ID alat pura-pura untuk folder 1 |
| `admin_url` | `{{base_url}}` | Alamat server untuk API admin (`{{admin_url}}/admin/...`) |
| `admin_key` | `ganti-kunci-admin` | Header `X-Admin-Key` |
| `rfid_terdaftar` | `0218893066` | Kartu anggota **aktif** |
| `rfid_nonaktif` | `0055555555` | Kartu anggota **nonaktif** |
| `rfid_tidak_terdaftar` | `9999999990` | Kartu yang **tidak** terdaftar |

Variabel lain (`tap_id`, `alat_uji`, `kartu_baru`, `hari_ini`, `pengaturan_asli`, `fw_md5`, ...) adalah **variabel koleksi yang diisi otomatis** saat koleksi berjalan; tidak perlu diubah. `firmware_versi` (`9.9.9`) harus sama dengan teks versi di `contoh-firmware.bin`.

### Di aplikasi Postman

1. **Import** kedua file JSON.
2. Pilih environment **Absensi RFID Terintegrasi** (pojok kanan atas), sesuaikan `base_url` dan `api_key`.
3. Untuk folder 2 (unggah firmware): **Settings → General → Working directory** = folder `contoh-integrasi/postman`.
4. Klik kanan koleksi (atau folder **1. API alat** saja) → **Run** → jalankan dari atas. Kasus "pulang" sengaja menunggu 61 detik.

### Dengan Newman (terminal / CI)

Dijalankan dari folder utama repo. Perintah di bawah sudah diuji terhadap server contoh PHP dan Node, masing-masing **dua kali berturut-turut** tanpa restart server:

```bash
# Seluruh koleksi terhadap server contoh (PHP atau Node)
npx newman run contoh-integrasi/postman/AbsensiRFID.postman_collection.json \
  -e contoh-integrasi/postman/AbsensiRFID.postman_environment.json \
  --env-var base_url=http://localhost:8080 --env-var api_key=ganti-dengan-kunci-anda \
  --working-dir contoh-integrasi/postman --delay-request 50
```

Ringkasan hasilnya:

```text
┌─────────────────────────┬─────────────────┬─────────────────┐
│                         │        executed │          failed │
├─────────────────────────┼─────────────────┼─────────────────┤
│              iterations │               1 │               0 │
├─────────────────────────┼─────────────────┼─────────────────┤
│                requests │             108 │               0 │
├─────────────────────────┼─────────────────┼─────────────────┤
│            test-scripts │             126 │               0 │
├─────────────────────────┼─────────────────┼─────────────────┤
│      prerequest-scripts │             114 │               0 │
├─────────────────────────┼─────────────────┼─────────────────┤
│              assertions │             514 │               0 │
├─────────────────────────┴─────────────────┴─────────────────┤
│ total run duration: 1m 9.4s                                 │
├─────────────────────────────────────────────────────────────┤
│ total data received: 25.71kB (approx)                       │
├─────────────────────────────────────────────────────────────┤
│ average response time: 2ms [min: 1ms, max: 24ms, s.d.: 2ms] │
└─────────────────────────────────────────────────────────────┘
```

(1 request folder 1, "Unduh firmware", dilewati karena `ABS-TES001` tidak dijadwalkan update. Kalau dijadwalkan, request itu ikut jalan dan memeriksa ukuran & MD5 file.)

**Aplikasi Anda sendiri** (tanpa API admin contoh): jalankan folder 1 saja, dan isi kartu sesuai data Anda:

```bash
npx newman run contoh-integrasi/postman/AbsensiRFID.postman_collection.json \
  -e contoh-integrasi/postman/AbsensiRFID.postman_environment.json \
  --env-var base_url=https://app.anda.com/api/absensi --env-var api_key=KUNCI-ANDA \
  --env-var rfid_terdaftar=NOMOR_AKTIF --env-var rfid_nonaktif=NOMOR_NONAKTIF \
  --folder "1. API alat"
```

Catatan:
- Folder 1 juga mewajibkan `config.title`, `dim_after`, `dim_level`, `restart_at`, `announcements_rev` di `/ping`/`/heartbeat` dan `GET /announcements` = 200, karena keenam fitur wajib membutuhkannya.
- Kasus **"Tap – masuk"** menerima `check_in`, atau `check_out`/`duplicate` kalau kartu itu sudah tap hari ini (koleksi dijalankan ulang).
- Folder 2 memakai ID alat (`PM-...`) dan nomor kartu unik per run, membersihkan data ujinya, dan mengembalikan pengaturan di akhir, jadi bisa diulang. Kalau run berhenti di tengah, pengaturan uji (judul "Postman Uji", `duplicate_window` 0) bisa tertinggal: kembalikan lewat `PUT /admin/settings`, atau hapus data server contoh.
- Balasan **429** tidak dipicu koleksi; bentuknya ada sebagai contoh respons.

> Koleksi ini mengirim **data sungguhan** (tap, heartbeat, perubahan pengaturan). Jalankan di server uji, bukan di server produksi.

---

## 8. Checklist integrasi

**API alat** (rincian: [spesifikasi](../doc/spesifikasi-api.md), [panduan bagian 5](../doc/integrasi-aplikasi.md#5-api-alat-yang-dipanggil-alat))
- [ ] `GET /ping`, `POST /tap`, `POST /heartbeat`, `GET /announcements`, `GET /firmware/{versi}` di bawah satu Base URL, misalnya `/api/absensi/...`.
- [ ] `X-API-Key` salah → **401**. Tanpa `X-Device-ID` atau format salah → **400** dengan `message`. Semua hasil bisnis → **200**.
- [ ] Nomor kartu disimpan sebagai **teks** (`"0218893066"`, nol di depan tidak hilang).
- [ ] Aturan masuk/pulang di satu fungsi seperti `tentukanStatus()`; balas `status` + `message` (±32 karakter) + `name`.
- [ ] `tap_id` disimpan (unik); `tap_id` yang sama → balas respons yang dulu, jangan catat lagi.
- [ ] Tap `queued: true` memakai `tapped_at` (kalau `null`, waktu diterima).
- [ ] `server_time` ISO 8601 + zona waktu di `/ping` & `/heartbeat`; `config` berisi `title`, `dim_after`, `dim_level`, `restart_at`, `announcements_rev` (+ `pin`, `firmware_update` kalau ada).
- [ ] Balas `/tap` di bawah 1 detik (alat menunggu 3 detik; `/ping` 8 detik).
- [ ] `tes/cek-server.sh` lulus semua dan Postman folder **1. API alat** hijau.

**Enam fitur wajib** (rincian: [panduan bagian 6](../doc/integrasi-aplikasi.md#6-fitur-wajib-satu-per-satu))
- [ ] **Kartu belum terdaftar:** kartu baru muncul, bisa didaftarkan, lalu hilang dari daftar.
- [ ] **Rekap:** masuk/pulang per hari benar, unduhan CSV bisa dibuka di Excel.
- [ ] **Alat:** online/offline, kesehatan, `issues`, riwayat boot/crash; PIN & jam restart per alat sampai ke `config`.
- [ ] **Firmware:** unggah (validasi 0xE9, ukuran, versi di dalam file), jadwalkan ke 1 alat, `firmware_update` di `config`, unduhan dengan `Content-Length` benar, status `installed`/`failed`.
- [ ] **Pengumuman:** CRUD, `announcements_rev` berubah setiap perubahan, alat menampilkan yang baru dalam ±1 menit.
- [ ] **Pengaturan:** judul, layar redup, screensaver, jeda tap ganda, jam restart bawaan, API key.

**Keamanan & operasional**
- [ ] HTTPS di server publik; API key & kunci admin bukan nilai contoh.
- [ ] Halaman/API admin dilindungi login.
- [ ] Backup database terjadwal; zona waktu server benar (WIB/WITA/WIT).

---

## 9. Catatan hosting

- Contoh PHP mengenali endpoint dari bagian **terakhir** path (`.../ping`, `.../tap`, ...) dan API admin dari `/admin/` di path, jadi Base URL seperti `https://domain.com/absensi/index.php` juga bisa dipakai (alat akan memanggil `.../index.php/ping`, admin `.../index.php/admin/settings`).
- Letakkan `data.sqlite` dan folder `firmware/` **di luar folder publik** (ubah path di `new PDO(...)` dan `FOLDER_FIRMWARE`) atau blokir aksesnya, supaya database dan file firmware tidak bisa diunduh orang lain.
- Folder tempat `data.sqlite` dan `firmware/` berada harus bisa ditulis oleh web server.
- Unggah firmware butuh batas upload ≥ 2 MB: PHP `post_max_size` (bawaan 8M sudah cukup); Nginx `client_max_body_size 2m;`.
- Di belakang reverse proxy (Nginx, Cloudflare), teruskan header `Host` dan `X-Forwarded-Proto` supaya `firmware_update.url` memakai alamat & skema yang benar.
- Alat **tidak mengikuti redirect**. Pakai Base URL `https://` langsung kalau server memaksa HTTPS.
- Server contoh tidak punya login admin selain `X-Admin-Key`. Untuk produksi, bangun halaman admin dengan login di aplikasi Anda sendiri.

---

## 10. Daftar endpoint & kasus API alat

Request di bawah ini **persis** seperti yang dikirim koleksi Postman (folder **1. API alat**, urutan sama) dan respons-nya keluaran asli server contoh PHP, dengan:
- Base URL `http://localhost:8080`, API key `ganti-dengan-kunci-anda`, ID alat `ABS-TES001`;
- `tap_id` baru untuk setiap tap (kecuali kasus kirim ulang); `tapped_at` = jam alat saat kartu ditempel;
- teks `message` bebas (server acuan `api/` memakai teks sedikit berbeda). Urutan kunci JSON bebas.

Urutan kasus `/tap` penting karena hasilnya saling bergantung (masuk → kirim ulang → duplicate → pulang).

### `GET /ping`

#### 1. Ping – berhasil

Tombol "Tes koneksi" di alat (dan saat alat menyala). Wajib: `ok`. Disarankan: `server_time` (ISO 8601 + zona waktu). `config` membawa pengaturan jarak jauh (spesifikasi bagian 6): koleksi ini mewajibkan `title`, `dim_after`, `dim_level`, `restart_at`, dan `announcements_rev` karena dibutuhkan fitur wajib Pengaturan, Alat, dan Pengumuman. `config.pin` hanya dikirim kalau alat ini punya PIN khusus; `config.firmware_update` hanya kalau alat dijadwalkan update. Contoh 429: server boleh membatasi jumlah request; alat menganggapnya gagal dan mencoba lagi nanti (tidak dipicu koleksi ini).

Request:

```http
GET http://localhost:8080/ping
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-TES001
X-Spec-Version: 1
Accept: application/json
```

Respons – Berhasil:

```http
HTTP 200 OK
Content-Type: application/json; charset=utf-8

{
  "ok": true,
  "message": "Terhubung ke Aplikasi Contoh",
  "server_time": "2026-10-02T08:33:42+07:00",
  "config": {
    "title": "Aplikasi Contoh",
    "dim_after": 60,
    "dim_level": 20,
    "announcements_rev": "2-1790904822",
    "restart_at": "03:00"
  }
}
```

Respons – Terlalu banyak request (429):

```http
HTTP 429 Too Many Requests
Content-Type: application/json; charset=utf-8

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
X-Device-ID: ABS-TES001
X-Spec-Version: 1
Accept: application/json
```

Respons – API key salah:

```http
HTTP 401 Unauthorized
Content-Type: application/json; charset=utf-8

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

Respons – Tanpa X-Device-ID:

```http
HTTP 400 Bad Request
Content-Type: application/json; charset=utf-8

{
  "ok": false,
  "message": "Header X-Device-ID wajib"
}
```

### `POST /tap`

#### 4. Tap – masuk (check_in)

Tap pertama hari itu untuk kartu terdaftar → `check_in`. Tap biasa (`queued: false`) memakai jam server. Kalau koleksi dijalankan ulang di hari yang sama, kartu ini sudah tap, jadi aturan contoh menjawab `check_out` (atau `duplicate` kalau tap terakhirnya kurang dari 60 detik lalu); tes menerima ketiganya.

Request:

```http
POST http://localhost:8080/tap
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-TES001
X-Spec-Version: 1
Accept: application/json
Content-Type: application/json

{
  "device_id": "ABS-TES001",
  "tap_id": "PM-1A0FA3EEA34-E122C8",
  "rfid": "0218893066",
  "tapped_at": "2026-10-02T08:33:42+07:00",
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

Respons – Masuk:

```http
HTTP 200 OK
Content-Type: application/json; charset=utf-8

{
  "ok": true,
  "status": "check_in",
  "message": "Selamat datang",
  "name": "Budi Santoso",
  "time": "08:33",
  "photo_url": null
}
```

#### 5. Tap – kirim ulang tap_id yang sama (tidak dicatat dua kali)

Tap yang sama terkirim lagi dari antrean (misal balasan pertama lewat 8 detik). `tap_id` sama dengan kasus "Tap – masuk". Server tidak boleh mencatat baris baru: balas respons yang sama, atau `duplicate`.

Request:

```http
POST http://localhost:8080/tap
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-TES001
X-Spec-Version: 1
Accept: application/json
Content-Type: application/json

{
  "device_id": "ABS-TES001",
  "tap_id": "PM-1A0FA3EEA34-E122C8",
  "rfid": "0218893066",
  "tapped_at": "2026-10-02T08:33:42+07:00",
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
Content-Type: application/json; charset=utf-8

{
  "ok": true,
  "status": "check_in",
  "message": "Selamat datang",
  "name": "Budi Santoso",
  "time": "08:33",
  "photo_url": null
}
```

Respons – Atau duplicate (server acuan):

```http
HTTP 200 OK
Content-Type: application/json; charset=utf-8

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
X-Device-ID: ABS-TES001
X-Spec-Version: 1
Accept: application/json
Content-Type: application/json

{
  "device_id": "ABS-TES001",
  "tap_id": "PM-1A0FA3EEACD-AECCC1",
  "rfid": "0218893066",
  "tapped_at": "2026-10-02T08:33:42+07:00",
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

Respons – Sudah tercatat:

```http
HTTP 200 OK
Content-Type: application/json; charset=utf-8

{
  "ok": true,
  "status": "duplicate",
  "message": "Sudah tercatat",
  "time": "08:33",
  "name": "Budi Santoso",
  "photo_url": null
}
```

#### 7. Tap – pulang (check_out), menunggu 61 detik

Tap berikutnya di hari yang sama (lebih dari 60 detik setelah tap diterima). `info` maks. 2 baris. Di Postman, request ini menunggu 61 detik sebelum dikirim.

Request:

```http
POST http://localhost:8080/tap
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-TES001
X-Spec-Version: 1
Accept: application/json
Content-Type: application/json

{
  "device_id": "ABS-TES001",
  "tap_id": "PM-1A0FA3EEB16-B91967",
  "rfid": "0218893066",
  "tapped_at": "2026-10-02T08:33:42+07:00",
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

Respons – Pulang:

```http
HTTP 200 OK
Content-Type: application/json; charset=utf-8

{
  "ok": true,
  "status": "check_out",
  "message": "Hati-hati di jalan",
  "name": "Budi Santoso",
  "time": "08:34",
  "photo_url": null,
  "info": [
    "Masuk tadi 08:33"
  ]
}
```

#### 8. Tap – antrean dengan tapped_at (queued)

Tap yang tersimpan di alat saat server mati, dikirim belakangan dengan `queued: true`. Pakai `tapped_at` (di sini kemarin 07:30) sebagai waktu absen. Respons tap antrean tidak ditampilkan alat; alat hanya melihat kode HTTP (2xx/3xx/4xx = dihapus dari antrean, 5xx/timeout = dicoba lagi). Server boleh memakai waktu diterima kalau `tapped_at` lebih dari 5 menit di masa depan atau lebih lama dari 30 hari.

Request:

```http
POST http://localhost:8080/tap
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-TES001
X-Spec-Version: 1
Accept: application/json
Content-Type: application/json

{
  "device_id": "ABS-TES001",
  "tap_id": "PM-1A0FA3FD9C9-6B9361",
  "rfid": "0218893066",
  "tapped_at": "2026-10-01T07:30:00+07:00",
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

Respons – Dicatat pada jam tapped_at:

```http
HTTP 200 OK
Content-Type: application/json; charset=utf-8

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
X-Device-ID: ABS-TES001
X-Spec-Version: 1
Accept: application/json
Content-Type: application/json

{
  "device_id": "ABS-TES001",
  "tap_id": "PM-1A0FA3FDA15-952189",
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

Respons – Dicatat pada waktu diterima:

```http
HTTP 200 OK
Content-Type: application/json; charset=utf-8

{
  "ok": true,
  "status": "duplicate",
  "message": "Sudah tercatat",
  "time": "08:34",
  "name": "Budi Santoso",
  "photo_url": null
}
```

#### 10. Tap – kartu tidak terdaftar (unknown)

Tetap HTTP 200. Alat menampilkan KARTU TIDAK TERDAFTAR beserta nomor kartu.

Request:

```http
POST http://localhost:8080/tap
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-TES001
X-Spec-Version: 1
Accept: application/json
Content-Type: application/json

{
  "device_id": "ABS-TES001",
  "tap_id": "PM-1A0FA3FDA60-B9F595",
  "rfid": "9999999990",
  "tapped_at": "2026-10-02T08:34:43+07:00",
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

Respons – Kartu tidak terdaftar:

```http
HTTP 200 OK
Content-Type: application/json; charset=utf-8

{
  "ok": false,
  "status": "unknown",
  "message": "Kartu tidak terdaftar",
  "time": "08:34"
}
```

#### 11. Tap – kartu nonaktif (rejected)

Tetap HTTP 200. Alasan penolakan di `message` (misal "Kartu nonaktif", "Di luar jam kerja").

Request:

```http
POST http://localhost:8080/tap
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-TES001
X-Spec-Version: 1
Accept: application/json
Content-Type: application/json

{
  "device_id": "ABS-TES001",
  "tap_id": "PM-1A0FA3FDAA8-AA0ABA",
  "rfid": "0055555555",
  "tapped_at": "2026-10-02T08:34:43+07:00",
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

Respons – Ditolak:

```http
HTTP 200 OK
Content-Type: application/json; charset=utf-8

{
  "ok": false,
  "status": "rejected",
  "message": "Kartu nonaktif",
  "name": "Rina Kurnia",
  "time": "08:34",
  "photo_url": null
}
```

#### 12. Tap – raw null

`raw` boleh `null` (atau tidak ada). Server tidak boleh gagal karena itu.

Request:

```http
POST http://localhost:8080/tap
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-TES001
X-Spec-Version: 1
Accept: application/json
Content-Type: application/json

{
  "device_id": "ABS-TES001",
  "tap_id": "PM-1A0FA3FDAF2-F6E74A",
  "rfid": "9999999990",
  "tapped_at": "2026-10-02T08:34:43+07:00",
  "queued": false,
  "raw": null
}
```

Respons – raw null tetap diterima:

```http
HTTP 200 OK
Content-Type: application/json; charset=utf-8

{
  "ok": false,
  "status": "unknown",
  "message": "Kartu tidak terdaftar",
  "time": "08:34"
}
```

#### 13. Tap – rfid kosong (400)

Format salah dijawab HTTP 400 dengan `message`. Contoh PHP/Node membalas "rfid kosong", server acuan "Nomor kartu tidak valid".

Request:

```http
POST http://localhost:8080/tap
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-TES001
X-Spec-Version: 1
Accept: application/json
Content-Type: application/json

{
  "device_id": "ABS-TES001",
  "tap_id": "PM-1A0FA3FDB3A-38CA9B",
  "rfid": "",
  "tapped_at": "2026-10-02T08:34:44+07:00",
  "queued": false,
  "raw": null
}
```

Respons – rfid kosong:

```http
HTTP 400 Bad Request
Content-Type: application/json; charset=utf-8

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
X-Device-ID: ABS-TES001
X-Spec-Version: 1
Accept: application/json
Content-Type: application/json

ini-bukan-json
```

Respons – Body bukan JSON:

```http
HTTP 400 Bad Request
Content-Type: application/json; charset=utf-8

{
  "ok": false,
  "message": "rfid kosong"
}
```

### `POST /check-in` & `POST /check-out` (mode pilih Datang/Pulang)

Dipakai alat yang **mode absen**-nya "Pilih Datang / Pulang" (firmware ≥ 1.6.0): petugas memilih tombol DATANG atau PULANG di layar, lalu semua tap dikirim ke endpoint itu. Header, body, dan format balasan **sama dengan `/tap`**, ditambah field `"mode"` di body. `tap_id` idempoten lintas ketiga endpoint. Aplikasi yang tidak menyediakannya cukup tidak memakai mode pilih (alat akan mendapat 404 → E23). Aturan lengkap: [spesifikasi bagian 4.1](../doc/spesifikasi-api.md).

```bash
curl -X POST http://localhost:8080/check-in \
  -H "X-API-Key: ganti-dengan-kunci-anda" -H "X-Device-ID: ABS-TES001" -H "Content-Type: application/json" \
  -d '{"device_id":"ABS-TES001","tap_id":"79438C-00000001","rfid":"0218893066","tapped_at":"2026-10-02T07:45:10+07:00","queued":false,"mode":"check_in"}'
```
```json
{"ok":true,"status":"check_in","message":"Selamat datang","name":"Budi Santoso","time":"10:10","photo_url":null}
```

Check-in kedua di hari yang sama (`tap_id` lain) → `duplicate`, `time` = jam check-in pertama:
```json
{"ok":true,"status":"duplicate","message":"Sudah absen datang","name":"Budi Santoso","time":"10:10","photo_url":null}
```

`POST /check-out` (body sama, `"mode":"check_out"`):
```json
{"ok":true,"status":"check_out","message":"Hati-hati di jalan","name":"Budi Santoso","time":"10:10","photo_url":null,"info":["Masuk tadi 10:10"]}
```
Check-out ulang dalam jeda tap ganda (bawaan 60 detik) → `duplicate`. Tanpa check-in hari itu → `check_out` dengan `info: ["Belum absen datang hari ini"]`. Kartu tak dikenal / nonaktif → `unknown` / `rejected` seperti `/tap`.

**Mengatur mode dari server:** `PUT /admin/devices/ABS-TES001` body `{"tap_mode":"select"}` → `/ping` & `/heartbeat` mengirim `config.tap_mode: "select"`, dan dalam ± 1 menit alat menampilkan tombol DATANG/PULANG. `{"tap_mode": null}` → kunci tidak dikirim, alat memakai pengaturannya sendiri. Nilai lain → 422 `"tap_mode harus null (ikuti pengaturan alat), \"auto\", atau \"select\""`.

### `POST /heartbeat`

#### 15. Heartbeat – berhasil

Dikirim alat tiap 60 detik (isi persis seperti contoh di spesifikasi bagian 5). Server mencatat "terakhir terlihat" untuk X-Device-ID ini plus kesehatan dari `raw`, lalu membalas `server_time` dan `config`. Kalau `config.firmware_update` ada, URL-nya dipakai request "Unduh firmware".

Request:

```http
POST http://localhost:8080/heartbeat
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-TES001
X-Spec-Version: 1
Accept: application/json
Content-Type: application/json

{
  "device_id": "ABS-TES001",
  "firmware": "1.1.0",
  "time": "2026-10-02T08:34:44+07:00",
  "raw": {
    "wifi_ssid": "Kantor-2.4G",
    "rssi": -52,
    "ip": "192.168.1.23",
    "uptime_s": 3660,
    "free_heap": 180000,
    "min_free_heap": 152000,
    "reset_reason": "poweron",
    "rfid_ok": true,
    "queue": 0
  }
}
```

Respons – Berhasil:

```http
HTTP 200 OK
Content-Type: application/json; charset=utf-8

{
  "ok": true,
  "server_time": "2026-10-02T08:34:44+07:00",
  "config": {
    "title": "Aplikasi Contoh",
    "dim_after": 60,
    "dim_level": 20,
    "announcements_rev": "2-1790904822",
    "restart_at": "03:00"
  }
}
```

Respons – Terlalu banyak request (429):

```http
HTTP 429 Too Many Requests
Content-Type: application/json; charset=utf-8

{
  "ok": false,
  "message": "Terlalu banyak permintaan"
}
```

#### 16. Heartbeat – kesehatan lengkap (error, OTA gagal, crash)

Heartbeat dengan semua field kesehatan firmware 1.5.0 ke atas: `min_free_heap`, `error` (kode di layar, di sini E30), `ota_failed` (versi yang gagal dipasang), dan `crash` (dikirim setelah alat crash, bisa terkirim beberapa kali). Server wajib tetap membalas 200; isinya dipakai fitur Alat (peringatan & riwayat).

Request:

```http
POST http://localhost:8080/heartbeat
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-TES001
X-Spec-Version: 1
Accept: application/json
Content-Type: application/json

{
  "device_id": "ABS-TES001",
  "firmware": "1.1.0",
  "time": "2026-10-02T08:34:44+07:00",
  "raw": {
    "wifi_ssid": "Kantor-2.4G",
    "rssi": -71,
    "ip": "192.168.1.23",
    "uptime_s": 3720,
    "free_heap": 176000,
    "min_free_heap": 148000,
    "reset_reason": "panic",
    "rfid_ok": false,
    "queue": 3,
    "error": "E30",
    "ota_failed": "1.4.9",
    "crash": {
      "task": "loopTask",
      "pc": "0x400d4634",
      "backtrace": "0x400d4634 0x400880ed",
      "elf": "3f2a9c1b"
    }
  }
}
```

Respons – Contoh (server contoh):

```http
HTTP 200 OK
Content-Type: application/json; charset=utf-8

{
  "ok": true,
  "server_time": "2026-10-02T08:34:44+07:00",
  "config": {
    "title": "Aplikasi Contoh",
    "dim_after": 60,
    "dim_level": 20,
    "announcements_rev": "2-1790904822",
    "restart_at": "03:00"
  }
}
```

#### 17. Heartbeat – time null dan raw null

`time` = `null` kalau jam alat belum tersinkron; `raw` boleh `null`. Kirim `server_time` supaya jam alat disetel.

Request:

```http
POST http://localhost:8080/heartbeat
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-TES001
X-Spec-Version: 1
Accept: application/json
Content-Type: application/json

{
  "device_id": "ABS-TES001",
  "firmware": "1.1.0",
  "time": null,
  "raw": null
}
```

Respons – Berhasil:

```http
HTTP 200 OK
Content-Type: application/json; charset=utf-8

{
  "ok": true,
  "server_time": "2026-10-02T08:34:44+07:00",
  "config": {
    "title": "Aplikasi Contoh",
    "dim_after": 60,
    "dim_level": 20,
    "announcements_rev": "2-1790904822",
    "restart_at": "03:00"
  }
}
```

### `GET /announcements`

#### 18. Pengumuman – daftar untuk screensaver

Isi screensaver alat (spesifikasi bagian 8). Bagi alat endpoint ini opsional (404 = daftar lama tetap dipakai), tetapi Pengumuman termasuk 6 fitur wajib aplikasi, jadi koleksi ini mewajibkan 200. Maks. 10 item, `title` maks. 40 karakter, `description` maks. 160. Kode ikon: info, pengumuman, kalender, jam, peringatan, rapat, libur, selamat, kesehatan, buku. `interval` 2-60 detik, `idle` 5-600 detik.

Request:

```http
GET http://localhost:8080/announcements
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-TES001
X-Spec-Version: 1
Accept: application/json
```

Respons – Ada pengumuman:

```http
HTTP 200 OK
Content-Type: application/json; charset=utf-8

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
Content-Type: application/json; charset=utf-8

{
  "ok": true,
  "interval": 3,
  "idle": 30,
  "items": []
}
```

### `GET firmware_update.url`

#### 19. Unduh firmware (kalau ada firmware_update)

Spesifikasi bagian 6.1. URL diambil dari `config.firmware_update.url` di balasan "Heartbeat – berhasil". Request ini **dilewati** (bukan gagal) kalau server tidak menjadwalkan update untuk alat ini, jadi folder ini tetap lulus untuk aplikasi tanpa fitur firmware. Balasan: HTTP 200 tanpa redirect, isi file .bin mentah (`application/octet-stream`), `Content-Length` = `size`, MD5 isi = `md5`. Header `x-MD5` boleh ada.

Request (dikirim hanya kalau `config.firmware_update` ada):

```http
GET {config.firmware_update.url}
X-API-Key: ganti-dengan-kunci-anda
X-Device-ID: ABS-TES001
X-Spec-Version: 1
```

Respons – Berhasil (keluaran asli server contoh untuk alat yang dijadwalkan ke 9.9.9):

```http
HTTP 200 OK
Content-Type: application/octet-stream
Content-Length: 384
x-MD5: 482f6ed9a8c9cce753c8e1c5767e2daf

(isi file .bin mentah, 384 byte)
```

