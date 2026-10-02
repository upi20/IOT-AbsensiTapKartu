# Absensi RFID Terintegrasi — Server & Panel Admin

Server Laravel untuk alat absensi tap RFID. Isinya dua bagian:

1. **API standar alat** di `/api/absensi` — *implementasi acuan* dari
   [`doc/spesifikasi-api.md`](../doc/spesifikasi-api.md). Kalau Anda membuat aplikasi sendiri yang
   ingin dihubungkan ke alat, spesifikasi itulah acuannya; kode di sini contoh lengkap yang berjalan.
2. **Panel admin sederhana** di `/admin` — kelola anggota & kartu, lihat tap hari ini, rekap, alat
   (termasuk **pemantauan kesehatan alat** dan **mode absen** Otomatis / Pilih Datang/Pulang),
   **update firmware jarak jauh**, dan pengaturan.

- Laravel 13, PHP 8.3+, PostgreSQL, Blade + satu stylesheet (`public/css/admin.css`), tanpa build Node
- Zona waktu `Asia/Jakarta` (ubah dengan `APP_TIMEZONE`), bahasa Indonesia
- Tidak perlu scheduler (cron) maupun queue worker

Panduan lengkap proyek (alat, perakitan, firmware) ada di [`README.md` utama](../README.md). Contoh
server minimal (PHP/Node) dan skrip pemeriksa API ada di [`contoh-integrasi/`](../contoh-integrasi/README.md).

## Kebutuhan

- PHP **8.3** atau lebih baru dengan ekstensi:
  - wajib: `pdo_pgsql`, `mbstring`, `fileinfo` (plus ekstensi bawaan yang dibutuhkan Laravel, mis.
    `openssl`, `ctype`, `tokenizer`, `xml`)
  - opsional: `gd` untuk unggah foto anggota (tanpa GD aplikasi tetap jalan, hanya unggah foto yang
    dinonaktifkan) dan `exif` agar foto dari ponsel diputar sesuai orientasinya
- Composer 2
- **PostgreSQL**. Aplikasi memakai fitur khusus PostgreSQL (pencarian `ilike`, kolom
  `jsonb`), jadi **SQLite dan MySQL/MariaDB tidak didukung**.
- Node.js/npm **tidak** dibutuhkan.

## Setup dari clone baru

```bash
cd api
composer install
cp .env.example .env
php artisan key:generate
```

Buat dua database (yang kedua hanya untuk tes otomatis), misalnya:

```bash
createdb absensi_alat
createdb absensi_alat_test        # dipakai php artisan test (lihat phpunit.xml)
# atau: psql -U postgres -c "CREATE DATABASE absensi_alat;"  (dan absensi_alat_test)
```

Isi koneksi database di `.env` (`DB_HOST`, `DB_PORT`, `DB_DATABASE=absensi_alat`, `DB_USERNAME`,
`DB_PASSWORD`), lalu:

```bash
php artisan migrate
php artisan absensi:admin-password admin   # atur password admin (ditanya 2x, min. 8 karakter)
php artisan storage:link                   # agar foto anggota bisa diakses di /storage/...
```

`php artisan migrate` membuat semua tabel sekaligus:

- akun admin awal: username `admin`, email `admin@example.com`, **tanpa password** (tidak bisa login
  sampai `absensi:admin-password admin` dijalankan);
- **API key acak 24 karakter** untuk alat (lihat panel → **Pengaturan**);
- pengaturan bawaan: judul layar alat `Absensi RFID`, screensaver (3 detik per pengumuman, muncul
  setelah diam 30 detik).

Data contoh (opsional):

```bash
php artisan db:seed                              # 2 anggota contoh: Budi Santoso (0218893066), Siti Aminah (0287454020)
php artisan db:seed --class=AnnouncementSeeder   # 10 pengumuman contoh untuk screensaver
```

Menjalankan server:

```bash
./serve.sh            # = php artisan serve --host=127.0.0.1 --port=8133
# atau: php artisan serve
```

Buka `http://localhost:8133/admin` lalu masuk sebagai `admin`. `./serve.sh` hanya mendengarkan
`127.0.0.1`, jadi server tidak bisa diakses dari perangkat lain. Untuk mencoba alat lewat jaringan lokal
(LAN), jalankan `php artisan serve --host=0.0.0.0 --port=8133` dan pakai Base URL
`http://<ip-komputer>:8133/api/absensi` (izinkan port 8133 di firewall). Untuk akses dari internet, lihat
[Memasang di internet](#memasang-di-internet).

`.env.example` memakai `APP_ENV=local` dan `APP_DEBUG=true` untuk pengembangan. Tidak perlu
`php artisan config:cache`; `php artisan serve` membaca `.env` setiap request. Catatan: `php artisan serve`
meneruskan `APP_ENV` dari saat ia dijalankan, jadi perubahan `APP_ENV` baru berlaku setelah server
dijalankan ulang (`APP_DEBUG` langsung berlaku).

## Menghubungkan alat

Di menu **Pengaturan** alat, isi:

| Isian di alat | Nilai |
|---|---|
| Base URL | `<APP_URL>/api/absensi`, mis. `https://absensi.example.com/api/absensi` (alamat lengkapnya juga tampil di panel → **Pengaturan** → Koneksi alat) |
| API key | panel → **Pengaturan** → Koneksi alat |

Tekan "Tes koneksi". Alat otomatis muncul di halaman **Alat** (dikenali dari `X-Device-ID`),
tidak perlu didaftarkan dulu.

Cara mendaftarkan kartu baru: tempel kartu di alat (layar: KARTU TIDAK TERDAFTAR) → buka
**Kartu belum terdaftar** di panel → **Daftarkan** (nomor kartu terisi otomatis) → isi nama → Simpan.

## Memasang di internet

Alat harus bisa menjangkau server. Pilihan umum:

- **VPS + nginx**: arahkan *document root* ke `api/public` dengan PHP-FPM, atau jadikan nginx
  *reverse proxy* ke `./serve.sh` (`http://127.0.0.1:8133`). Pasang sertifikat HTTPS (mis. Let's Encrypt).
- **Cloudflare Tunnel** (tanpa membuka port): jalankan `cloudflared` di komputer yang sama dengan server
  dan arahkan ingress ke `http://localhost:8133`.

Hal yang perlu diperhatikan:

- Di `.env` server publik: `APP_ENV=production`, `APP_DEBUG=false` (galat hanya menampilkan pesan
  singkat, tanpa detail kode), dan `APP_URL=https://<domain-anda>`.
- **Proxy tepercaya**: `bootstrap/app.php` hanya memercayai proxy lokal
  (`trustProxies(at: ['127.0.0.1', '::1'])`), cocok untuk nginx/cloudflared yang berjalan di mesin yang
  sama. Header `X-Forwarded-*` dari alamat lain diabaikan. IP klien (untuk batas login & batas API)
  diambil dari `X-Forwarded-For`. Kalau proxy berada di mesin lain, tambahkan alamatnya di situ.
- **URL https**: middleware `SecureUrlsBehindTunnel` memaksa semua URL (termasuk `photo_url` foto anggota,
  redirect, dan form) memakai `https` dan memberi cookie sesi atribut `Secure` bila request datang lewat
  HTTPS (`X-Forwarded-Proto: https` dari proxy tepercaya) atau memakai host yang sama dengan `APP_URL`
  `https://…`. Akses langsung `http://127.0.0.1:8133` tetap memakai `http`.
- **Buat ulang API key** di panel → **Pengaturan** sebelum dipakai sungguhan, lalu isi ulang di alat.
- Folder `storage/` dan `bootstrap/cache/` harus bisa ditulis oleh proses PHP.
- Opsional: `composer install --no-dev --optimize-autoloader` di server produksi. Paket pengembangan
  Laravel Boost hanya aktif bila `APP_ENV=local` atau `APP_DEBUG=true`; untuk mematikannya sama sekali
  tambahkan `BOOST_BROWSER_LOGS_WATCHER=false` di `.env`.

## API standar `/api/absensi`

Lengkapnya di [`doc/spesifikasi-api.md`](../doc/spesifikasi-api.md). Ringkasnya:

| Endpoint | Fungsi |
|---|---|
| `GET /ping` | Tes koneksi. `{"ok":true,"message":"Terhubung ke <judul>","server_time":"…+07:00","config":{…}}` |
| `POST /tap` | Kartu ditempelkan → `check_in` / `check_out` / `duplicate` / `unknown` / `rejected` |
| `POST /check-in`, `POST /check-out` | Mode absen **Pilih Datang/Pulang** (firmware 1.6.0+): body & respons sama dengan `/tap` (+ `"mode"`), jenis tap ditentukan endpoint. Lihat [Mode absen](#mode-absen) |
| `POST /heartbeat` | Tiap 60 detik. Mencatat status alat, membalas `server_time` + `config` |
| `GET /announcements` | Pengumuman screensaver: `{"ok":true,"interval":3,"idle":30,"items":[{"id":"12","title":"…","description":"…","icon":"rapat"}]}` |
| `GET /firmware/{id}` | File `.bin` untuk update jarak jauh (URL-nya dari `config.firmware_update`). Hanya untuk alat yang dijadwalkan ke firmware itu, selain itu 404 |

- Header wajib: `X-API-Key` (salah/kosong → **401** `{"ok":false,"message":"API key salah"}`) dan
  `X-Device-ID` (kosong → **400**). `X-Spec-Version` diterima tanpa diperiksa.
- Body POST yang bukan objek JSON, `rfid` kosong/tidak valid, atau data lain yang formatnya salah
  → **400** `{"ok":false,"message":"…"}`.
- Batas request → **429** `{"ok":false,"message":"Terlalu banyak permintaan"}`:
  - 240 request/menit per alat (`X-Device-ID`) + IP. Alat normal hanya beberapa request per menit
    (heartbeat 1/menit, antrean paling cepat 1 tap/detik), jadi batas ini tidak tercapai.
  - API key salah 20 kali/menit dari satu IP → semua request dari IP itu dibalas 429 sampai menit itu
    lewat (memperlambat tebakan API key).
- Semua hasil bisnis → **HTTP 200**.
- `config` berisi `title` (judul layar), `pin` (PIN menu Pengaturan alat, hanya dikirim kalau diisi
  di panel), `dim_after` & `dim_level` (layar redup setelah diam sekian detik ke sekian persen, bawaan
  60 detik & 20 %), `announcements_rev` (penanda versi pengumuman), dan `restart_at` (jam restart harian
  alat ini, `"HH:MM"` menurut jam di layar alat; selalu dikirim, `""` = alat tidak restart otomatis; bawaan
  `"03:00"`, diatur per alat di halaman **Alat**), serta `tap_mode` (`"auto"` / `"select"`, hanya dikirim kalau
  mode absen alat itu diatur di halaman **Alat**).
- `GET /announcements`: hanya pengumuman aktif, urut nomor urutan, maks. 10; `id` berupa teks,
  `description` tidak dikirim kalau kosong. `interval`/`idle` dari menu **Pengumuman** (bawaan 3 dan 30 detik).
- `config.announcements_rev` = `"<jumlah pengumuman>-<unix perubahan terakhir>-<interval>-<idle>"`,
  mis. `"2-1790729112-3-30"`. Nilainya berubah setiap pengumuman ditambah/diubah/dihapus atau pengaturan
  screensaver diganti, sehingga alat mengambil ulang `/announcements` pada heartbeat berikutnya (±1 menit).
- `config.firmware_update` (`version`, `url`, `size`, `md5`) hanya dikirim kalau alat dijadwalkan update,
  versinya belum terpasang, dan belum pernah gagal dipasang di alat itu (`raw.ota_failed`). Lihat
  [Update firmware jarak jauh](#update-firmware-jarak-jauh).

```bash
curl -X POST http://localhost:8133/api/absensi/tap \
  -H "X-API-Key: <api-key>" -H "X-Device-ID: ABS-1A2B3C" -H "X-Spec-Version: 1" \
  -H "Content-Type: application/json" \
  -d '{"device_id":"ABS-1A2B3C","tap_id":"1A2B3C-5F3A9C21","rfid":"0218893066","tapped_at":"2026-09-30T07:45:12+07:00","queued":false,"raw":null}'
```

### Log request API

Semua request ke `/api/...` (termasuk yang ditolak 400/401/429) dicatat oleh middleware
`CatatLogApi` ke `storage/logs/api-YYYY-MM-DD.log`, terpisah dari `laravel.log`: alat (X-Device-ID),
seluruh parameter, balasan server, dan lama proses. API key hanya dicatat ada-tidaknya, dan PIN alat
disembunyikan. Kunci di log berbahasa Inggris: `method`, `url`, `device` (`id`, `registered`), `api_key`,
`spec_version`, `ip`, `user_agent`, `duration_ms`, `request`, `response` (`status`, `body`), `error`.
Disimpan 30 hari (ubah dengan `API_LOG_DAYS` di `.env`). Contoh melihat tap satu alat:

```bash
grep '"id":"ABS-1A2B3C"' storage/logs/api-$(date +%F).log | grep 'absensi/tap'
```

### Aturan tap di implementasi acuan ini

Per anggota, per hari kalender `Asia/Jakarta` (lihat `app/Services/AttendanceService.php`):

| Kondisi | `status` | `ok` | `message` | Lainnya |
|---|---|---|---|---|
| Nomor kartu tidak terdaftar | `unknown` | false | Kartu belum terdaftar | dicatat → muncul di "Kartu belum terdaftar" |
| Anggota nonaktif | `rejected` | false | Kartu nonaktif | `name` |
| < 60 detik dari tap diterima sebelumnya | `duplicate` | true | Sudah tercatat | `time` = jam tap sebelumnya |
| Tap diterima pertama hari itu | `check_in` | true | Selamat datang | `time` |
| Tap diterima berikutnya | `check_out` | true | Sampai jumpa | `time`, `info: ["Masuk 07:45"]` |

- Jeda duplikat 60 detik bisa diubah dengan `ABSENSI_DUPLICATE_WINDOW_SECONDS` di `.env`.
- `photo_url` (URL absolut) disertakan kalau anggota punya foto.
- **Tap antrean** (`queued: true`) memakai `tapped_at` dari alat. Waktu diterima yang dipakai kalau
  `tapped_at` `null`/tidak valid, lebih dari **5 menit di masa depan**, atau lebih dari **30 hari yang
  lalu** (jam alat kemungkinan salah). Tap biasa selalu memakai jam server.
- Tap antrean yang jamnya **lebih awal dari check-in yang sudah ada** di hari yang sama juga dicatat
  sebagai `check_in`; check-in yang lama tidak diubah (bisa saja dari alat lain dan bukan jam pulang).
  Jadi satu hari bisa punya dua `check_in`: rekap, dasbor, dan `info: ["Masuk …"]` selalu memakai yang
  paling awal, jam pulang tetap `check_out` terakhir.
- **`tap_id`** disimpan per alat. Tap dengan `tap_id` yang sudah pernah dicatat tidak dicatat lagi:
  dibalas `duplicate` (jam tap aslinya), atau hasil yang sama untuk `unknown`/`rejected`.
- JSON mentah dari alat disimpan di `attendances.payload`; body terakhir per alat di
  `devices.last_payload`.
- Nomor kartu disimpan sebagai 10 digit (mis. `0218893066`, yaitu UID `0A 0B 0C 0D` dibaca
  little-endian). Kartu lama yang dulu didaftarkan sebagai hex 4 byte (mis. `0A0B0C0D`) tetap dikenali
  karena nilainya sama.

### Mode absen

Butuh firmware alat **1.6.0** ke atas (spesifikasi bagian 4.1). Di halaman **Alat**, pilihan **Mode absen** per alat:
*Ikuti pengaturan di alat* (`devices.tap_mode` null, `config.tap_mode` tidak dikirim), *Otomatis (1 endpoint /tap)*
(`auto`), atau *Pilih Datang/Pulang di alat* (`select`: petugas memilih DATANG/PULANG di layar alat, tap dikirim ke
`/check-in` atau `/check-out`). Mode yang sedang dipakai alat dan pilihan saat ini (Datang / Pulang / belum dipilih)
diambil dari heartbeat (`raw.tap_mode`, `raw.tap_select`) dan ditampilkan di kartu alat.

| Endpoint | Kondisi | `status` | `message` | Lainnya |
|---|---|---|---|---|
| `/check-in` | Sudah ada `check_in` di tanggal yang sama | `duplicate` | Sudah absen datang | `time` = jam `check_in` pertama |
| `/check-in` | Selain itu | `check_in` | Selamat datang | `time` |
| `/check-out` | Ada `check_out` < 60 detik (jeda tap ganda) di tanggal yang sama | `duplicate` | Sudah tercatat | `time` = jam `check_out` itu |
| `/check-out` | Selain itu | `check_out` | Hati-hati di jalan | `info: ["Masuk tadi 07:45"]` atau `["Belum absen datang hari ini"]` |

Kartu tidak terdaftar / nonaktif, `tap_id` (berlaku lintas `/tap`, `/check-in`, `/check-out`), tap antrean, foto, dan
penyimpanan `payload` sama dengan `/tap`; barisnya masuk ke tabel `attendances` yang sama, jadi rekap tetap satu.
Kolom `devices.tap_mode`, `reported_tap_mode`, `tap_select` dibuat migrasi `2026_10_02_000001`; sebelum migrasi itu
dijalankan API & halaman Alat tetap jalan, hanya tanpa pengaturan mode absen.

### Kesehatan alat

Setiap `/heartbeat` menyimpan data kesehatan dari `raw` ke tabel `devices` (`uptime_s`, `reset_reason`,
`rfid_ok`, `queue`, `free_heap`, `min_free_heap`, `error_code`, `ota_failed`, `heartbeat_at`). Kejadian
penting dicatat di tabel `device_events` (200 terbaru per alat):

| Kejadian | Kapan |
|---|---|
| `boot` | `uptime_s` lebih kecil dari heartbeat sebelumnya (alat restart), beserta `reset_reason` |
| `crash` | `raw.crash` dikirim (crash yang sama dalam 10 menit tidak dicatat ulang) |
| `firmware` | Versi firmware berganti, mis. `Firmware 1.4.0 -> 1.5.0` |
| `ota_failed` | Alat melapor update firmware gagal (versi baru di `raw.ota_failed`) |

Halaman **Alat** menampilkan peringatan (`Device::healthIssues()`), dan **Dasbor** menampilkan daftar alat
yang perlu diperiksa. Batasnya konstanta di `app/Models/Device.php`: offline > 10 menit, pembaca RFID
tidak terdeteksi, kode error di layar, sinyal < −80 dBm, antrean > 20 tap, ≥ 3 restart tidak normal
(`watchdog`/`panic`/`brownout`) dalam 24 jam, update firmware gagal, RAM terendah < 20000 byte.

### Update firmware jarak jauh

Butuh firmware alat **1.5.0** ke atas.

1. Kompilasi firmware dengan `VERSI_FIRMWARE` baru di `config.h` (`cd firmware && ./upload.sh -c absensi`,
   lokasi file `.bin` dijelaskan di README firmware).
2. Panel → **Firmware** → unggah: versi (`1.5.0`), file `.bin` (maks. 1966080 byte = slot OTA partisi
   `min_spiffs`), catatan. Server menolak file yang byte pertamanya bukan `0xE9` (image aplikasi ESP32)
   atau yang tidak memuat teks versinya. File disimpan di `storage/app/private/firmware/<versi>.bin`.
3. Panel → **Alat** → "Update firmware ke" per alat, atau **Terapkan ke semua alat** di halaman Firmware.
   Pada heartbeat berikutnya alat menerima `config.firmware_update`, mengunduh `GET /api/absensi/firmware/{id}`
   (dengan `X-API-Key`; dibalas `application/octet-stream`, `Content-Length`, dan header `x-MD5`), lalu
   restart. Status per alat: *Menunggu alat mengunduh*, *Sudah terpasang*, atau *Gagal, kembali ke …*.

Alat tidak memeriksa sertifikat HTTPS, jadi pihak yang bisa menyadap jaringan alat bisa saja memasang
firmware palsu. Jadwalkan update hanya untuk alat yang memang ingin diupdate, lalu kosongkan lagi bila perlu.
Unggahan ±2 MB juga butuh `upload_max_filesize` dan `post_max_size` PHP minimal 2M (bawaan PHP sudah cukup).

### Foto anggota

Diunggah di panel (JPG/PNG, maks. 8 MB), lalu diproses dengan GD menjadi **JPEG baseline**, muat di
dalam **160×160** piksel, **≤ 30 KB** (kualitas diturunkan sampai muat), dan disimpan di
`storage/app/public/anggota/`. Lihat `app/Services/MemberPhoto.php`.

## Panel admin `/admin`

Masuk dengan **username atau email** + password. Salah 5 kali untuk akun yang sama dari satu IP →
dikunci 60 detik; selain itu maksimal 10 percobaan login per menit per IP.

| Menu | Isi |
|---|---|
| **Dasbor** | Hari ini: masuk / pulang / belum hadir, 20 tap terakhir (diperbarui tiap 10 detik), status alat (aktif bila terlihat < 3 menit), daftar alat yang perlu diperiksa |
| **Anggota** | Cari, tambah, ubah (nama, NIS/NIP, nomor kartu, foto, aktif), hapus |
| **Kehadiran** | Semua tap kartu pada tanggal yang dipilih (bisa dicari); hapus satu tap atau semua tap tanggal itu (mis. untuk mengulang tes) |
| **Kartu belum terdaftar** | Nomor kartu yang pernah ditempel tapi belum punya pemilik → tombol "Daftarkan" (nomor kartu terisi otomatis) |
| **Rekap** | Rentang tanggal, per anggota per hari: jam masuk pertama & jam pulang terakhir; ekspor CSV |
| **Alat** | Satu kartu per alat: status Online/Offline, **peringatan kesehatan**, terakhir terlihat, firmware, IP, WiFi & RSSI, lama menyala, alasan restart terakhir, pembaca RFID, antrean offline, RAM, kode error di layar, tap hari ini, dan **riwayat 10 kejadian terakhir** (restart, crash, ganti firmware, update gagal). Bisa diubah: nama, **PIN menu Pengaturan per alat** (opsional, 4–8 digit), **jam restart harian per alat** (bawaan 03:00, sebaiknya jam sepi; kosongkan = tidak restart otomatis), dan **target update firmware**. Galat isian tampil di kartu alat yang bersangkutan |
| **Firmware** | Unggah file `.bin` untuk update jarak jauh (versi, ukuran, MD5, jumlah alat yang dijadwalkan), **Terapkan ke semua alat**, hapus |
| **Pengumuman** | Pengumuman untuk screensaver alat: judul (maks. 40), deskripsi (maks. 160), ikon, aktif, urutan; **aksi massal** (tampilkan / sembunyikan / hapus yang dicentang); plus pengaturan screensaver (lama tiap pengumuman 2–60 detik, muncul setelah diam 5–600 detik). Sampai ke alat dalam ±1 menit |
| **Pengaturan** | Base URL & API key (bisa dibuat ulang), judul layar alat dan **layar redup** (redup setelah 0 atau 10–3600 detik, kecerahan 0–100 %; untuk semua alat), ganti password |

## Perintah Artisan

Daftar lengkap: `php artisan list absensi`.

```bash
php artisan absensi:admin-password admin                       # atur/ganti password admin (tersembunyi, 2x)
php artisan absensi:admin-create operator operator@example.com # akun admin baru (tanpa password)
php artisan absensi:member-create "Andi Wijaya" 1234567890 --identifier=1003
php artisan absensi:member-list
php artisan absensi:member-deactivate 1234567890 [--activate]
php artisan absensi:unknown-cards [--limit=20] [--all]
php artisan absensi:report [2026-09-30]                        # rekap satu hari di terminal
php artisan absensi:device-create "Nama alat"                  # USANG: kunci alat untuk API lama /api/v1
```

## Struktur kode

```
routes/api.php                          /api/absensi (standar) dan /api/v1 (usang)
routes/web.php                          panel /admin
bootstrap/app.php                       proxy tepercaya, middleware, format galat API
app/Http/Middleware/AuthenticateAbsensiDevice.php   cek X-API-Key, X-Device-ID, catat alat
app/Http/Middleware/CatatLogApi.php     log semua request API ke storage/logs/api-*.log
app/Http/Middleware/SecureUrlsBehindTunnel.php      URL https & cookie Secure di belakang proxy
app/Http/Controllers/Api/AbsensiController.php      ping / tap / heartbeat / announcements / firmware
app/Services/AttendanceService.php      aturan masuk / pulang / duplikat
app/Services/MemberPhoto.php            olah foto untuk layar alat
app/Http/Controllers/Admin/*            halaman panel
app/Models/Setting.php                  api_key, title, screensaver_interval, screensaver_idle, dim_after, dim_level
app/Models/Announcement.php             pengumuman, isi GET /announcements & announcements_rev
app/Models/Device.php                   alat, data kesehatan dari heartbeat, peringatan (healthIssues)
app/Models/DeviceEvent.php              riwayat kejadian alat (boot, crash, firmware, ota_failed)
app/Models/FirmwareRelease.php          file firmware untuk update jarak jauh
config/absensi.php                      jeda duplikat (ABSENSI_DUPLICATE_WINDOW_SECONDS)
resources/views/admin/*                 tampilan Blade
public/css/admin.css, public/js/admin.js
```

## API lama `/api/v1` (USANG)

Masih aktif hanya untuk firmware lama yang sudah terpasang; **jangan dipakai untuk alat baru**, dan
akan dihapus setelah semua alat memakai `/api/absensi`. Header `X-Device-Key` (kunci per alat, dibuat
dengan `php artisan absensi:device-create "Nama"`; hanya hash yang disimpan). Endpoint:
`GET /api/v1/ping`, `POST /api/v1/tap` (`{"uid":"A1B2C3D4"}`), `GET /api/v1/attendances/today`.
Respons lama tidak berubah (`unknown_card` → 404, `inactive` → 403, dst.). Tap dari API lama
tercatat di tabel yang sama, jadi tetap tampil di panel.

## Test

```bash
php artisan test        # memakai database absensi_alat_test & APP_ENV=testing (lihat phpunit.xml)
./vendor/bin/pint       # format kode
```

Database tes memakai pengguna/password yang sama dengan `.env` (`DB_USERNAME`, `DB_PASSWORD`); hanya
nama database yang diganti `phpunit.xml`.
