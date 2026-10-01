# Casing cetak 3D

Bagian dari [Absensi RFID Terintegrasi](../README.md) — **Tahap 5**.

Casing dua bagian untuk papan 10 × 10 cm: **PCB dot matrix** ([rakit-dotmatrix.html](rakit-dotmatrix.html), bawaan) atau **PCB cetak** ([pcb-papan-induk.md](pcb-papan-induk.md)). Dibuat di OpenSCAD dari ukuran papan dan gambar resmi LCD, jadi posisi lubang sama persis dengan papan. Pilih versinya dengan parameter `versi` di `casing.scad` (`dotmatrix` atau `pcb`): posisi USB, tombol EN/BOOT, LED, dan RC522 bergeser ± 1 mm di antara keduanya.

> **Status (1 Okt 2026):** desain selesai, lolos cek tabrakan dengan PCB, LCD, RC522, ESP32, buzzer, elko, dan LED. **Belum dicetak.** Cetak **1 set dulu** untuk dicoba sebelum mencetak banyak.

---

## 1. File

| File | Isi |
|---|---|
| `hardware/casing/hasil/casing-depan.stl` | **Kirim ke jasa cetak.** Panel depan + dinding |
| `hardware/casing/hasil/casing-belakang.stl` | **Kirim ke jasa cetak.** Tutup belakang |
| `hardware/casing/hasil/pratinjau-*.png` | Gambar depan, belakang, bagian dalam, rakitan |
| `hardware/casing/casing.scad` | Desain (buka di OpenSCAD). Semua ukuran di bagian atas file |
| `versi` di `casing.scad` | `dotmatrix` (bawaan, untuk PCB dot matrix) atau `pcb` (PCB cetak). Posisi USB, tombol EN/BOOT, LED, dan RC522 bergeser ± 1 mm |
| `hardware/casing/buat.sh` | Membuat ulang STL + gambar + cek tabrakan: `cd hardware/casing && ./buat.sh` (butuh `openscad` di PATH) |

Melihat 3D: buka `casing.scad` di OpenSCAD, pilih **bagian** (depan / belakang / rakit) di panel Customizer, tekan **F5**.

---

## 2. Bentuk

Ukuran luar **105.8 × 113.8 × 41.2 mm** (lebar × tinggi × tebal), sudut membulat.

| Bagian | Isi |
|---|---|
| **Depan** (tebal 22.8 mm) | Jendela LCD selebar area sentuh (77.8 × 50.6 mm, tepi miring), area **TEMPEL KARTU** (garis kartu + simbol, diukir), lubang LED + tabung cahaya, 12 lubang suara buzzer, 4 tiang baut |
| **Belakang** (tebal 20.4 mm) | 12 tiang tempat PCB duduk, lubang **USB-C di sisi kiri**, lubang **EN** dan **BOOT** (tekan dengan tusuk gigi), 2 lubang gantungan dinding, ventilasi di belakang ESP32, tulisan EN dan BOOT |

Kedua bagian bertemu dengan sambungan berundak (rapat, tidak bergeser). PCB terjepit di antara tiang belakang dan tiang depan.

Panel di atas RC522 setebal 2 mm, dan kartu berjarak ± 6–7 mm dari antena. Ini masih jauh di dalam jangkauan RC522 (± 3–5 cm).

---

## 3. Pesan ke jasa cetak

Contoh pesan untuk jasa cetak 3D (lewat WhatsApp atau email):

> Halo, saya mau cetak 2 file STL (casing alat elektronik, 1 set dulu):
> - casing-depan.stl (105.8 × 113.8 × 22.8 mm)
> - casing-belakang.stl (105.8 × 113.8 × 20.4 mm)
>
> Bahan **PETG**, warna bebas (saran: hitam atau putih). Layer 0.2 mm, infill 20–30 %, dinding 3 lapis.
> Arah cetak: **sisi luar di bawah** (panel depan menempel ke bed, tutup belakang juga), tanpa support.
> Lubang baut sengaja kecil (pilot 2.6 mm untuk sekrup M3), mohon tidak diperbesar.

| Pilihan | Saran | Alasan |
|---|---|---|
| Bahan | **PETG** | Lebih kuat dan tahan panas daripada PLA (aman di ruangan panas / dekat jendela) |
| Alternatif | PLA+ | Lebih murah, cukup untuk dalam ruangan ber-AC |
| Hindari | Resin | Getas untuk casing yang dibaut, dan lebih mahal untuk ukuran ini |
| Arah cetak | Sisi luar di bawah | Permukaan depan rata dan rapi, tidak perlu support |

---

## 4. Belanja tambahan (per unit)

| Barang | Jumlah | Untuk |
|---|---|---|
| Spacer M3 **11 mm** female-female (kuningan / nilon) | 8 | 4 untuk LCD, 4 untuk RC522 |
| Baut M3 × **25 mm** | 8 | Dari belakang casing, lewat PCB, ke spacer |
| Baut M3 × **5 mm** | 8 | LCD dan RC522 ke spacer |
| Baut M3 × **30 mm** | 4 | Menyatukan casing depan dan belakang |
| Sekrup dinding + fisher | 2 | Gantungan dinding (kepala sekrup maks. Ø 8 mm, batang maks. Ø 4 mm) |

Untuk 5 unit: 40 spacer, 40 baut M3×25, 40 baut M3×5, 20 baut M3×30.

> Kalau lubang RC522 di modul Anda tidak sejajar dengan tiangnya (lihat [pcb-papan-induk.md §5](pcb-papan-induk.md)), RC522 tetap tertahan header dan panel depan. Spacer RC522 boleh tidak dipasang.

---

## 5. Merakit

1. Solder semua komponen ke PCB. **LED RGB dipasang tinggi:** kubah LED ± 13 mm di atas PCB (kaki jangan dipotong pendek), supaya masuk ke tabung cahaya di panel depan.
2. Letakkan PCB di atas tiang **tutup belakang** (sisi ESP32 menghadap ke tutup belakang, USB ke lubang di sisi kiri).
3. Pasang 8 spacer 11 mm di sisi depan PCB (4 lubang LCD, 4 lubang RC522), lalu kencangkan dengan baut M3×25 **dari luar tutup belakang**.
4. Colokkan ESP32 (dari belakang PCB, sebelum langkah 2), LCD, dan RC522. Kencangkan LCD dan RC522 ke spacer dengan baut M3×5.
5. Tes alat dulu (colok USB lewat lubang samping).
6. Pasang **casing depan**, lalu kencangkan 4 baut M3×30 dari belakang: 2 di pita atas, 2 di pojok bawah (lewat PCB). Baut langsung masuk ke plastik (lubang pilot 2.6 mm), jangan terlalu kencang.
7. Pasang 2 sekrup di dinding (jarak **30 mm**, sejajar), gantung alat lewat lubang kunci di belakang lalu geser ke bawah.

---

## 6. Kalau perlu diubah

Semua ukuran ada di atas `hardware/casing/casing.scad`, contoh:

| Masalah | Ubah |
|---|---|
| Kartu kurang terbaca | `panel` (tebal panel depan) jadi 1.5 |
| Casing terlalu sempit / longgar ke PCB | `jarak_pcb` |
| ESP32 menyentuh tutup belakang | `ruang_esp32` |
| LCD tertekan panel / longgar | `celah_lcd` |
| Lubang baut terlalu sempit / longgar | `lubang_ulir` (pilot), `lubang_baut` (tembus) |

Setelah mengubah, jalankan `./buat.sh` (STL baru + cek tabrakan otomatis).
