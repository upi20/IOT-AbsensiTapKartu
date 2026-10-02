# Panduan Pengguna — Alat Absensi RFID

Panduan ini untuk **petugas dan admin di lokasi** yang memakai alat absensi sehari-hari. Tidak perlu pengetahuan teknis.

---

## 1. Mengenal layar alat

```
┌──────────────────────────────────────────────┐
│ ▂▄▆ ● Server OK   Antrean 2          ID   ⚙  │ ← bilah status: sinyal WiFi, server, antrean, ID alat, menu
│              NAMA INSTANSI                   │
│               07:45  12                      │ ← jam
│          Kamis, 2 Oktober 2026               │
│  ─────────────────────────────────────────   │
│   [kartu]  Tempelkan kartu                   │ ← (mode Pilih: tombol DATANG & PULANG)
│ ─────────────────────────────────────────────│
│                    Siap                      │ ← baris info: "Siap", atau pesan / kode error
└──────────────────────────────────────────────┘
```

- **Titik server:** hijau = tersambung, merah = gagal, abu = belum dicek.
- **"Antrean N":** N tap tersimpan di alat karena server sempat tidak bisa dihubungi. Tap ini dikirim otomatis begitu server tersambung lagi.
- **Layar meredup** sendiri saat alat tidak dipakai. Kartu tetap terbaca saat layar redup.
- Kalau ada pengumuman, layar menampilkannya bergantian saat alat diam (screensaver). Sentuh layar untuk kembali.

---

## 2. Absen dengan kartu

Tempelkan kartu ke area pembaca sampai terdengar bunyi. Hasilnya tampil ± 4 detik:

| Layar | Bunyi & lampu | Artinya |
|---|---|---|
| **MASUK** (hijau) | 1 bip, lampu hijau | Absen datang tercatat |
| **PULANG** (biru) | 1 bip, lampu biru | Absen pulang tercatat |
| **SUDAH TERCATAT** (kuning) | 2 bip | Kartu baru saja ditempel. Tidak perlu diulang |
| **KARTU TIDAK TERDAFTAR** (merah) | 3 bip | Kartu belum didaftarkan. Hubungi admin (lihat bagian 7) |
| **DITOLAK** (merah) | 3 bip | Kartu dinonaktifkan oleh admin |
| **TERSIMPAN** (kuning tua) | 2 bip panjang | Server sedang tidak bisa dihubungi. Tap **sudah aman tersimpan** di alat dan dikirim otomatis nanti dengan jam aslinya |
| **PILIH MODE DULU** (kuning) | 2 bip | Mode Pilih: tombol DATANG / PULANG belum dipilih. Tap tidak dikirim |
| **GAGAL** (oranye) | 1 bip panjang | Tap ditolak server. Coba lagi; kalau berulang, catat pesannya dan hubungi teknisi |

---

## 3. Mode absen

Ada dua cara alat mencatat datang dan pulang. Mode diatur oleh admin, dari menu alat atau dari website.

| Mode | Cara pakai |
|---|---|
| **Otomatis** | Cukup tempel kartu. Sistem menentukan sendiri datang atau pulang |
| **Pilih Datang / Pulang** | Layar utama menampilkan tombol **DATANG** dan **PULANG**. Petugas menyentuh salah satunya **sekali**, misalnya DATANG di pagi hari. Semua tap berikutnya tercatat sebagai datang sampai tombol PULANG disentuh. Tombol yang aktif berwarna (hijau/biru) dan bertuliskan "dipilih" |

Di mode Pilih:
- **Pilihan dikosongkan otomatis setiap ganti hari.** Pagi berikutnya, sentuh lagi DATANG.
- Kalau belum memilih, kartu yang ditempel tidak dicatat, dan layar menampilkan **PILIH MODE DULU**.

---

## 4. Menu Pengaturan

Sentuh **ikon gir (⚙)** di pojok kanan atas, lalu masukkan **PIN**. PIN bawaan pabrik `2026` **harus diganti** saat serah terima. Menu menutup sendiri setelah 60 detik tanpa sentuhan.

| Menu | Untuk apa |
|---|---|
| **WiFi** | Ganti jaringan WiFi (harus **2,4 GHz**) atau passwordnya |
| **Server** | Alamat server (Base URL) dan API key. **Hanya diubah oleh teknisi** |
| **Tes koneksi** | Mengecek WiFi dan server |
| **Info alat** | ID alat, versi firmware, sinyal, antrean, kode error, mode absen, dan lainnya. **Foto layar ini saat melapor ke teknisi** |
| **Ganti PIN** | Mengganti PIN menu (4–8 angka) |
| **Kalibrasi layar** | Kalau sentuhan meleset dari posisi jari |
| **Tes buzzer & LED** | Bunyi bip, lalu lampu merah → hijau → biru → putih |
| **Tes screensaver** | Menampilkan pengumuman sekarang |
| **Cek kabel RFID** | Memeriksa pembaca kartu (untuk teknisi) |
| **Mode absen** | Otomatis, atau Pilih Datang / Pulang (bagian 3) |
| **Update firmware** | Memeriksa dan memasang versi firmware baru (bagian 6) |
| **Reset pabrik** | Menghapus semua pengaturan. **Jangan dipakai tanpa arahan teknisi** |

---

## 5. Kode error di layar

Kalau ada masalah, baris paling bawah layar menampilkan **kode error**. Sebutkan kodenya saat menghubungi teknisi.

| Kode | Arti | Yang bisa dilakukan di lokasi |
|---|---|---|
| **E10** | WiFi tidak ditemukan | Pastikan router menyala. WiFi harus 2,4 GHz. Dekatkan router |
| **E11** | Password WiFi salah | Password router mungkin baru diganti: Pengaturan → WiFi |
| **E12** | WiFi gagal tersambung | Restart router |
| **E13** | WiFi tidak memberi alamat | Restart router |
| **E20** | Server tidak bisa dihubungi | Cek internet di lokasi (coba buka website dari HP di WiFi yang sama) |
| **E21** | API key salah | Hubungi teknisi |
| **E22** | Alat ditolak server | Hubungi teknisi/admin |
| **E23** | Alamat server salah | Hubungi teknisi |
| **E24** | Server sedang error / sibuk | Tunggu; tap tetap tersimpan di alat |
| **E25** | Balasan server tidak valid | Hubungi teknisi |
| **E26** | Server terlalu lama menjawab | Internet lambat; tap tetap tersimpan |
| **E30** | Pembaca kartu tidak terdeteksi | Matikan alat, hubungi teknisi (kemungkinan kabel longgar) |
| **E31** | Jam belum sinkron | Biasanya hilang sendiri setelah internet tersambung |

Selama **WiFi atau server bermasalah, absen tetap bisa dilakukan**: tap disimpan di alat (maks. 200 tap) dan dikirim otomatis nanti dengan jam aslinya.

---

## 6. Update firmware

Firmware adalah program di dalam alat. Versi baru disiapkan teknisi lewat website.

**Cara A — otomatis.** Alat memasang sendiri saat tidak dipakai ± 1 menit. Tidak perlu melakukan apa pun.

**Cara B — manual (langsung):**
1. Ketuk ⚙ → masukkan PIN → **Update firmware**.
2. Alat memeriksa server:
   - **"Tersedia vX.Y.Z"**: tekan **Pasang sekarang** → **Pasang**.
   - **"Sudah versi terbaru"**: tidak ada yang perlu dipasang.
3. Layar menampilkan **"Memperbarui firmware"**, bilah kemajuan, dan lampu **biru**. Proses ± 20 detik, lalu alat menyala ulang sendiri.

> ⚠️ **Jangan cabut listrik** selama "Memperbarui firmware" dan sampai 1 menit setelah alat menyala ulang. Kalaupun terjadi, alat tidak rusak: alat kembali ke versi lama dan mengulang update.

Kalau muncul **"Update firmware gagal"**, alasannya tampil di layar. Alat akan mencoba lagi otomatis 30 menit kemudian. Bisa juga dicoba lagi lewat menu.

---

## 7. Untuk admin website

| Kebutuhan | Di website |
|---|---|
| **Mendaftarkan kartu baru** | Minta orangnya menempelkan kartu di alat (layar "tidak terdaftar"), buka **Kartu belum terdaftar**, klik **Daftarkan**, isi nama, simpan |
| **Laporan kehadiran** | **Rekap**: pilih tanggal, unduh CSV (bisa dibuka di Excel) |
| **Memantau alat** | **Alat**: status Online/Offline, sinyal, kode error, riwayat restart. Peringatan muncul di dasbor |
| **Pengumuman di layar alat** | **Pengumuman**: tambah atau ubah. Tampil di alat dalam ± 1 menit |
| **Judul layar, layar redup** | **Pengaturan** |
| **PIN menu, jam restart, mode absen per alat** | **Alat** → pilih alat |
| **Update firmware** | **Firmware**: unggah versi baru, lalu **Alat** → pilih versi untuk alat itu |

---

## 8. Perawatan

- **Daya:** pakai adaptor yang disertakan (5 V, minimal 2 A). Adaptor yang lemah membuat alat sering menyala ulang. Di website, kondisi ini muncul sebagai peringatan "listrik turun (brownout)".
- **Restart harian:** alat menyala ulang sendiri sekali sehari pada jam yang diatur (bawaan 03:00) untuk menjaga kestabilan. Data tidak hilang.
- **Tempat pemasangan:** dalam jangkauan WiFi yang kuat, tidak terkena hujan atau sinar matahari langsung.
- **Membersihkan layar:** kain kering atau sedikit lembap, jangan disemprot cairan langsung.
- **Jangan menekan tombol EN 3 kali berturut-turut** tanpa arahan: itu membuka tawaran **reset pabrik**.
- **Alat macet:** cabut listrik 10 detik, lalu colok lagi. Program yang macet juga membuat alat menyala ulang sendiri dalam 60 detik (watchdog).

---

## 9. Saat menghubungi teknisi

Siapkan:
1. **ID alat**, tertulis di pojok kanan atas layar dan di Info alat.
2. **Kode error** di baris bawah layar, kalau ada.
3. **Foto layar Info alat** (Pengaturan → Info alat).
4. Kapan masalah mulai terjadi, dan apakah listrik atau internet di lokasi sedang bermasalah.
