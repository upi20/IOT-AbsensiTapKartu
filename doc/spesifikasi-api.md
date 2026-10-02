# Spesifikasi API Absensi RFID Terintegrasi (v1)

Dokumen ini untuk **pengembang aplikasi** yang ingin menghubungkan aplikasinya dengan alat absensi RFID. Aplikasi Anda cukup menyediakan **3 endpoint wajib** (`/ping`, `/tap`, `/heartbeat`), ditambah endpoint opsional untuk pengumuman (`/announcements`), mode pilih Datang/Pulang (`/check-in` & `/check-out`, bagian 4.1), dan update firmware jarak jauh (bagian 6.1). Logika bisnis sepenuhnya milik aplikasi: siapa pemilik kartu, kapan dianggap masuk atau pulang, terlambat atau tidak.

Versi spesifikasi: `1` · Terakhir diperbarui: 2 Oktober 2026

> Dokumen ini adalah **kontrak teknis** alat ↔ aplikasi. Untuk gambaran lengkap apa saja yang perlu dibangun di aplikasi Anda (6 fitur wajib: Kartu belum terdaftar, Rekap, Alat, Firmware, Pengumuman, Pengaturan), urutan kerja, dan cara mengujinya dengan Postman, baca **[Panduan Integrasi Aplikasi](integrasi-aplikasi.md)**.

---

## 1. Cara kerja singkat

```
[Kartu RFID] ─tap─▶ [Alat]  ──── POST {base}/tap ─────────▶ [Aplikasi Anda]
                     │     ◀──── hasil (masuk/pulang, nama, foto) ─┘
                     │
                     ├──── POST {base}/heartbeat (tiap 60 detik) ─▶ status alat aktif
                     ├──── GET  {base}/ping (tes koneksi & jam) ──▶
                     ├──── GET  {base}/announcements (opsional, screensaver) ──▶
                     └──── GET  config.firmware_update.url (opsional, update firmware) ──▶
```

Semua pengaturan dilakukan di alat lewat layar sentuh (menu **Pengaturan**, dilindungi PIN):

| Pengaturan di alat | Contoh |
|---|---|
| WiFi (pilih dari hasil scan, atau ketik manual) + password | `Kantor-2.4G` |
| **Base URL** endpoint | `http://192.168.1.10/api/absensi` atau `https://app.contoh.com/api/absensi` |
| **API key** (teks bebas) | `rahasia-kantor-123` |

Alat memanggil `{base}/ping`, `{base}/tap`, `{base}/heartbeat`, dan (opsional) `{base}/announcements`. Di mode absen **Pilih Datang/Pulang**, tap dikirim ke `{base}/check-in` atau `{base}/check-out` sebagai ganti `/tap` (bagian 4.1).

- **HTTP dan HTTPS sama-sama didukung.** Untuk HTTPS, alat tidak memeriksa sertifikat.
- **Jaringan WiFi harus 2.4 GHz.**

## 2. Aturan umum

**Header yang selalu dikirim alat:**

| Header | Isi |
|---|---|
| `X-API-Key` | API key yang diisi di alat. Aplikasi wajib menolak kunci yang salah dengan HTTP `401` |
| `X-Device-ID` | ID unik alat, contoh `ABS-0001`. Diatur produsen di firmware sebelum alat di-upload, tampil di layar alat, dan **tidak berubah** walaupun alat direset pabrik. Kalau berbeda dengan `device_id` di body, yang dipakai header |
| `X-Spec-Version` | Versi spesifikasi yang dipakai alat, saat ini `1` |
| `Content-Type` | `application/json` (untuk POST) |
| `Accept` | `application/json` |

**Respons:**
- Dalam bentuk JSON, **HTTP 200** untuk semua hasil bisnis, termasuk kartu tidak dikenal dan `duplicate`.
- Request yang formatnya salah (misal `rfid` kosong, body bukan JSON, atau tanpa header `X-Device-ID`) dijawab **HTTP 400**. Kode lain (`401`, `404`, `500`, dan seterusnya) dianggap galat koneksi/server. Kalau responsnya JSON dan berisi `message`, pesan itu ditampilkan di layar.
- Batas waktu tunggu alat:
  - `/tap` (juga `/check-in` & `/check-out`): **3 detik**. Kalau tidak ada jawaban, tap disimpan di antrean (TERSIMPAN), supaya orang berikutnya tidak ikut menunggu.
  - `/heartbeat`, `/announcements`, dan kiriman antrean: **3 detik**. Semuanya hanya dikirim saat alat sedang diam.
  - `/ping` (saat alat menyala dan tombol "Tes koneksi"): **8 detik**.
  - Karena itu **balas `/tap` secepat mungkin**, idealnya di bawah 1 detik.
- Teks yang tampil di layar sebaiknya pendek. Kalau terlalu panjang, alat memotongnya.

**Format data:**
- Semua waktu memakai format ISO 8601 dengan zona waktu, contoh `2026-09-30T07:45:12+07:00`.
- **Nomor kartu (`rfid`)** berupa teks **10 digit desimal**, sama seperti pembaca RFID USB atau mesin absensi pada umumnya.
  - Contoh: UID `0A 0B 0C 0D` → `"0218893066"`.
  - Kartu dengan UID 7 atau 10 byte dikirim sebagai hex huruf besar (contoh `"04A1B2C3D4E5F6"`).

## 3. `GET {base}/ping`

Dipakai untuk tombol **"Tes koneksi"** di alat dan untuk menyamakan jam.

**Respons:**
```json
{
  "ok": true,
  "message": "Terhubung ke Aplikasi Contoh",
  "server_time": "2026-09-30T07:45:12+07:00",
  "config": { "pin": "4321", "title": "PT Contoh Sejahtera", "dim_after": 60, "dim_level": 20, "restart_at": "03:00" }
}
```

| Field | Wajib | Keterangan |
|---|---|---|
| `ok` | ✅ | `true` kalau API key benar |
| `message` | – | Ditampilkan di layar saat tes koneksi |
| `server_time` | disarankan | Jam alat disamakan dengan waktu ini. Zona waktu tampilan alat ikut offset-nya (`+07:00`) |
| `config` | – | Pengaturan jarak jauh (lihat bagian 6) |

Server boleh membatasi jumlah request dan membalas **HTTP 429**. Alat menganggapnya gagal dan mencoba lagi nanti.

## 4. `POST {base}/tap`

Dikirim setiap kali kartu ditempelkan. **Aplikasi yang menentukan** apakah tap itu masuk (check-in), pulang (check-out), atau ditolak.

**Isi (body) yang dikirim alat:**
```json
{
  "device_id": "ABS-1A2B3C",
  "tap_id": "1A2B3C-5F3A9C21",
  "rfid": "0218893066",
  "tapped_at": "2026-09-30T07:45:12+07:00",
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

| Field | Selalu ada | Keterangan |
|---|---|---|
| `device_id` | ✅ | Sama dengan header `X-Device-ID` |
| `tap_id` | ✅ | ID unik tap, dibuat alat. **Sama persis saat tap yang sama dikirim ulang** dari antrean. Aplikasi sebaiknya menyimpan `tap_id` dan tidak mencatat dua kali tap dengan `tap_id` yang sudah ada (cukup balas hasil yang sama, atau `duplicate`) |
| `rfid` | ✅ | Nomor kartu (lihat bagian 2) |
| `tapped_at` | ✅ (bisa `null`) | Waktu tap menurut jam alat. `null` kalau jam alat belum tersinkron |
| `queued` | ✅ | `true` kalau tap ini sempat tersimpan di alat karena server tidak bisa dihubungi, lalu dikirim belakangan (lihat bagian 7) |
| `raw` | – | Informasi tambahan, berupa objek atau `null`. **Boleh diabaikan.** Isi dan kuncinya bisa bertambah di versi firmware berikutnya |

**Respons yang diharapkan dari aplikasi:**
```json
{
  "ok": true,
  "status": "check_in",
  "name": "Budi Santoso",
  "message": "Selamat datang",
  "time": "07:45",
  "photo_url": "https://app.contoh.com/foto/budi-160.jpg",
  "info": ["Shift pagi", "Terlambat 5 menit"]
}
```

| Field | Wajib | Keterangan |
|---|---|---|
| `ok` | ✅ | `true` untuk `check_in`, `check_out`, dan `duplicate`. `false` untuk `unknown` dan `rejected` |
| `status` | ✅ | Salah satu dari tabel di bawah |
| `name` | – | Nama pemilik kartu |
| `message` | disarankan | Pesan utama di layar (maks. ±32 karakter) |
| `time` | – | Jam yang dicatat aplikasi (`HH:MM`). Kalau kosong, alat memakai jamnya sendiri |
| `photo_url` | – | Foto pemilik kartu (lihat di bawah). Boleh `null` atau dihilangkan |
| `info` | – | Maksimal 2 baris keterangan tambahan (maks. ±40 karakter per baris) |

**Nilai `status` dan tampilannya di alat:**

| `status` | Tampilan di alat | Warna / bunyi |
|---|---|---|
| `check_in` | **MASUK** | hijau, 1 bip |
| `check_out` | **PULANG** | biru, 1 bip |
| `duplicate` | **SUDAH TERCATAT** | kuning, 2 bip |
| `unknown` | **KARTU TIDAK TERDAFTAR** + nomor kartu | merah, 3 bip |
| `rejected` | **DITOLAK** (alasan di `message`, misal "Di luar jam kerja") | merah, 3 bip |

Nilai `status` lain yang tidak dikenal alat akan ditampilkan sebagai **INFO** (abu-abu, 1 bip) dengan isi `message`.

**Foto (`photo_url`):**
- Format **JPEG baseline** (bukan progressive), ukuran **maksimal 160×160 piksel**, **maksimal 30 KB**.
- Alat mengunduh foto setelah respons diterima, dengan batas 3 detik. Kalau gagal atau terlalu besar, foto dilewati dan tampilan tetap normal.
- Boleh HTTP atau HTTPS.

### 4.1 Mode pilih: `POST {base}/check-in` & `POST {base}/check-out` (opsional)

Butuh firmware **1.6.0** ke atas. Sebagian aplikasi ingin endpoint terpisah untuk **datang** dan **pulang**. Untuk itu alat punya pengaturan **Mode absen**:

| Mode | Cara kerja di alat |
|---|---|
| `auto` (Otomatis, bawaan) | Seperti bagian 4: semua tap dikirim ke `POST {base}/tap`, aplikasi yang menentukan masuk atau pulang |
| `select` (Pilih Datang/Pulang) | Layar utama alat punya 2 tombol **DATANG** dan **PULANG**. Petugas memilih **sekali** (bukan tiap tap), lalu semua tap berikutnya dikirim ke `/check-in` atau `/check-out` sesuai pilihan sampai diganti |

- Pilihan tersimpan di alat dan **otomatis dikosongkan saat tanggal berganti**, jadi harus dipilih lagi setiap hari.
- Kalau belum memilih lalu kartu ditempelkan, alat menampilkan **"Pilih DATANG atau PULANG dulu"** dan **tidak mengirim apa pun**.
- Mode absen diubah dari menu Pengaturan alat, atau dari aplikasi lewat `config.tap_mode` (bagian 6). Mode yang sedang dipakai dan pilihan saat ini dilaporkan di heartbeat (`raw.tap_mode`, `raw.tap_select`, bagian 5).
- `/tap` **tidak berubah**. Aplikasi yang tidak menyediakan `/check-in` & `/check-out` cukup tidak memakai mode `select` (kalau dipakai juga, alat mendapat HTTP 404 dan menampilkan `E23`).

**Header** sama persis dengan `/tap` (`X-API-Key`, `X-Device-ID`, `X-Spec-Version`, `Content-Type`, `Accept`).

**Isi (body)** sama persis dengan `/tap`, ditambah field `mode`: `"check_in"` di `/check-in`, `"check_out"` di `/check-out`.

```json
{
  "device_id": "ABS-1A2B3C",
  "tap_id": "1A2B3C-5F3A9C22",
  "rfid": "0218893066",
  "tapped_at": "2026-09-30T16:02:05+07:00",
  "queued": false,
  "mode": "check_out",
  "raw": { "uid_hex": "0A0B0C0D", "firmware": "1.6.0" }
}
```

**Respons** formatnya sama persis dengan `/tap` (`ok`, `status`, `name`, `message`, `time`, `photo_url`, `info`), begitu juga kode HTTP dan aturan galatnya (`401`, `400`, `429`/`5xx`/timeout → antrean, dan seterusnya). Contoh `/check-out`:

```json
{
  "ok": true,
  "status": "check_out",
  "name": "Budi Santoso",
  "message": "Hati-hati di jalan",
  "time": "16:02",
  "info": ["Masuk tadi 07:45"]
}
```

Contoh `/check-in` saat anggota sudah absen datang hari itu:

```json
{ "ok": true, "status": "duplicate", "name": "Budi Santoso", "message": "Sudah absen datang", "time": "07:45" }
```

**Aturan:**
- **`tap_id` berlaku lintas endpoint.** Tap dengan `tap_id` yang sudah pernah dicatat (di `/tap`, `/check-in`, atau `/check-out`) tidak dicatat dua kali; cukup balas hasil yang sama (atau `duplicate`).
- **`queued` + `tapped_at` sama dengan `/tap`** (bagian 7). Tap antrean dikirim ulang ke endpoint yang sama dengan saat tap.
- Tap dari endpoint ini masuk ke **data absensi yang sama** dengan `/tap`, jadi rekap tetap satu.
- `status` harus salah satu nilai di bagian 4. Aplikasi boleh punya aturan sendiri. Aturan di server acuan (`api/`), per anggota per tanggal (waktu efektif tap):

| Endpoint | Kondisi | `status` | `message` | Lainnya |
|---|---|---|---|---|
| keduanya | Kartu tidak terdaftar | `unknown` | Kartu belum terdaftar | sama dengan `/tap` |
| keduanya | Kartu nonaktif | `rejected` | Kartu nonaktif | sama dengan `/tap` |
| `/check-in` | Sudah ada `check_in` di tanggal yang sama | `duplicate` | Sudah absen datang | `time` = jam `check_in` pertama hari itu |
| `/check-in` | Selain itu | `check_in` | Selamat datang | `time` |
| `/check-out` | Ada `check_out` di tanggal yang sama dalam jeda tap ganda (bawaan 60 detik) | `duplicate` | Sudah tercatat | `time` = jam `check_out` itu |
| `/check-out` | Selain itu | `check_out` | Hati-hati di jalan | `time`, `info: ["Masuk tadi 07:45"]` kalau ada `check_in` hari itu, atau `["Belum absen datang hari ini"]` |

## 5. `POST {base}/heartbeat`

Dikirim **setiap 60 detik** sebagai tanda alat aktif, dan sekali lagi segera setelah alat terhubung ke WiFi.

**Isi:**
```json
{
  "device_id": "ABS-1A2B3C",
  "firmware": "1.1.0",
  "time": "2026-09-30T07:46:00+07:00",
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

Isi `raw` adalah info kesehatan alat. Semuanya **opsional untuk aplikasi**: boleh disimpan untuk pemantauan, boleh diabaikan.

| Field `raw` | Keterangan |
|---|---|
| `wifi_ssid`, `rssi`, `ip` | Nama WiFi, kekuatan sinyal (dBm, di bawah −80 = lemah), IP alat |
| `uptime_s` | Lama alat menyala sejak boot (detik). Nilai yang **lebih kecil** dari heartbeat sebelumnya berarti alat baru restart |
| `free_heap` | RAM bebas saat ini (byte) |
| `min_free_heap` | RAM bebas **terendah** sejak boot (byte). Firmware **1.5.0** ke atas |
| `reset_reason` | Alasan restart terakhir: `poweron` (baru dinyalakan / tombol EN), `external`, `software` (restart oleh program, mis. restart harian atau setelah update), `panic` (program error), `watchdog` (program macet), `brownout` (listrik turun, cek adaptor/kabel daya), `deepsleep`, `other` |
| `rfid_ok` | `false` = pembaca RFID tidak terdeteksi |
| `queue` | Jumlah tap offline yang masih menunggu dikirim |
| `error` | Kode error yang **sedang tampil di layar alat**, mis. `"E30"`. Tidak dikirim kalau tidak ada error. Firmware **1.5.0** ke atas |
| `ota_failed` | Versi firmware yang **gagal dipasang** lewat update jarak jauh (alat kembali ke versi lama), mis. `"1.5.0"`. Tidak dikirim kalau tidak ada. Firmware **1.5.0** ke atas |
| `tap_mode` | Mode absen yang sedang dipakai alat: `"auto"` atau `"select"` (bagian 4.1). Firmware **1.6.0** ke atas |
| `tap_select` | Pilihan saat ini di mode `select`: `"check_in"` (DATANG), `"check_out"` (PULANG), atau `null` (belum memilih / mode `auto`). Firmware **1.6.0** ke atas |
| `crash` | Hanya dikirim setelah alat **crash**, sampai ada heartbeat yang berhasil (jadi bisa terkirim beberapa kali): `{"task":"loopTask","pc":"0x400d4634","backtrace":"0x400d4634 0x400880ed","elf":"<build id>"}`, semuanya teks. `backtrace` = daftar alamat PC dipisah spasi, `elf` = ID build firmware (untuk mencocokkan alamat dengan file ELF saat dianalisis). Firmware **1.5.0** ke atas |

**Kode error di layar alat (`raw.error`):**

| Kode | Arti | Kode | Arti |
|---|---|---|---|
| `E10` | Nama WiFi tidak ditemukan | `E22` | Alat ditolak server (403) |
| `E11` | Password WiFi salah | `E23` | Alamat API tidak ditemukan (404) |
| `E12` | Gagal tersambung ke WiFi (lainnya) | `E24` | Server error (5xx) |
| `E13` | Tidak mendapat IP dari router (DHCP) | `E25` | Balasan server tidak valid |
| `E20` | Server tidak terjangkau / DNS gagal | `E26` | Server tidak menjawab (timeout) |
| `E21` | API key salah (401) | `E30` | Pembaca RFID tidak terdeteksi |
| | | `E31` | Jam belum sinkron |

**Respons:**
```json
{ "ok": true, "server_time": "2026-09-30T07:46:01+07:00", "config": { "pin": "4321" } }
```

`time` bernilai `null` kalau jam alat belum tersinkron.

`server_time` disarankan (dipakai untuk menjaga jam alat tetap tepat). Aplikasi cukup mencatat "terakhir terlihat" untuk `device_id` itu. Alat dianggap **tidak aktif** kalau tidak ada heartbeat selama lebih dari ±3 menit.

Seperti `/ping`, server boleh membalas **HTTP 429** kalau request terlalu sering. Alat menganggapnya gagal dan mencoba lagi nanti.

## 6. Pengaturan jarak jauh (`config`)

Objek `config` boleh disertakan di respons `/ping` dan `/heartbeat`. Semua isinya opsional, dan alat hanya mengubah pengaturan yang dikirim.

| Kunci | Contoh | Efek di alat |
|---|---|---|
| `pin` | `"4321"` | Mengganti **PIN menu Pengaturan** alat ini (4–8 digit, berupa teks). Setiap alat boleh punya PIN berbeda, jadi aplikasi mengirim PIN sesuai `X-Device-ID`. PIN tersimpan di alat, sehingga tetap berlaku walau server mati |
| `title` | `"PT Contoh Sejahtera"` | Judul di layar utama (maks. ±30 karakter) |
| `dim_after` | `60` | Lampu layar **meredup** setelah alat tidak disentuh dan tidak ada kartu selama sekian detik. `0` = tidak pernah meredup. Nilai sah: `0` atau `10`–`3600`. **Bawaan 60** |
| `dim_level` | `20` | Kecerahan lampu layar saat redup, dalam persen (`0`–`100`, `0` = mati). **Bawaan 20** |
| `restart_at` | `"03:00"` | **Restart harian** alat ini pada jam tersebut (format `HH:MM` 24 jam, jam yang tampil di alat). Alat hanya restart kalau sedang diam (2 menit tanpa kartu/sentuhan), dalam 30 menit sejak jam itu, dan sudah menyala minimal 1 jam. `""` = tidak restart otomatis. Per alat, seperti `pin`. **Bawaan `"03:00"`** |
| `announcements_rev` | `"7-1759212345"` | Penanda versi daftar pengumuman (teks bebas). Kalau berubah, alat segera mengambil ulang `GET /announcements` (lihat bagian 8) |
| `tap_mode` | `"select"` | **Mode absen** alat ini (bagian 4.1): `"auto"` = semua tap ke `/tap`; `"select"` = petugas memilih DATANG/PULANG di alat, tap ke `/check-in` / `/check-out`. Tidak dikirim atau `null` = alat memakai pengaturannya sendiri (diubah dari menu alat). Nilai lain diabaikan. Per alat, seperti `pin`. Firmware **1.6.0** ke atas |
| `firmware_update` | `{"version":"1.5.0","url":"…","size":1523456,"md5":"…"}` | **Update firmware jarak jauh** (lihat 6.1). Tidak dikirim = tidak ada update. Aplikasi lain boleh tidak pernah mengirimnya |

- Nilai di luar aturan (PIN bukan 4–8 digit, judul lebih dari 30 karakter, `dim_after` 1–9 atau lebih dari 3600, `dim_level` di luar 0–100, atau bukan angka bulat, `restart_at` bukan `HH:MM` dan bukan `""`) **diabaikan** oleh alat.
- `dim_after` dan `dim_level` tersimpan di alat. Kalau server tidak mengirimnya, alat memakai nilai terakhir yang diterima (atau bawaan 60 detik / 20 %). Butuh firmware **1.3.0** ke atas; firmware lama mengabaikannya.
- `restart_at` juga tersimpan di alat (bawaan `"03:00"`), butuh firmware **1.4.0** ke atas. Restart harian membersihkan memori; antrean tap tetap tersimpan. Alat juga punya **watchdog**: kalau program macet lebih dari 60 detik, alat restart sendiri. Alasan restart terakhir dikirim di heartbeat sebagai `raw.reset_reason` (`poweron`, `software`, `watchdog`, `panic`, `brownout`, ...).
- Saat layar redup, **kartu tetap diproses** dan lampu langsung terang lagi. Sentuhan pertama hanya menyalakan lampu dan tidak menekan tombol apa pun.
- `server_time` yang berakhiran `Z` (UTC) tetap dipakai untuk mengatur jam, tetapi zona tampilan alat tidak berubah.
- **PIN bawaan pabrik: `2026`.** Nilai ini tertanam di firmware dan dipakai sampai diganti dari menu alat atau lewat `config.pin`.
- **Reset pabrik:** tekan tombol **EN 3 kali dalam 5 detik**, lalu konfirmasi di layar. Yang terhapus: WiFi, Base URL, API key, PIN (kembali ke `2026`), judul, zona waktu, kalibrasi layar sentuh, antrean tap offline, dan pengumuman tersimpan. **ID alat tidak terhapus.** Menu Pengaturan juga punya tombol "Reset pabrik".

### 6.1 Update firmware jarak jauh (`config.firmware_update`, opsional)

Butuh firmware **1.5.0** ke atas. Fitur ini sepenuhnya opsional: aplikasi yang tidak mengirim `firmware_update` tidak perlu menyediakan apa pun, dan alat mengabaikan kunci yang tidak ada.

```json
"config": {
  "firmware_update": {
    "version": "1.5.0",
    "url": "https://app.contoh.com/api/absensi/firmware/3",
    "size": 1523456,
    "md5": "9e107d9d372bb6826bd81d3542a419d6"
  }
}
```

| Field | Keterangan |
|---|---|
| `version` | Versi firmware baru. Alat hanya memasang kalau berbeda dengan versinya sendiri |
| `url` | URL **absolut** file `.bin` (image aplikasi ESP32, bukan file gabungan/bootloader) |
| `size` | Ukuran file (byte). Maksimal **1966080** (slot OTA partisi `min_spiffs`) |
| `md5` | MD5 file (32 huruf hex kecil). Alat menolak file yang MD5-nya tidak cocok |

**Alur di alat:** setelah menerima `firmware_update`, alat mengunduh file dari `url`, memeriksa ukuran & MD5, memasangnya ke slot OTA, lalu restart. Kalau firmware baru gagal menyala, alat kembali ke versi lama dan mengirim `raw.ota_failed: "<versi>"` di heartbeat. Selama itu, aplikasi **sebaiknya berhenti mengirim** `firmware_update` untuk versi yang sama (perbaiki lalu terbitkan versi baru).

Kirim `firmware_update` hanya kalau alat itu memang dijadwalkan update **dan** `version` berbeda dengan `firmware` yang dilaporkan alat **dan** berbeda dengan `raw.ota_failed`.

**Unduhan `GET {url}`:**
- Alat mengirim header `X-API-Key`, `X-Device-ID`, dan `X-Spec-Version` **hanya kalau `url` punya origin (skema + host + port) yang sama dengan Base URL**. Ke host lain (mis. CDN), header itu tidak dikirim, jadi URL-nya harus bisa diunduh tanpa autentikasi.
- Balas **HTTP 200** dengan isi file `.bin` mentah (`Content-Type: application/octet-stream`) dan header **`Content-Length`** yang benar. Jangan memakai redirect. Header `x-MD5` (MD5 file) boleh disertakan.
- Server acuan (`api/`): `GET {base}/firmware/{id}`, hanya untuk alat yang dijadwalkan ke firmware itu (selain itu **404** `{"ok":false,"message":"Firmware tidak tersedia untuk alat ini"}`).

> **Keamanan.** Alat tidak memeriksa sertifikat HTTPS, dan MD5 hanya mendeteksi file rusak, bukan file palsu. Siapa pun yang bisa menyadap atau membelokkan lalu lintas antara alat dan server (WiFi publik, router yang dibobol, DNS palsu) bisa memasang firmware buatannya sendiri. Aktifkan update hanya per alat dengan sengaja, saat memang ada versi baru, dan sebaiknya di jaringan yang dipercaya.

## 7. Saat server tidak bisa dihubungi (antrean)

- Kalau `/tap` gagal karena jaringan (timeout, tidak ada WiFi, atau HTTP 5xx), alat **menyimpan tap itu** (maksimal **200 tap**, tetap tersimpan walau alat mati), lalu menampilkan **"TERSIMPAN – akan dikirim"**.
- Begitu server bisa dihubungi lagi, alat mengirim antrean satu per satu dengan `"queued": true` dan `tapped_at` asli. Respons untuk tap antrean tidak ditampilkan, cukup dijawab dengan `ok`.
- Aplikasi sebaiknya memakai `tapped_at` (bukan waktu diterima) untuk tap yang `queued: true`. Kalau `tapped_at` bernilai `null` (jam alat belum tersinkron), pakai waktu diterima.
- Aplikasi boleh mengganti `tapped_at` antrean dengan waktu diterima kalau nilainya tidak masuk akal: lebih dari 5 menit di masa depan, atau lebih lama dari 30 hari. Ini pilihan server; server acuan (`api/`) melakukannya.
- Untuk tap antrean, alat hanya melihat kode HTTP. **2xx, 3xx, atau 4xx (kecuali 429)** → tap dihapus dari antrean, apa pun isi balasannya (3xx diperlakukan seperti 4xx, redirect tidak diikuti). **429, 5xx, atau timeout** → tap tetap di antrean dan dicoba lagi nanti.
- Karena tap bisa terkirim ulang (misal balasan server terlambat lewat 3 detik padahal tap sudah tersimpan), pakai `tap_id` untuk mencegah catatan ganda.
- Tap yang ditolak dengan HTTP `4xx` (misalnya API key salah) **tidak** masuk antrean.
- Semua aturan ini juga berlaku untuk `/check-in` & `/check-out` (mode pilih, bagian 4.1). Tap antrean dikirim ulang ke endpoint yang sama dengan saat tap.

## 8. Pengumuman / screensaver (opsional)

Kalau alat tidak dipakai selama beberapa saat, layar menampilkan **pengumuman bergantian**: ikon, judul, dan deskripsi. Jam, tanggal, dan judul instansi tetap tampil kecil di tepi layar.
- **Kartu tetap bisa di-tap** selama screensaver, dan diproses seperti biasa.
- **Sentuh layar** untuk kembali ke layar utama.

Isi pengumuman diambil otomatis dari aplikasi, jadi tidak ada yang perlu diisi di alat.

### `GET {base}/announcements`

**Respons:**
```json
{
  "ok": true,
  "interval": 3,
  "idle": 30,
  "items": [
    { "id": "12", "title": "Rapat Guru", "description": "Hari ini pukul 13.00 di ruang aula lantai 2.", "icon": "rapat" },
    { "id": "13", "title": "Libur Nasional", "description": "Kamis, 2 Oktober 2026 kantor tutup.", "icon": "libur" }
  ]
}
```

| Field | Wajib | Keterangan |
|---|---|---|
| `ok` | ✅ | `true` |
| `items` | ✅ | Daftar pengumuman, tampil berurutan. Maksimal **10**, sisanya diabaikan. Daftar kosong = screensaver tidak tampil |
| `items[].title` | ✅ | Judul, maks. **40** karakter (dihitung per huruf) |
| `items[].description` | – | Isi, maks. **160** karakter (dihitung per huruf). Alat membungkus teks menjadi beberapa baris. Boleh dihilangkan atau `null` |
| `items[].icon` | – | Salah satu kode ikon di bawah. Kosong atau tidak dikenal → `info` |
| `items[].id` | – | Penanda (teks), boleh diabaikan alat |
| `interval` | – | Lama tiap pengumuman tampil, dalam detik (2–60). **Bawaan 3** |
| `idle` | – | Screensaver muncul setelah alat diam sekian detik (5–600). **Bawaan 30** |

**Kode ikon yang tersedia di alat:**

| Kode | Arti |
|---|---|
| `info` | Informasi umum |
| `pengumuman` | Pengeras suara / pengumuman |
| `kalender` | Tanggal / acara |
| `jam` | Waktu / jadwal |
| `peringatan` | Peringatan |
| `rapat` | Rapat / pertemuan |
| `libur` | Libur |
| `selamat` | Ucapan selamat / perayaan |
| `kesehatan` | Kesehatan |
| `buku` | Pendidikan / kegiatan belajar |

**Kapan alat mengambil pengumuman:**
- Setelah tersambung ke WiFi.
- Setiap kali `config.announcements_rev` di respons `/ping` atau `/heartbeat` berubah. Dengan penanda ini, perubahan di aplikasi tampil di alat dalam ±1 menit.
- Paling lambat setiap 10 menit, sebagai cadangan kalau aplikasi tidak mengirim `announcements_rev`.

**Catatan lain:**
- Daftar terakhir disimpan di alat, sehingga tetap tampil walau server sedang mati.
- Kalau aplikasi tidak menyediakan endpoint ini (HTTP 404) atau balasannya bukan JSON yang valid, daftar lama tetap dipakai, dan alat **tidak** menampilkan pesan galat. Kalau belum pernah ada daftar, screensaver tidak tampil.
- Nilai `interval` atau `idle` di luar batas diabaikan, lalu alat memakai nilai bawaan.
- Font di alat hanya mendukung huruf latin dasar (ASCII). Huruf beraksen atau emoji bisa tampil kurang sempurna.

## 9. Checklist untuk aplikasi yang ingin integrasi

Checklist di bawah adalah syarat **API alat**. Untuk alat yang dikirim ke pelanggan, aplikasi juga perlu enam fitur pengelolaan (Kartu belum terdaftar, Rekap, Alat, Firmware, Pengumuman, Pengaturan); lihat [Panduan Integrasi Aplikasi](integrasi-aplikasi.md#2-fitur-wajib).

- [ ] Sediakan `GET /ping`, `POST /tap`, dan `POST /heartbeat` di bawah satu base URL.
- [ ] Periksa header `X-API-Key`. Kunci salah → HTTP `401`.
- [ ] `/tap` membalas `status` dengan salah satu nilai di bagian 4, ditambah `message` (dan `name`).
- [ ] Simpan nomor kartu 10 digit di data karyawan atau siswa Anda.
- [ ] Pakai `tapped_at` untuk tap `queued: true`.
- [ ] Simpan `tap_id` dan abaikan tap dengan `tap_id` yang sudah pernah dicatat.
- [ ] (Opsional) Kirim `photo_url` JPEG kecil, `config.pin` (per alat), `config.title`, `config.dim_after` / `config.dim_level` (layar redup), dan `config.restart_at` (per alat).
- [ ] (Opsional) Tampilkan status alat aktif dari heartbeat, plus kesehatan alat dari `raw` (restart, crash, `error`, RFID, antrean, RAM).
- [ ] (Opsional) Mode pilih Datang/Pulang: sediakan `POST /check-in` & `POST /check-out` (bagian 4.1), plus `config.tap_mode` kalau mode absen ingin diatur dari aplikasi.
- [ ] (Opsional) Update firmware jarak jauh: kirim `config.firmware_update` dan sediakan file `.bin` di `url` (bagian 6.1).
- [ ] (Opsional) Sediakan `GET /announcements` untuk screensaver pengumuman, plus `config.announcements_rev`.

## 10. Contoh cepat (cURL)

```bash
curl -X POST http://192.168.1.10/api/absensi/tap \
  -H "X-API-Key: rahasia-kantor-123" -H "X-Device-ID: ABS-1A2B3C" -H "X-Spec-Version: 1" \
  -H "Content-Type: application/json" \
  -d '{"device_id":"ABS-1A2B3C","tap_id":"1A2B3C-5F3A9C21","rfid":"0218893066","tapped_at":"2026-09-30T07:45:12+07:00","queued":false,"raw":null}'

# Mode pilih (bagian 4.1): sama dengan /tap, ditambah "mode". /check-out memakai "mode":"check_out".
curl -X POST http://192.168.1.10/api/absensi/check-in \
  -H "X-API-Key: rahasia-kantor-123" -H "X-Device-ID: ABS-1A2B3C" -H "X-Spec-Version: 1" \
  -H "Content-Type: application/json" \
  -d '{"device_id":"ABS-1A2B3C","tap_id":"1A2B3C-5F3A9C22","rfid":"0218893066","tapped_at":"2026-09-30T07:46:02+07:00","queued":false,"mode":"check_in","raw":null}'
```
