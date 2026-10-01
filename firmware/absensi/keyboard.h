// Papan ketik di layar (QWERTY) untuk mengisi nama WiFi, password, Base URL, dan API key.
//
//  [ Judul                       ][ Batal ][  OK  ]
//  [ kolom isian (bergeser kalau teks panjang)    ]
//   q  w  e  r  t  y  u  i  o  p
//     a  s  d  f  g  h  j  k  l
//  Shift z  x  c  v  b  n  m  Hapus
//  ?123 <        spasi          >
//
// Halaman "?123" berisi angka dan simbol, "#+=" berisi simbol tambahan.
// Tombol berukuran 48 x 54 piksel (gambar 44 x 50) supaya mudah ditekan di layar resistif.
#pragma once

// Kode tombol khusus (huruf biasa memakai kode hurufnya sendiri)
const char K_SHIFT = 1, K_GANTI = 2, K_HAPUS = 3, K_HALAMAN = 4, K_KIRI = 5, K_KANAN = 6;

// Isi tiap halaman: baris 1 (10 tombol), baris 2 (9 tombol), baris 3 (7 tombol)
const char* const ISI_PAPAN[3][3] = {
  {"qwertyuiop", "asdfghjkl", "zxcvbnm"},
  {"1234567890", ".:/-_@?=&", "#%+!*,;"},
  {"()[]{}<>$~", "'\"`^|\\.-_", "!?,;:/@"},
};

const int KB_Y[4] = {100, 155, 210, 265};   // posisi atas tiap baris tombol
const int KB_H = 54;                         // tinggi satu baris
const int KB_MUAT = 37;                      // jumlah huruf yang terlihat di kolom isian
const Tombol KB_BATAL = {300, 2, 84, 44, "Batal"};
const Tombol KB_OK    = {390, 2, 86, 44, "OK"};

struct Kunci { int16_t x, y, w; char kode; };

// Keadaan papan ketik yang sedang terbuka
String kbTeks;
int kbKursor = 0;        // posisi kursor (0 = sebelum huruf pertama)
int kbAwal = 0;          // huruf pertama yang terlihat di kolom isian
int kbHalaman = 0;       // 0 = huruf, 1 = angka & simbol, 2 = simbol tambahan
int kbShift = 0;         // 0 = huruf kecil, 1 = satu huruf besar, 2 = huruf besar terus (CAPS)
int kbMaks = 0;

// Susun daftar tombol halaman sekarang. Mengembalikan jumlah tombol.
int kbSusun(Kunci* k) {
  int n = 0;
  const char* const* isi = ISI_PAPAN[kbHalaman];
  for (int i = 0; i < 10; i++) k[n++] = {(int16_t)(i * 48), (int16_t)KB_Y[0], 48, isi[0][i]};
  for (int i = 0; i < 9; i++)  k[n++] = {(int16_t)(24 + i * 48), (int16_t)KB_Y[1], 48, isi[1][i]};
  k[n++] = {0, (int16_t)KB_Y[2], 72, kbHalaman == 0 ? K_SHIFT : K_GANTI};
  for (int i = 0; i < 7; i++)  k[n++] = {(int16_t)(72 + i * 48), (int16_t)KB_Y[2], 48, isi[2][i]};
  k[n++] = {408, (int16_t)KB_Y[2], 72, K_HAPUS};
  k[n++] = {0, (int16_t)KB_Y[3], 72, K_HALAMAN};
  k[n++] = {72, (int16_t)KB_Y[3], 48, K_KIRI};
  k[n++] = {120, (int16_t)KB_Y[3], 288, ' '};
  k[n++] = {408, (int16_t)KB_Y[3], 72, K_KANAN};
  return n;
}

// Huruf yang diketik oleh tombol (huruf besar kalau Shift aktif).
char kbHuruf(char kode) {
  if (kbHalaman == 0 && kbShift > 0 && kode >= 'a' && kode <= 'z') return kode - 'a' + 'A';
  return kode;
}

String kbLabel(char kode) {
  switch (kode) {
    case K_SHIFT:   return kbShift == 0 ? "Shift" : kbShift == 1 ? "SHIFT" : "CAPS";
    case K_GANTI:   return kbHalaman == 1 ? "#+=" : "123";
    case K_HAPUS:   return "Hapus";
    case K_HALAMAN: return kbHalaman == 0 ? "?123" : "ABC";
    case K_KIRI:    return "<";
    case K_KANAN:   return ">";
    case ' ':       return "spasi";
    default:        return String(kbHuruf(kode));
  }
}

void kbGambarKunci(const Kunci& k, bool ditekan) {
  bool khusus = k.kode < ' ' || k.kode == ' ';
  uint16_t warna = khusus ? W_PANEL : W_TOMBOL;
  if (k.kode == K_SHIFT && kbShift > 0) warna = W_BIRU;
  if (ditekan) warna = W_AKSEN;
  tft.fillSmoothRoundRect(k.x + 2, k.y + 2, k.w - 4, KB_H - 4, 6, warna, W_LATAR);
  String label = kbLabel(k.kode);
  const GFXfont* f = label.length() > 1 ? F_TEBAL9 : F_TEBAL;
  tulis(label, k.x + k.w / 2, k.y + KB_H / 2, f, k.kode == ' ' ? W_REDUP : W_TEKS, MC_DATUM);
}

void kbGambarPapan() {
  tft.fillRect(0, KB_Y[0], 480, 320 - KB_Y[0], W_LATAR);
  Kunci k[40];
  int n = kbSusun(k);
  for (int i = 0; i < n; i++) kbGambarKunci(k[i], false);
}

// Gambar kolom isian: teks yang terlihat + kursor. Teks panjang bergeser mengikuti kursor.
void kbGambarIsi() {
  if (kbKursor < kbAwal) kbAwal = kbKursor;
  if (kbKursor > kbAwal + KB_MUAT) kbAwal = kbKursor - KB_MUAT;

  const uint16_t latar = rgb(6, 10, 18);
  tft.fillRect(6, 54, 468, 40, latar);
  String tampil = kbTeks.substring(kbAwal, kbAwal + KB_MUAT);

  tft.setTextFont(1);
  tft.setTextSize(2);                                      // huruf 12 x 16 piksel, lebar sama semua
  tft.setTextColor(W_TEKS, latar);
  tft.setTextDatum(TL_DATUM);
  tft.drawString(tampil, 12, 66);
  tft.setTextSize(1);
  if (kbAwal > 0) tft.fillTriangle(8, 74, 11, 70, 11, 78, W_REDUP);                          // ada teks di kiri
  if ((int)kbTeks.length() > kbAwal + KB_MUAT) tft.fillTriangle(471, 74, 468, 70, 468, 78, W_REDUP);  // ada di kanan
  tft.fillRect(11 + (kbKursor - kbAwal) * 12, 62, 2, 24, W_AKSEN);  // kursor
}

// Buka papan ketik. `teks` berisi nilai awal dan diganti hasilnya kalau OK ditekan.
// Isian selalu terlihat (juga password dan API key), supaya mudah dicek saat mengetik di layar kecil.
// False kalau Batal ditekan atau layar terlalu lama tidak disentuh (di menu Pengaturan).
bool ketik(const String& judul, String& teks, int maks) {
  kbTeks = teks;
  kbKursor = kbTeks.length();
  kbAwal = 0;
  kbHalaman = 0;
  kbShift = 0;
  kbMaks = maks;

  tft.fillScreen(W_LATAR);
  tft.fillRect(0, 0, 480, 48, W_PANEL);
  tft.setFreeFont(F_TEBAL9);
  tulis(potong(judul, 280), 10, 24, F_TEBAL9, W_TEKS, ML_DATUM);
  tft.fillSmoothRoundRect(KB_BATAL.x, KB_BATAL.y, KB_BATAL.w, KB_BATAL.h, 8, W_TOMBOL, W_PANEL);
  tulis(KB_BATAL.teks, KB_BATAL.x + KB_BATAL.w / 2, KB_BATAL.y + KB_BATAL.h / 2, F_TEBAL9, W_TEKS, MC_DATUM);
  tft.fillSmoothRoundRect(KB_OK.x, KB_OK.y, KB_OK.w, KB_OK.h, 8, W_HIJAU, W_PANEL);
  tulis(KB_OK.teks, KB_OK.x + KB_OK.w / 2, KB_OK.y + KB_OK.h / 2, F_TEBAL, W_TEKS, MC_DATUM);
  tft.drawRect(4, 52, 472, 44, W_GARIS);
  kbGambarIsi();
  kbGambarPapan();

  Kunci k[40];
  int menyala = -1;                 // tombol yang sedang disorot sesaat setelah ditekan
  uint32_t menyalaSejak = 0;
  sentuhTerakhir = millis();

  while (true) {
    if (terlaluLamaDiam()) return false;
    int n = kbSusun(k);
    if (menyala >= 0 && millis() - menyalaSejak > 120) {  // kembalikan warna tombol
      if (menyala < n) kbGambarKunci(k[menyala], false);
      menyala = -1;
    }
    int x, y;
    if (!sentuh(x, y)) continue;

    if (kena(KB_BATAL, x, y) && y < 50) { klik(); return false; }
    if (kena(KB_OK, x, y) && y < 50) {
      klik();
      teks = kbTeks;
      return true;
    }
    if (y < KB_Y[0]) continue;

    int i = 0;                                              // cari tombol yang ditekan
    while (i < n && !(x >= k[i].x && x < k[i].x + k[i].w && y >= k[i].y && y < k[i].y + KB_H)) i++;
    if (i >= n) continue;
    char kode = k[i].kode;
    bool gambarUlangPapan = false;

    if (kode >= ' ') {                                      // huruf, angka, simbol, spasi
      if ((int)kbTeks.length() >= kbMaks) {
        bip(2, 40);                                         // sudah penuh
        continue;
      }
      kbTeks = kbTeks.substring(0, kbKursor) + kbHuruf(kode) + kbTeks.substring(kbKursor);
      kbKursor++;
      if (kbShift == 1) { kbShift = 0; gambarUlangPapan = true; }
      kbGambarIsi();
    } else if (kode == K_HAPUS) {
      if (kbKursor > 0) {
        kbTeks.remove(kbKursor - 1, 1);
        kbKursor--;
        kbGambarIsi();
      }
    } else if (kode == K_KIRI) {
      if (kbKursor > 0) { kbKursor--; kbGambarIsi(); }
    } else if (kode == K_KANAN) {
      if (kbKursor < (int)kbTeks.length()) { kbKursor++; kbGambarIsi(); }
    } else if (kode == K_SHIFT) {
      kbShift = (kbShift + 1) % 3;
      gambarUlangPapan = true;
    } else if (kode == K_GANTI) {
      kbHalaman = kbHalaman == 1 ? 2 : 1;
      gambarUlangPapan = true;
    } else if (kode == K_HALAMAN) {
      kbHalaman = kbHalaman == 0 ? 1 : 0;
      gambarUlangPapan = true;
    }
    bip(1, 15);

    if (gambarUlangPapan) {
      kbGambarPapan();
      menyala = -1;
    } else {
      kbGambarKunci(k[i], true);                            // sorot sebentar
      menyala = i;
      menyalaSejak = millis();
    }
  }
}
