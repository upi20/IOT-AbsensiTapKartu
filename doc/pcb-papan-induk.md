# PCB cetak (papan induk)

Bagian dari [Absensi RFID Terintegrasi](../README.md) — **Tahap 4.B**. Pilihan lain yang lebih murah: PCB dot matrix ([rakit-dotmatrix.html](rakit-dotmatrix.html), Tahap 4.A). Nilai beberapa komponen di versi dot matrix berbeda (2N3904, FR107, 220 µF, 22 µF, 47 nF, tanpa R6), tetapi rangkaiannya sama.

PCB ini menggantikan kabel jumper. Modul prototipe (ESP32 DevKit 30 pin, LCD 3.5", RC522) **dicolokkan** ke PCB lewat header female, tidak disolder langsung. Jadi:

- **Firmware tidak berubah.** Semua pin sama dengan [skema-lengkap.md §10](skema-lengkap.md#10-ringkasan-gpio-untuk-program).
- Modul yang rusak tinggal dicabut dan diganti.
- Semua komponen tambahan **through-hole** (berkaki panjang), mudah disolder sendiri.
- Expansion board tidak dipakai lagi.
- Yang dipesan ke pabrik hanya **PCB kosong**. Komponen dibeli dan disolder sendiri.

> **Status (1 Okt 2026):** desain KiCad selesai, DRC bersih (0 pelanggaran, semua jalur tersambung). **Belum dipesan.** Sebelum memesan, lakukan cek cetak 1:1 di §6.

---

## 1. File

Semua ada di folder `hardware/pcb/`:

| File | Isi |
|---|---|
| `absensi-pcb.kicad_pro` | **Buka ini di KiCad** (dobel klik), lalu buka PCB Editor. Tampilan 3D: menu View > 3D Viewer |
| `absensi-pcb.kicad_pcb` | Desain PCB: bentuk papan, komponen, jalur |
| `hasil/absensi-pcb-jlcpcb.zip` | **File pesanan** (Gerber + bor). Upload ini ke JLCPCB |
| `hasil/absensi-pcb-gko.zip` | Isi sama, nama gaya Protel/Altium (`.GTL`, `.GKO` untuk outline, `.DRL`) untuk pabrik yang meminta `.GKO` |
| `hasil/absensi-pcb-gbr.zip` | Isi sama, semua lapisan berekstensi `.gbr` |
| `hasil/cetak-depan.pdf` | Cetak 1:1 sisi depan (LCD, RFID, LED, buzzer) |
| `hasil/cetak-belakang.pdf` | Cetak 1:1 sisi belakang (ESP32), sudah dicerminkan |
| `hasil/3d-depan.png`, `hasil/3d-belakang.png` | Gambar 3D |
| `buat_pcb.py` | Semua ukuran dan posisi komponen (ubah di sini, bukan di KiCad) |
| `buat.sh` | Membuat ulang semuanya: `cd pcb && ./buat.sh` |

`buat.sh` membuat papan dari `buat_pcb.py`, menarik jalur otomatis (Freerouting), menambah tuangan GND, memeriksa DRC, lalu membuat file pesanan, file cetak, dan gambar 3D. Freerouting dan Java portabel diunduh sekali ke `hardware/pcb/alat/` (± 130 MB, tidak dipasang ke sistem).

> Kalau desain diubah langsung di KiCad lalu `buat.sh` dijalankan, perubahan itu **tertimpa**. Ubah ukuran di `buat_pcb.py`, atau berhenti memakai `buat.sh` dan lanjutkan di KiCad saja.

---

## 2. Tata letak

Papan **100 × 100 mm**, 2 lapis. Dilihat dari depan:

```
┌──────────────────────────────────┐
│ ○                              ○ │  ○ = lubang M3 (4 lubang LCD, dipakai juga untuk casing)
│   ┌──────────────────────────┐ ▐ │
│   │   LCD 3.5" (depan)       │ ▐ │  ▐ = header LCD 14 pin (VCC paling bawah)
│   │   ESP32 di BELAKANG,     │ ▐ │
│   │   USB di tepi kiri       │ ▐ │
│ ○ └──────────────────────────┘ ○ │
│ LED  ┌───────────────────┐ ▐ 🔊  │  ▐ = header RC522 8 pin (SDA paling bawah)
│ R1-3 │ RC522 (depan)     │ ▐ Q1  │
│ C1   │ TEMPEL KARTU      │ ▐ D1  │
│ ○    └───────────────────┘   ○   │  ○ = 2 lubang casing + 4 lubang RC522
└──────────────────────────────────┘
```

| Bagian | Posisi | Alasan |
|---|---|---|
| LCD | Depan, atas. PCB LCD 98 × 56.34, pin di kanan | Sesuai gambar resmi LCD |
| RC522 | Depan, bawah LCD, pin di kanan | Area kartu di bawah layar |
| Area antena RC522 | **Tanpa tembaga** di kedua sisi | Tembaga melemahkan pembacaan kartu |
| ESP32 DevKit | **Belakang**, USB di tepi kiri papan, komponen menghadap keluar | USB bisa dicolok dari samping casing |
| Area antena WiFi ESP32 | Tanpa tembaga, ± 2 cm dari RC522 | Sinyal WiFi |
| Tombol EN & BOOT | Di belakang, dekat USB | Beri lubang di casing belakang (EN 3 kali = reset pabrik) |
| Tuangan GND | Sisi belakang, seluas papan | Mengurangi gangguan |

Jarak antar papan: header female ± 8.5 mm + plastik header modul ± 2.5 mm = **± 11 mm**. Pakai **spacer M3 11 mm** (atau 10 mm + ring) untuk LCD dan RC522. Komponen di bawah LCD (R6, C2) sengaja yang pendek.

---

## 3. Sambungan

`3V3` dan `GND` = pin 3V3 dan GND milik DevKit.

### LCD 3.5" (J3, 14 pin)

| Pin LCD | Ke | Catatan |
|---|---|---|
| VCC | 3V3 | **Jangan 5V** (J1 di LCD disolder) |
| GND | GND | |
| CS | IO5 | |
| RESET | IO4 | |
| DC | IO16 | |
| SDI (MOSI) | IO23 | |
| SCK | IO18 | |
| LED | IO2 | + R6 10 kΩ ke GND (IO2 pasti LOW saat menyala) |
| SDO (MISO) | – | |
| T_CLK | IO22 | |
| T_CS | IO15 | |
| T_DIN | IO21 | |
| T_DO | IO19 | |
| T_IRQ | – | |

Pin LED LCD aman langsung ke IO2: menurut skema resmi LCD, pin LED masuk ke transistor S8050 lewat resistor 1 kΩ di modul LCD, jadi arusnya hanya ± 2.6 mA.

### RC522 (J4, 8 pin)

| Pin | Ke |
|---|---|
| SDA (SS) | IO33 |
| SCK | IO14 |
| MOSI | IO13 |
| MISO | IO35 |
| IRQ | – |
| GND | GND |
| RST | IO32 |
| 3.3V | 3V3 |

### Buzzer lewat transistor (BZ1, Q1, R4, R5, D1)

```
IO17 ──[R4 1 kΩ]──┬── B  (Q1 S8050)
                [R5 10 kΩ]
GND ──────────────┴── E

3V3 ──┬── buzzer (+)
      │      buzzer (−) ── C (Q1)
      └──|◄── D1 1N4148 (garis/katoda ke 3V3)
```

HIGH di IO17 tetap = bunyi, firmware tidak berubah.

### LED RGB (D2) dan kapasitor

| Bagian | Sambungan |
|---|---|
| R1, R2, R3 (220 Ω) | IO25 → R, IO26 → G, IO27 → B |
| Kaki K (terpanjang) | GND |
| C1 470 µF | 3V3–GND (kiri bawah) |
| C2 100 nF | 3V3–GND dekat header LCD |
| C3 10 µF + C4 100 nF | 3V3–GND dekat header RC522 |

---

## 4. Daftar belanja (5 unit + cadangan)

| Komponen | Per unit | Beli |
|---|---|---|
| **PCB** (JLCPCB, file `absensi-pcb-jlcpcb.zip`) | 1 | 5 |
| Header female 1×15, 2.54 mm | 2 | 12 |
| Header female 1×14 | 1 | 6 |
| Header female 1×8 | 1 | 6 |
| Resistor 220 Ω 1/4 W | 3 | 20 |
| Resistor 1 kΩ 1/4 W | 1 | 10 |
| Resistor 10 kΩ 1/4 W | 2 | 20 |
| Transistor S8050 (NPN, TO-92) | 1 | 10 |
| Dioda 1N4148 | 1 | 10 |
| Elko 470 µF 10 V (Ø 8 mm, jarak kaki 3.5 mm) | 1 | 6 |
| Elko 10 µF (Ø 5 mm, jarak kaki 2.5 mm) | 1 | 6 |
| Kapasitor keramik 100 nF (jarak kaki 2.5 mm) | 2 | 12 |
| Spacer M3 11 mm + baut M3 | ± 8 + 16 | 1 paket |
| LED RGB 5 mm katoda bersama | 1 | 6 |
| Buzzer aktif 12 mm (jarak kaki 7.6 mm) | 1 | 6 |

---

## 5. Ukuran yang diambil dari internet (perlu dicek)

| Modul | Ukuran di desain | Sumber | Keyakinan |
|---|---|---|---|
| LCD | PCB 98 × 56.34, lubang Ø3.2 berjarak 92 × 49.5, 14 pin di tengah sisi pendek, 2.00 mm dari tepi, VCC paling bawah (pin di kanan) | Gambar resmi LCDWIKI MSP3520 | Tinggi (cocok dengan ukur Anda 9.97 × 5.57 cm) |
| ESP32 DevKit | 51.5 × 28, jarak dua deret pin **25.4 mm**, pin pertama 6 mm dari ujung antena | 3 footprint KiCad di GitHub, semuanya sama | Tinggi, tetapi cek jarak deret |
| RC522 | 60 × 40, deret pin 1.9 mm dari tepi kanan, SDA 9.5 mm dari tepi bawah, 4 lubang | Footprint AZ-Delivery RC522 | **Sedang**: tiap pabrik bisa beda beberapa mm |
| Buzzer | Jarak kaki 7.6 mm | Footprint standar buzzer 12 mm | Sedang (Anda mengukur Ø14 mm) |
| LED RGB | Urutan kaki R, K, G, B | Umum untuk LED katoda bersama | Sedang |
| S8050 | Urutan kaki E, B, C (sisi datar menghadap Anda) | Datasheet umum S8050 | Tinggi |

---

## 6. Cek sebelum memesan (wajib)

1. **Cetak** `hasil/cetak-depan.pdf` dan `hasil/cetak-belakang.pdf` di A4 dengan **skala 100 %** ("Actual size", jangan "Fit to page").
2. **Ukur kotak papan di kertas: harus 10.0 × 10.0 cm.** Kalau tidak, pengaturan printer salah.
3. Di kertas **depan**:
   - Letakkan **LCD** (layar menghadap atas) di kotak LCD. Ke-14 pin harus jatuh tepat di 14 lingkaran J3, dan 4 lubang LCD harus sejajar dengan 4 lubang di kertas. Pin **VCC** di posisi paling bawah.
   - Letakkan **RC522** di kotak RC522, pin di kanan. **SDA** harus di lingkaran paling bawah J4. Catat kalau lubang RC522 tidak sejajar (lubang ini hanya penyangga, pin yang paling penting).
   - Tusukkan kaki **buzzer**, **LED**, dan **S8050** ke lingkarannya.
4. Di kertas **belakang**: letakkan **ESP32 DevKit** dengan komponen menghadap atas dan USB di tepi. Ke-30 pin harus jatuh tepat di lingkaran J1 dan J2, dan tulisan pin di kertas harus sama dengan tulisan di DevKit (3V3, GND, 15, 2, 4, ...).
5. Kalau ada yang meleset, ubah ukurannya di `hardware/pcb/buat_pcb.py` (semua ukuran ada di bagian atas file), lalu jalankan `./buat.sh` lagi. Laporkan juga lewat *Issues* supaya desainnya diperbaiki untuk semua orang.
6. Casing untuk versi PCB cetak: ubah `versi = "pcb"` di `hardware/casing/casing.scad` dan buat ulang STL (lihat [casing.md](casing.md)).

---

## 7. Memesan di JLCPCB

1. Buka jlcpcb.com, klik **Order now**, upload `hardware/pcb/hasil/absensi-pcb-jlcpcb.zip`.
2. Pastikan terbaca **100 × 100 mm, 2 layers**.
3. Pilihan: PCB Qty **5**, Thickness **1.6 mm**, Surface Finish **HASL**, warna bebas. Pilihan lain biarkan bawaan.
4. **Jangan** centang PCB Assembly (komponen disolder sendiri).
5. Cek pratinjau gambar PCB, pilih pengiriman, bayar. Cek harga dan ongkos kirim terbaru sebelum membayar.

---

## 8. Merakit

1. **Rakit satu unit dulu.** Urutan solder dari yang paling pendek: resistor, dioda, kapasitor keramik, transistor, LED, elko, buzzer, lalu header female.
2. Header female ESP32 (J1, J2) dipasang **dari sisi belakang** dan disolder di sisi depan. Semua komponen lain dipasang dari depan dan disolder di belakang.
3. Perhatikan arah: elko (kaki + ke tanda +), dioda (garis ke tanda garis), S8050 (sisi datar sesuai gambar), LED (kaki terpanjang ke K), buzzer (+ ke tanda +).
4. Sebelum memasang modul: ukur dengan multimeter bahwa **3V3 dan GND tidak tersambung singkat**.
5. Pasang ESP32, upload firmware, cek layar, touch, kartu, buzzer, LED. Setelah lolos, rakit 4 unit sisanya.

---

## 9. Nanti: versi terpadu

Untuk produksi puluhan unit: modul ESP32-WROOM disolder langsung, plus USB-C, chip USB, dan regulator sendiri. Lebih kecil dan murah per unit, tetapi desainnya lebih sulit dan komponennya SMD. Tidak perlu untuk 5 unit.
