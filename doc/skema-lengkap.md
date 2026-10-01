# Skema Kabel Prototipe

Bagian dari [Absensi RFID Terintegrasi](../README.md) — **Tahap 1**.

Semua sambungan kabel **LCD 3.5" + touch, buzzer, LED RGB, dan RFID RC522** ke ESP32 DOIT DevKit V1 (30 pin) yang dipasang di **expansion board 30 pin** (header G-V-S per pin), memakai kabel jumper female-female. Tidak perlu breadboard: setiap pin hanya untuk satu kabel.

Versi interaktif (gambar kabel, sorotan per langkah, centang progres): [rakit-lengkap.html](rakit-lengkap.html). Unduh repositori lalu buka file itu di browser.

> **Aturan utama:** semua komponen memakai **3,3 V**. Jangan ada kabel yang masuk ke pin **5V**. Selama memasang kabel, **cabut kabel USB**.

---

## Daftar Isi

1. [Tabel semua sambungan](#1-tabel-semua-sambungan)
2. [Daya: kenapa semua 3,3 V](#2-daya-kenapa-semua-33-v)
3. [Peta header expansion](#3-peta-header-expansion)
4. [Mengenali komponen](#4-mengenali-komponen)
5. [Urutan pemasangan dan tes](#5-urutan-pemasangan-dan-tes)
6. [Tips kabel female-female](#6-tips-kabel-female-female)
7. [Cek sebelum colok USB](#7-cek-sebelum-colok-usb)
8. [Pin yang tidak boleh dipakai](#8-pin-yang-tidak-boleh-dipakai)
9. [Troubleshooting](#9-troubleshooting)
10. [Ringkasan GPIO untuk program](#10-ringkasan-gpio-untuk-program)

---

## 1. Tabel semua sambungan

Cara membaca kolom "Ke expansion":
- **D17, baris S** = di deret pin, cari tulisan **D17**. Setiap tulisan punya 3 pin berjajar: **S** (sinyal, paling luar dekat tepi board), **V** (tegangan, tengah), **G** (GND, paling dalam dekat ESP32).
- **Header daya** = blok pin 2×4 bertuliskan **5V 5V 3.3V 3.3V** dan **GND GND GND GND**.

Expansion board lain mungkin menamai atau mengurutkan pin berbeda. Yang penting **GPIO-nya sama** (lihat [bagian 10](#10-ringkasan-gpio-untuk-program)).

### LCD 3.5" ILI9488

| Pin LCD | Ke expansion | Catatan |
|---|---|---|
| VCC | Header daya, pin **3.3V** | **Wajib 3,3 V**, dan jumper J1 di LCD harus disambung (lihat [bagian 2](#2-daya-kenapa-semua-33-v)) |
| GND | Header daya, pin **GND** | Pin GND mana saja |
| CS | **D5**, baris S | |
| RESET | **D4**, baris S | |
| D/C (DC) | **D16**, baris S | **Bukan D2** |
| SDI (MOSI) | **D23**, baris S | |
| SCK | **D18**, baris S | |
| LED | **D2**, baris S | Lampu latar. Firmware menyalakan dan meredupkannya lewat D2 |
| SDO (MISO) | *tidak disambung* | |

### Touch (di modul LCD yang sama)

| Pin touch | Ke expansion | Catatan |
|---|---|---|
| T_CLK | **D22**, baris S | Boleh juga ke pin D22 di header I2C (GPIO sama) |
| T_CS | **D15**, baris S | |
| T_DIN | **D21**, baris S | Boleh juga ke pin D21 di header I2C (GPIO sama). Di modul lain bernama T_IN |
| T_DO | **D19**, baris S | Di modul lain bernama T_OUT |
| T_IRQ | *tidak disambung* | |

Touch memakai pin sendiri (tidak berbagi kabel dengan layar), dibaca firmware dengan cara bit-bang.

### Buzzer aktif

| Kaki buzzer | Ke expansion | Catatan |
|---|---|---|
| **+** (kaki panjang, ada tanda +) | **D17**, baris S | |
| **−** (kaki pendek) | **D17**, baris **G** | Pin G mana saja |

### LED RGB 5 mm common cathode

| Kaki LED | Ke expansion | Catatan |
|---|---|---|
| Katoda (**kaki terpanjang**) | **D25**, baris **G** | Pin G mana saja |
| R (merah) | **D25**, baris S | Sebaiknya lewat resistor 220–330 Ω |
| G (hijau) | **D26**, baris S | Sebaiknya lewat resistor 220–330 Ω |
| B (biru) | **D27**, baris S | Sebaiknya lewat resistor 220–330 Ω |

### RFID RC522 (modul 60 × 40 mm)

| Pin RC522 | Ke expansion | Catatan |
|---|---|---|
| **3.3V** | **D34**, baris **V** | Jumper JUMP harus di 3.3V. **Jangan ke 5V** |
| GND | **D34**, baris **G** | Pin G mana saja |
| SDA (kadang tertulis SS atau NSS) | **D33**, baris S | Ini chip select SPI, bukan SDA I2C |
| SCK | **D14**, baris S | |
| MOSI | **D13**, baris S | |
| MISO | **D35**, baris S | D35 hanya bisa input, cocok untuk MISO |
| RST | **D32**, baris S | |
| IRQ | *tidak disambung* | |

Kolom D34 dipakai untuk daya RFID karena pin S D34 memang tidak dipakai: **"kolom D34 = daya RFID"**. RFID memakai jalur SPI sendiri (D14, D13, D35), terpisah dari LCD (D18, D23).

---

## 2. Daya: kenapa semua 3,3 V

| Hal | Penjelasan |
|---|---|
| Sumber daya | Cukup **satu kabel USB ke port ESP32**. Kabel ini sekaligus untuk upload dan monitor serial |
| Jaringan 3,3 V | Pin **3.3V** di header daya dan semua pin **V** (kalau JUMP di 3.3V) mendapat daya dari regulator 3,3 V di ESP32 |
| Pin 5V expansion | Pada expansion board jenis ini, header 5V baru bertegangan kalau USB-C atau DC jack **expansion** sendiri dicolok. Rangkaian ini **tidak memakai 5V sama sekali** |
| Jumper **JUMP** | Mengatur tegangan semua pin **V**. **Harus di posisi 3.3V** (menutup pin 3.3V dan V). Kalau di 5V dan expansion diberi daya, RC522 bisa rusak |
| LCD, jumper **J1** | Menurut skema resmi modul LCD: VCC 5 V → J1 dibuka (regulator U1 di LCD menurunkan ke 3,3 V); VCC 3,3 V → **J1 disambung**. Rangkaian ini memberi 3,3 V, jadi **sambungkan J1 dengan timah**. Setelah J1 disambung, **VCC tidak boleh lagi 5 V** |
| RFID RC522 | Chip MFRC522 hanya tahan **3,3 V**. 5 V bisa merusaknya |
| Buzzer | Buzzer aktif 3–5 V. Di 3,3 V bunyinya sedikit lebih pelan, itu normal |
| LED RGB | Dinyalakan langsung dari pin GPIO (3,3 V) |
| Lampu LCD (LED) | Pin LED LCD masuk ke transistor di modul LCD (lewat resistor 1 kΩ), jadi arus dari D2 hanya ± 2–3 mA |

---

## 3. Peta header expansion

Setiap tulisan pin (misalnya D17) punya satu kolom berisi 3 pin: **S, V, G**. Di kedua deret, **S selalu paling luar** (dekat tepi board) dan **G selalu paling dalam** (dekat ESP32).

Keterangan isi sel: `.` = kosong, `-` = tidak dipakai, `xx` = **jangan dipakai**.

### 3a. Orientasi normal: colokan DC dan USB-C expansion di KANAN

```
Deret atas (deret LCD): LCD, touch, buzzer
         D23   D22   TX0   RX0   D21   D19   D18   D5    D17   D16   D4    D2    D15
  S      SDI   T_CLK -     -     T_DIN T_DO  SCK   CS    BZ+   DC    RST   BL    T_CS
  V      .     .     .     .     .     .     .     .     .     .     .     .     .
  G      .     .     .     .     .     .     .     .     BZ-   .     .     .     .

        ┌──────────────── ESP32 DevKit (port USB ESP32 di kanan) ────────────────┐
        └────────────────────────────────────────────────────────────────────────┘

Deret bawah: RFID, LED RGB
         EN    VP    VN    D34   D35   D32   D33   D25   D26   D27   D14   D12   D13
  G      .     .     .     RFGND .     .     .     LED-  .     .     .     .     .
  V      .     .     .     RF3V3 .     .     .     .     .     .     .     .     .
  S      xx    -     -     -     MISO  RST   SDA   R     Hijau Biru  SCK   xx    MOSI

Header daya 2×4 (pojok kanan bawah):     5V    5V    3.3V  3.3V
                                         GND   GND   GND   GND
  LCD VCC → 3.3V, LCD GND → salah satu GND. LCD LED → D2 baris S (bukan 3.3V).
Header I2C (kanan atas): kolom D22, D21, VCC, GND, masing-masing 2 pin.
```

- Deret atas: `RST` = RESET LCD, `DC` = D/C LCD, `BL` = LED LCD (lampu latar), `BZ+`/`BZ-` = kaki buzzer.
- Deret bawah: `MISO`, `RST`, `SDA`, `SCK`, `MOSI` = pin RC522. `RF3V3`/`RFGND` = daya RC522. `R`, `Hijau`, `Biru` = kaki warna LED, `LED-` = katoda LED.

### 3b. Board diputar 180°: colokan DC dan USB-C expansion di KIRI

Semua posisi berputar: deret EN … D13 pindah ke atas, deret D23 … D15 pindah ke bawah, dan urutan kiri-kanan terbalik. **Tulisan di board tetap sama**, jadi selalu cocokkan dengan tulisannya.

```
Deret atas sekarang deret EN … D13: RFID, LED RGB
         D13   D12   D14   D27   D26   D25   D33   D32   D35   D34   VN    VP    EN
  S      MOSI  xx    SCK   Biru  Hijau R     SDA   RST   MISO  -     -     -     xx
  V      .     .     .     .     .     .     .     .     .     RF3V3 .     .     .
  G      .     .     .     .     .     LED-  .     .     .     RFGND .     .     .

        ┌──────────────── ESP32 DevKit (port USB ESP32 di kiri) ─────────────────┐
        └────────────────────────────────────────────────────────────────────────┘

Deret bawah sekarang deret D23 … D15: LCD, touch, buzzer
         D15   D2    D4    D16   D17   D5    D18   D19   D21   RX0   TX0   D22   D23
  G      .     .     .     .     BZ-   .     .     .     .     .     .     .     .
  V      .     .     .     .     .     .     .     .     .     .     .     .     .
  S      T_CS  BL    RST   DC    BZ+   CS    SCK   T_DO  T_DIN -     -     T_CLK SDI

Header daya 2×4 (pojok kiri atas):  GND   GND   GND   GND    ← baris luar
                                    3.3V  3.3V  5V    5V     ← baris dalam
Header I2C: pindah ke kiri bawah.
```

**Kalau LCD dibalik** (layar menghadap meja): urutan pin LCD tercermin, jadi dari kiri ke kanan terbaca T_IRQ, T_DO, T_DIN, T_CS, T_CLK, SDO, LED, SCK, SDI, DC, RESET, CS, GND, **VCC**. Tetap ikuti tulisan di pin LCD.

---

## 4. Mengenali komponen

### Buzzer aktif (± 12 × 9,5 mm, 2 kaki)
- Kaki **+** lebih **panjang**, dan di badan buzzer biasanya ada tanda **+** di dekat kaki itu.
- Kalau kedua kaki sudah sama panjang, cari tanda + di bagian atas badan.
- Buzzer **aktif**: bagian bawah tertutup rapat (hitam) dan berbunyi sendiri bila diberi 3–5 V. Kalau bagian bawahnya terlihat papan hijau, itu buzzer **pasif**: firmware ini tidak membunyikannya dengan benar, jadi ganti dengan buzzer aktif.
- Stiker di atas buzzer boleh dilepas supaya suaranya lebih keras.

### LED RGB 5 mm common cathode (4 kaki)
- Kaki **terpanjang = katoda (−)**, disambung ke GND.
- Urutan kaki yang paling umum, dilihat dengan kaki menghadap ke bawah: **R, katoda, G, B**.
  - **R** = kaki tunggal di satu sisi katoda.
  - **G** dan **B** = dua kaki di sisi lain katoda (G lebih dekat ke katoda, B paling luar).
- Urutan bisa berbeda antar pabrik. Kalau warnanya tertukar saat dites, tukar kabelnya.
- Cara memastikan jenisnya: hubungkan kaki terpanjang ke GND, lalu sentuhkan satu kaki warna ke 3,3 V lewat resistor 220–330 Ω. Kalau menyala, LED itu **common cathode** (benar). Kalau tidak pernah menyala, kemungkinan **common anode**: ganti LED.

### RFID RC522
- Pin biasanya: **SDA, SCK, MOSI, MISO, IRQ, GND, RST, 3.3V**. Urutan fisiknya bisa berbeda, jadi **selalu ikuti tulisan di papan RC522**.
- **SDA** di RC522 kadang tertulis **SS** atau **NSS**. Ini pin chip select SPI, **bukan** SDA milik I2C. Jangan dicolok ke header I2C.
- **Header pin RC522 harus disolder.** Kalau pin header masih lepas (hanya diselipkan), kabel tidak kontak dengan baik dan RC522 tidak terbaca.
- Modul ini hanya untuk **3,3 V**.

---

## 5. Urutan pemasangan dan tes

Pasang **satu komponen, tes, baru lanjut**. Setiap kali memasang atau memindah kabel: **cabut USB dulu**. Siapkan software dulu ([README Tahap 2.1](../README.md#21-pasang-software)).

### Langkah 1: LCD dan touch
1. Sambungkan jumper **J1** di belakang LCD dengan timah ([bagian 2](#2-daya-kenapa-semua-33-v)).
2. Jumper **JUMP** di expansion di posisi **3.3V**.
3. Pasang kabel sesuai tabel [LCD](#lcd-35-ili9488) dan [touch](#touch-di-modul-lcd-yang-sama) (SDO dan T_IRQ dibiarkan kosong).
4. Upload sketsa tes layar sentuh: dari folder `firmware/`, `./upload.sh tes_touch -m` (atau buka `firmware/tes/tes_touch/tes_touch.ino` di Arduino IDE).
5. Hasil benar: kalibrasi 3 titik, lalu mode menggambar. Titik kuning muncul tepat di bawah stylus.

### Langkah 2: firmware utama
1. Upload firmware utama (`./upload.sh -m`, atau buka `firmware/absensi/absensi.ino` dengan partisi **Huge APP**).
2. Firmware tetap berjalan walau RFID, buzzer, dan LED belum dipasang (layar boot menulis "RFID tidak terdeteksi").
3. Selesaikan panduan pertama kali di layar (WiFi, Base URL, API key). Lihat [README Tahap 2.4–2.5](../README.md#24-siapkan-server-sementara-kalau-website-belum-ada).

### Langkah 3: buzzer (2 kabel)
1. Kaki **−** ke **D17 baris G**.
2. Kaki **+** ke **D17 baris S**.
3. Colok USB. Menu Pengaturan (ikon gir, PIN `2026`) → **Tes buzzer & LED**: buzzer harus berbunyi.

### Langkah 4: LED RGB (4 kabel)
1. Kaki **terpanjang (katoda)** ke **D25 baris G**.
2. Kaki **R** ke **D25 baris S**, **G** ke **D26 baris S**, **B** ke **D27 baris S**.
3. Menu → **Tes buzzer & LED**: LED menyala merah, hijau, biru, lalu putih (masing-masing 0,7 detik, disertai bip).

**Soal resistor:** idealnya setiap kaki warna (R, G, B) diberi resistor **220–330 Ω** secara seri (satu kaki resistor dipelintir ke kaki LED, kaki resistor yang lain masuk ke kabel female). Untuk prototipe boleh tanpa resistor: firmware mengatur pin LED ke kekuatan arus terendah, sehingga arusnya tetap kecil. Ini bukan cara ideal (LED sedikit kurang terang). Di PCB (Tahap 4) resistor sudah termasuk.

### Langkah 5: RFID RC522 (7 kabel)
1. **GND** ke **D34 baris G**.
2. **3.3V** ke **D34 baris V** (cek lagi: JUMP di 3.3V).
3. **SDA** ke **D33**, **SCK** ke **D14**, **MOSI** ke **D13**, **MISO** ke **D35**, **RST** ke **D32** (semua baris S).
4. IRQ dibiarkan kosong.
5. Colok USB. Layar boot harus menulis "RC522 (0x..)" dan monitor serial `RFID: RC522 terdeteksi`.
6. Kalau tidak terdeteksi: menu → **Cek kabel RFID**, atau upload `tes_miso` lalu `tes_rfid` (lihat [Troubleshooting](#rfid-rc522)).
7. Tempelkan kartu rata di atas antena: bunyi bip dan hasil tampil.

---

## 6. Tips kabel female-female

- Kabel female pas untuk pin header (expansion, LCD, RC522), tetapi **longgar di kaki komponen yang tipis** seperti buzzer dan LED.
- Dorong kabel **sampai mentok** ke pangkal kaki.
- Kalau masih goyang: **tekuk sedikit** ujung kaki komponen (huruf L kecil) sebelum dimasukkan, atau **pelintir** kakinya supaya lebih tebal dan menjepit.
- Pastikan **kaki-kaki LED dan buzzer tidak saling bersentuhan**. Kalau perlu, renggangkan kakinya sedikit.
- Tarik pelan setiap kabel setelah dipasang. Kalau lepas dengan mudah, pasang ulang.

---

## 7. Cek sebelum colok USB

- [ ] Jumper **JUMP** di posisi **3.3V**
- [ ] **Tidak ada** kabel di pin **5V** header daya
- [ ] Jumper **J1** di LCD sudah disambung
- [ ] LCD **VCC** di pin **3.3V** header daya, LCD **LED** di **D2 baris S**, LCD **GND** di GND
- [ ] RFID **3.3V** di **D34 baris V**, RFID **GND** di **D34 baris G**
- [ ] Semua kabel sinyal di baris **S** (paling luar)
- [ ] Buzzer **+** di D17 S, **−** di D17 G (tidak tertukar)
- [ ] LED: **kaki terpanjang** di D25 G
- [ ] Tidak ada kabel di **D12, TX0, RX0, EN** (D2 hanya untuk LED LCD)
- [ ] Kaki LED dan kaki buzzer tidak saling bersentuhan
- [ ] Hanya **satu** kabel USB, dicolok ke **port di ESP32**

---

## 8. Pin yang tidak boleh dipakai

| Pin | Alasan |
|---|---|
| **D12** | Pin boot yang menentukan tegangan flash. Kalau tertarik HIGH saat menyala, ESP32 gagal boot. **Jangan pernah dipakai** |
| **D2** | Pin boot + LED biru bawaan board. **Hanya untuk LED LCD (lampu latar).** Jangan pindahkan DC atau sinyal lain ke sini: DC LCD menahan D2 HIGH saat reset sehingga upload gagal. Pin LED LCD aman karena tidak menahan D2 HIGH |
| **TX0, RX0** | Jalur USB ke komputer (upload dan monitor serial) |
| **EN** | Tombol reset ESP32 |
| D34, VP, VN | Hanya bisa input. Tidak dipakai (pin V dan G di kolom D34 dipakai untuk daya RFID) |
| **5V** (header daya) | Tidak ada komponen 5 V di rangkaian ini |

Pin S yang masih kosong hanya **D34, VP, VN**, dan ketiganya input saja (misalnya untuk tombol, dengan resistor pull-up luar).

---

## 9. Troubleshooting

### Umum

| Gejala | Periksa |
|---|---|
| Upload gagal `Failed to communicate with the flash chip` | Ada kabel yang menahan pin boot. Pastikan tidak ada kabel di **D12** dan D2 hanya berisi LED LCD. Cabut kabel komponen yang baru dipasang, upload, lalu pasang lagi |
| Upload tertahan `Connecting....` | Tahan tombol **BOOT** sampai persentase upload muncul |
| Upload gagal `Resource busy` | Tutup Serial Monitor Arduino IDE, atau pakai `firmware/upload.sh` |
| ESP32 restart terus (brownout) | Ada korsleting 3,3 V ke GND. Cabut USB, cek kabel V dan G, dan kaki komponen yang bersentuhan |
| Ada komponen terasa **panas** | **Cabut USB segera.** Biasanya kabel daya tertukar atau masuk 5V |

### LCD dan touch

| Gejala | Periksa |
|---|---|
| Layar putih polos | CS (D5), DC (D16), RESET (D4), SCK (D18), SDI (D23) |
| Layar gelap total | LED ke D2 baris S, VCC ke 3.3V, GND, dan J1 sudah disambung |
| Layar redup sendiri | Normal: setelah diam 60 detik lampu meredup. Tempel kartu atau sentuh layar untuk menyalakannya |
| Warna terbalik (putih jadi hitam) | Tambahkan `#define TFT_INVERSION_ON` (atau `TFT_INVERSION_OFF`) di `firmware/absensi/tft_setup.h` |
| Merah dan biru tertukar | Tambahkan `#define TFT_RGB_ORDER TFT_BGR` (atau `TFT_RGB`) di `tft_setup.h` |
| Touch tidak merespons | T_CLK (D22), T_DIN (D21), T_DO (D19), T_CS (D15) |
| Titik sentuh meleset | Kalibrasi ulang (menu **Kalibrasi layar**) |

### Buzzer

| Gejala | Periksa |
|---|---|
| Tidak bunyi | Kaki + dan − tertukar? + harus ke D17 **S**. Kabel longgar di kaki buzzer? |
| Bunyi terus sejak dicolok | Kaki + masuk ke baris **V**, bukan S. Pindahkan ke S |
| Bunyi pelan | Normal untuk buzzer 3–5 V yang diberi 3,3 V. Lepas stikernya |
| Hanya "klik" pelan | Buzzer **pasif**. Ganti dengan buzzer aktif |

### LED RGB

| Gejala | Periksa |
|---|---|
| Tidak menyala sama sekali | Kaki **terpanjang** ke G? Kabel longgar? Kalau sudah benar, kemungkinan LED **common anode**: ganti |
| Warna tertukar (merah jadi hijau, dll.) | Urutan kaki LED berbeda. Tukar kabelnya |
| Satu warna tidak menyala | Kabel warna itu longgar, atau salah kolom (D25/D26/D27) |
| Redup | Normal kalau tanpa resistor dan arus dibatasi firmware |

### RFID RC522

Buka menu **Cek kabel RFID** di alat, atau upload `tes_miso` dan `tes_rfid`.

| Versi terbaca | Arti |
|---|---|
| Nilai lain, misalnya `0x91`, `0x92` (asli), `0x82`, `0x88`, `0x12`, `0xB2` (klon) | RC522 menjawab, kabel baik |
| `0x00` | Ada kabel tertukar (sering **SDA dan SCK**) atau lepas. `tes_rfid` mencoba semua susunan kabel |
| `0xFF` | RC522 tidak menjawab: cek 3,3 V (D34 V), GND (D34 G), JUMP di 3.3V, dan header RC522 sudah disolder |
| `0xEE` | Kabel **MOSI (D13) dan MISO (D35) bersentuhan**. Pisahkan konektornya, cek dengan `tes_miso` |

| Gejala | Periksa |
|---|---|
| Terdeteksi tapi kartu tidak terbaca | Tempelkan kartu rata di atas antena RC522, jarak 0–3 cm, tahan 1 detik. Jauhkan dari benda logam |
| Kadang terbaca, kadang tidak | Kabel longgar, terutama SCK dan MISO. Tekan ulang semua kabel RC522 |
| RC522 panas | **Cabut USB.** Kemungkinan 3,3 V/GND tertukar atau masuk 5 V |

---

## 10. Ringkasan GPIO untuk program

Sama dengan [firmware/absensi/config.h](../firmware/absensi/config.h) dan [tft_setup.h](../firmware/absensi/tft_setup.h).

| Fungsi | GPIO | Pin expansion |
|---|---|---|
| LCD CS / DC / RST | 5 / 16 / 4 | D5 / D16 / D4 |
| LCD SCK / MOSI | 18 / 23 | D18 / D23 |
| LCD LED (lampu latar, PWM) | 2 | D2 |
| Touch CLK / DIN / DOUT / CS | 22 / 21 / 19 / 15 | D22 / D21 / D19 / D15 |
| Buzzer | 17 | D17 |
| LED R / G / B | 25 / 26 / 27 | D25 / D26 / D27 |
| RFID SS(SDA) / SCK / MOSI / MISO / RST | 33 / 14 / 13 / 35 / 32 | D33 / D14 / D13 / D35 / D32 |
| Tombol BOOT | 0 | (tombol di board) |
