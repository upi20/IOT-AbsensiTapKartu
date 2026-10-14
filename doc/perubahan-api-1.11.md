# Perubahan API untuk firmware 1.11: jadwal DATANG/PULANG otomatis

Dokumen ini untuk pengembang (atau agent) yang memelihara **aplikasi server** yang dipakai alat absensi RFID. Isinya hanya perubahan yang dibutuhkan firmware **1.11.1** (1.11.0 + perbaikan aturan pilihan manual). Spesifikasi lengkap ada di [spesifikasi-api.md](spesifikasi-api.md) (bagian 4.1, 5, 6, dan 6.1).

## Ringkasan

- **Tidak ada endpoint baru.** `/ping`, `/heartbeat`, `/tap`, `/check-in`, `/check-out`, dan `/announcements` tetap sama.
- **Server → alat:** satu field baru di objek `config` (respons `/ping` dan `/heartbeat`): **`tap_schedule`**.
- **Alat → server:** satu field baru di `raw` heartbeat: **`raw.tap_schedule`**. Hanya laporan, boleh disimpan atau diabaikan.
- **Kompatibel mundur.** Firmware lama mengabaikan `config.tap_schedule`. Server yang tidak mengirimnya membuat firmware 1.11.0 bekerja persis seperti 1.6.0–1.10.0.

## Tujuan fitur

Di **mode Pilih** (`config.tap_mode = "select"`), layar alat punya tombol **DATANG** dan **PULANG**. Selama ini petugas harus menekan tombol itu setiap hari. Dengan `tap_schedule`, alat memilih sendiri:

- pada jam `check_in` (misalnya 06:00) → **DATANG** terpilih, semua tap dikirim ke `POST {base}/check-in`;
- pada jam `check_out` (misalnya 17:00) → **PULANG** terpilih, semua tap dikirim ke `POST {base}/check-out`.

Jam ini **diatur per alat dari aplikasi server**.

Prasyarat: aplikasi sudah menyediakan `POST {base}/check-in` dan `POST {base}/check-out` (sejak firmware 1.6.0) dan bisa mengirim `config.tap_mode`. Jadwal hanya berlaku saat mode alat `"select"`.

## 1. `config.tap_schedule` (server → alat)

Kirim di **respons `/ping` dan `/heartbeat`**, di dalam objek `config`, untuk alat yang sesuai dengan header `X-Device-ID`.

**Jadwal aktif:**
```json
{
  "ok": true,
  "message": "Terhubung",
  "server_time": "2026-10-14T05:58:00+07:00",
  "config": {
    "tap_mode": "select",
    "tap_schedule": { "check_in": "06:00", "check_out": "17:00" }
  }
}
```

**Jadwal dimatikan:**
```json
"config": { "tap_mode": "select", "tap_schedule": null }
```

| Nilai yang dikirim | Efek di alat |
|---|---|
| Objek `{"check_in": "HH:MM", "check_out": "HH:MM"}` yang sah | Jadwal dipakai dan disimpan di alat (tahan restart / listrik padam) |
| `null` | Jadwal **dimatikan**. Petugas memilih DATANG/PULANG manual |
| Kunci `tap_schedule` **tidak dikirim** | Alat memakai jadwal **terakhir** yang pernah diterima |
| Objek tidak sah | **Diabaikan**, alat tetap memakai jadwal sebelumnya |

**Aturan nilai yang sah:**
- `check_in` dan `check_out` **keduanya wajib**, berupa teks `"HH:MM"` 24 jam dengan nol di depan: `"06:00"` sah, `"6:00"` tidak.
- `00:00` ≤ jam ≤ `23:59`.
- `check_in` **harus lebih awal** dari `check_out` di hari yang sama. Shift lewat tengah malam tidak didukung.
- Jam mengikuti **jam lokal alat**, yaitu zona waktu dari `server_time` (misalnya `+07:00`).

**Saran implementasi:**
- Simpan dua kolom per alat, misalnya `schedule_check_in` dan `schedule_check_out` (teks 5 karakter, boleh kosong).
- Saat membangun `config`: kalau keduanya terisi dan sah, kirim objeknya. Kalau kosong, kirim **`null`**, supaya jadwal yang pernah terkirim ikut mati di alat. Jangan menghilangkan kuncinya.
- Validasi di form admin: keduanya diisi atau keduanya kosong, format `HH:MM`, dan `check_in` < `check_out`.

## 2. Perilaku di alat (untuk ditampilkan atau dijelaskan ke admin)

| Jam di alat | Pilihan di layar |
|---|---|
| 00:00 – sebelum `check_in` | Kosong (pilihan dikosongkan saat tanggal berganti). Petugas boleh memilih manual; tap sebelum memilih **tidak dikirim** |
| `check_in` – sebelum `check_out` | **DATANG** dipilih otomatis |
| `check_out` – 23:59 | **PULANG** dipilih otomatis |

- **Jadwal selalu menang.** Alat menyamakan pilihan dengan jadwal setiap 5 detik, termasuk saat alat baru menyala.
- Petugas tetap boleh menekan tombol lain (misalnya DATANG untuk karyawan yang terlambat), tapi pilihan manual itu hanya bertahan **3 menit** sejak tombol terakhir ditekan, lalu alat kembali sendiri ke pilihan jadwal.
- Sebelum jam `check_in` tidak ada jadwal yang berlaku, jadi pilihan manual bertahan.
- Tombol yang belum dipilih menampilkan jamnya, misalnya "otomatis 17:00".
- Server **tidak perlu** mengubah logika `/check-in` atau `/check-out`. Alat tetap mengirim ke endpoint yang sama seperti saat petugas memilih manual.

## 3. `raw.tap_schedule` (alat → server, di heartbeat)

Firmware 1.11.0 melaporkan jadwal yang sedang dipakai:

```json
{
  "device_id": "ABS-1A2B3C",
  "firmware": "1.11.1",
  "time": "2026-10-14T06:01:00+07:00",
  "raw": {
    "tap_mode": "select",
    "tap_select": "check_in",
    "tap_schedule": { "check_in": "06:00", "check_out": "17:00" }
  }
}
```

`raw.tap_schedule` bernilai `null` kalau alat tidak memakai jadwal. Field ini opsional untuk server. Kalau disimpan, admin bisa melihat apakah jadwal di alat sudah sama dengan yang diatur di aplikasi.

## 4. Cara mengetes di server

Tes dengan `curl` (ganti `{base}`, API key, dan ID alat). Atur dulu jadwal 06:00–17:00 untuk alat `ABS-TES` di aplikasi:

```bash
curl -s {base}/ping -H "X-API-Key: <key>" -H "X-Device-ID: ABS-TES" -H "X-Spec-Version: 1" -H "Accept: application/json"
```

| Skenario | Yang harus ada di `config` |
|---|---|
| Jadwal 06:00 / 17:00 diatur untuk alat itu | `"tap_schedule": {"check_in": "06:00", "check_out": "17:00"}` |
| Jadwal dikosongkan di admin | `"tap_schedule": null` |
| Alat lain tanpa jadwal | `"tap_schedule": null`, dan tidak ikut memakai jadwal alat lain |
| Form admin: `check_in` 17:00, `check_out` 06:00 | Ditolak (check_in harus lebih awal) |
| Form admin: hanya satu kolom diisi | Ditolak (harus keduanya) |
| Form admin: `6:00` atau `25:00` | Ditolak (format `HH:MM`, 00:00–23:59) |
| `POST /heartbeat` dengan `raw.tap_schedule` | Diterima (HTTP 200); disimpan kalau aplikasi memilih menyimpannya |

Respons `/heartbeat` harus memuat `config` yang sama dengan `/ping`, karena alat mengambil pengaturan dari keduanya.

## 5. Update firmware ke 1.11.1 lewat server (opsional)

Kalau aplikasi sudah mendukung `config.firmware_update` ([spesifikasi-api.md](spesifikasi-api.md) bagian 6.1), alat bisa diperbarui tanpa kabel:

| | |
|---|---|
| File | `absensi-1.11.1.bin` |
| `version` | `"1.11.1"` |
| `size` | `1359664` |
| `md5` | `"96002c29fc089d9678fac8038b5bc8aa"` |

```json
"config": {
  "firmware_update": {
    "version": "1.11.1",
    "url": "https://<server>/<path-unduhan>/absensi-1.11.1.bin",
    "size": 1359664,
    "md5": "96002c29fc089d9678fac8038b5bc8aa"
  }
}
```

- `url` harus **absolut**, membalas **HTTP 200** dengan isi `.bin` mentah, `Content-Length` yang benar, dan **tanpa redirect**.
- Kalau `url` beda host dengan Base URL alat, alat **tidak** mengirim `X-API-Key`, jadi file harus bisa diunduh tanpa autentikasi.
- Kirim hanya ke alat yang memang dijadwalkan update, selama `firmware` di heartbeat masih bukan `"1.11.1"`. Berhenti mengirim kalau heartbeat melaporkan `raw.ota_failed: "1.11.1"`.
- Alat memasang update saat sedang diam (± 1 menit tanpa kartu/sentuhan), atau langsung lewat menu alat **Pengaturan → Update firmware**.
- Satu file `.bin` ini berlaku untuk **semua alat**: setiap alat tetap memakai ID-nya sendiri.

Kalau aplikasi belum mendukung `firmware_update`, firmware dipasang lewat USB oleh teknisi.

## 6. Checklist

- [ ] Kolom jadwal per alat (`check_in`, `check_out`), dengan form dan validasi di admin.
- [ ] `config.tap_schedule` dikirim di `/ping` **dan** `/heartbeat`: objek kalau diatur, `null` kalau tidak.
- [ ] Alat dengan jadwal juga dikirimi `config.tap_mode: "select"` (jadwal hanya berlaku di mode Pilih).
- [ ] `POST /heartbeat` tetap menerima `raw.tap_schedule` (simpan atau abaikan).
- [ ] (Opsional) `config.firmware_update` untuk versi 1.11.1, dengan data file di bagian 5.
