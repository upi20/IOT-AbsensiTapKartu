# Spesifikasi API Alat Absensi Tap (v1)

Dokumen ini untuk **pengembang aplikasi** yang ingin menghubungkan aplikasinya dengan alat absensi RFID. Aplikasi Anda cukup menyediakan **3 endpoint wajib** (`/ping`, `/tap`, `/heartbeat`), ditambah 1 endpoint opsional untuk pengumuman (`/announcements`). Logika bisnis sepenuhnya milik aplikasi: siapa pemilik kartu, kapan dianggap masuk atau pulang, terlambat atau tidak.

Versi spesifikasi: `1` · Terakhir diperbarui: 1 Oktober 2026

---

## 1. Cara kerja singkat

```
[Kartu RFID] ─tap─▶ [Alat]  ──── POST {base}/tap ─────────▶ [Aplikasi Anda]
                     │     ◀──── hasil (masuk/pulang, nama, foto) ─┘
                     │
                     ├──── POST {base}/heartbeat (tiap 60 detik) ─▶ status alat aktif
                     ├──── GET  {base}/ping (tes koneksi & jam) ──▶
                     └──── GET  {base}/announcements (opsional, screensaver) ──▶
```

Semua pengaturan dilakukan di alat lewat layar sentuh (menu **Pengaturan**, dilindungi PIN):

| Pengaturan di alat | Contoh |
|---|---|
| WiFi (pilih dari hasil scan, atau ketik manual) + password | `Kantor-2.4G` |
| **Base URL** endpoint | `http://192.168.1.10/api/absensi` atau `https://app.contoh.com/api/absensi` |
| **API key** (teks bebas) | `rahasia-kantor-123` |

Alat memanggil `{base}/ping`, `{base}/tap`, `{base}/heartbeat`, dan (opsional) `{base}/announcements`.

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
  - `/tap`: **3 detik**. Kalau tidak ada jawaban, tap disimpan di antrean (TERSIMPAN), supaya orang berikutnya tidak ikut menunggu.
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
    "rfid_ok": true,
    "queue": 0
  }
}
```

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

- Nilai di luar aturan (PIN bukan 4–8 digit, judul lebih dari 30 karakter, `dim_after` 1–9 atau lebih dari 3600, `dim_level` di luar 0–100, atau bukan angka bulat, `restart_at` bukan `HH:MM` dan bukan `""`) **diabaikan** oleh alat.
- `dim_after` dan `dim_level` tersimpan di alat. Kalau server tidak mengirimnya, alat memakai nilai terakhir yang diterima (atau bawaan 60 detik / 20 %). Butuh firmware **1.3.0** ke atas; firmware lama mengabaikannya.
- `restart_at` juga tersimpan di alat (bawaan `"03:00"`), butuh firmware **1.4.0** ke atas. Restart harian membersihkan memori; antrean tap tetap tersimpan. Alat juga punya **watchdog**: kalau program macet lebih dari 60 detik, alat restart sendiri. Alasan restart terakhir dikirim di heartbeat sebagai `raw.reset_reason` (`poweron`, `software`, `watchdog`, `panic`, `brownout`, ...).
- Saat layar redup, **kartu tetap diproses** dan lampu langsung terang lagi. Sentuhan pertama hanya menyalakan lampu dan tidak menekan tombol apa pun.
- `server_time` yang berakhiran `Z` (UTC) tetap dipakai untuk mengatur jam, tetapi zona tampilan alat tidak berubah.
- **PIN bawaan pabrik: `2026`.** Nilai ini tertanam di firmware dan dipakai sampai diganti dari menu alat atau lewat `config.pin`.
- **Reset pabrik:** tekan tombol **EN 3 kali dalam 5 detik**, lalu konfirmasi di layar. Yang terhapus: WiFi, Base URL, API key, PIN (kembali ke `2026`), judul, zona waktu, kalibrasi layar sentuh, antrean tap offline, dan pengumuman tersimpan. **ID alat tidak terhapus.** Menu Pengaturan juga punya tombol "Reset pabrik".

## 7. Saat server tidak bisa dihubungi (antrean)

- Kalau `/tap` gagal karena jaringan (timeout, tidak ada WiFi, atau HTTP 5xx), alat **menyimpan tap itu** (maksimal **200 tap**, tetap tersimpan walau alat mati), lalu menampilkan **"TERSIMPAN – akan dikirim"**.
- Begitu server bisa dihubungi lagi, alat mengirim antrean satu per satu dengan `"queued": true` dan `tapped_at` asli. Respons untuk tap antrean tidak ditampilkan, cukup dijawab dengan `ok`.
- Aplikasi sebaiknya memakai `tapped_at` (bukan waktu diterima) untuk tap yang `queued: true`. Kalau `tapped_at` bernilai `null` (jam alat belum tersinkron), pakai waktu diterima.
- Aplikasi boleh mengganti `tapped_at` antrean dengan waktu diterima kalau nilainya tidak masuk akal: lebih dari 5 menit di masa depan, atau lebih lama dari 30 hari. Ini pilihan server; server acuan (`api/`) melakukannya.
- Untuk tap antrean, alat hanya melihat kode HTTP. **2xx, 3xx, atau 4xx (kecuali 429)** → tap dihapus dari antrean, apa pun isi balasannya (3xx diperlakukan seperti 4xx, redirect tidak diikuti). **429, 5xx, atau timeout** → tap tetap di antrean dan dicoba lagi nanti.
- Karena tap bisa terkirim ulang (misal balasan server terlambat lewat 3 detik padahal tap sudah tersimpan), pakai `tap_id` untuk mencegah catatan ganda.
- Tap yang ditolak dengan HTTP `4xx` (misalnya API key salah) **tidak** masuk antrean.

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

- [ ] Sediakan `GET /ping`, `POST /tap`, dan `POST /heartbeat` di bawah satu base URL.
- [ ] Periksa header `X-API-Key`. Kunci salah → HTTP `401`.
- [ ] `/tap` membalas `status` dengan salah satu nilai di bagian 4, ditambah `message` (dan `name`).
- [ ] Simpan nomor kartu 10 digit di data karyawan atau siswa Anda.
- [ ] Pakai `tapped_at` untuk tap `queued: true`.
- [ ] Simpan `tap_id` dan abaikan tap dengan `tap_id` yang sudah pernah dicatat.
- [ ] (Opsional) Kirim `photo_url` JPEG kecil, `config.pin` (per alat), `config.title`, `config.dim_after` / `config.dim_level` (layar redup), dan `config.restart_at` (per alat).
- [ ] (Opsional) Tampilkan status alat aktif dari heartbeat.
- [ ] (Opsional) Sediakan `GET /announcements` untuk screensaver pengumuman, plus `config.announcements_rev`.

## 10. Contoh cepat (cURL)

```bash
curl -X POST http://192.168.1.10/api/absensi/tap \
  -H "X-API-Key: rahasia-kantor-123" -H "X-Device-ID: ABS-1A2B3C" -H "X-Spec-Version: 1" \
  -H "Content-Type: application/json" \
  -d '{"device_id":"ABS-1A2B3C","tap_id":"1A2B3C-5F3A9C21","rfid":"0218893066","tapped_at":"2026-09-30T07:45:12+07:00","queued":false,"raw":null}'
```
