// Ikon pengumuman (screensaver): lingkaran berwarna dengan gambar putih di tengahnya.
// Digambar dengan bentuk dasar TFT_eSPI (lingkaran, kotak, segitiga, garis, busur), tanpa bitmap,
// jadi bisa dibuat sebesar apa saja. Semua angka di bawah ditulis untuk ikon 100 piksel
// (jari-jari lingkaran 50, titik 0,0 = tengah), lalu diskalakan ke ukuran sebenarnya.
#pragma once

// Kode ikon sesuai doc/spesifikasi-api.md bagian 8 (urutannya sama dengan KODE_IKON dan WARNA_IKON)
enum JenisIkon { IK_INFO, IK_PENGUMUMAN, IK_KALENDER, IK_JAM, IK_PERINGATAN, IK_RAPAT, IK_LIBUR,
                 IK_SELAMAT, IK_KESEHATAN, IK_BUKU, JUMLAH_IKON };
const char* const KODE_IKON[] = {"info", "pengumuman", "kalender", "jam", "peringatan", "rapat", "libur",
                                 "selamat", "kesehatan", "buku"};
const uint16_t WARNA_IKON[] = {W_BIRU, W_ORANYE, W_MERAH, W_TOSCA, W_AMBER, W_UNGU, W_LANGIT,
                               W_PINK, W_HIJAU, W_COKLAT};

// Kode dari server -> jenis ikon. Kosong atau tidak dikenal = info.
int ikonDariKode(String kode) {
  kode.trim();
  kode.toLowerCase();
  for (int i = 0; i < JUMLAH_IKON; i++) if (kode == KODE_IKON[i]) return i;
  return IK_INFO;
}

// ---------- Alat bantu skala ----------

int ikX, ikY;            // tengah ikon di layar
float ikSkala;           // ukuran sebenarnya / 100
uint16_t ikWarna;        // warna lingkaran ikon yang sedang digambar
const uint16_t IK_PUTIH = W_TEKS;

int ix(float v) { return ikX + (int)lroundf(v * ikSkala); }
int iy(float v) { return ikY + (int)lroundf(v * ikSkala); }
int iu(float v) { return max(1, (int)lroundf(v * ikSkala)); }

// Tepi halus dicampur dengan warna lawannya: putih di atas lingkaran, atau warna lingkaran di atas putih.
uint16_t ikLawan(uint16_t w) { return w == IK_PUTIH ? ikWarna : IK_PUTIH; }

void ikBulat(float x, float y, float r, uint16_t w) { tft.fillSmoothCircle(ix(x), iy(y), iu(r), w, ikLawan(w)); }

void ikKotak(float x, float y, float lebar, float tinggi, float r, uint16_t w) {
  if (r <= 0) tft.fillRect(ix(x), iy(y), iu(lebar), iu(tinggi), w);
  else tft.fillSmoothRoundRect(ix(x), iy(y), iu(lebar), iu(tinggi), iu(r), w, ikLawan(w));
}

void ikGaris(float x0, float y0, float x1, float y1, float tebal, uint16_t w) {
  tft.drawWideLine(ix(x0), iy(y0), ix(x1), iy(y1), tebal * ikSkala, w, ikLawan(w));
}

void ikSegitiga(float x0, float y0, float x1, float y1, float x2, float y2, uint16_t w) {
  tft.fillTriangle(ix(x0), iy(y0), ix(x1), iy(y1), ix(x2), iy(y2), w);
}

// Busur: sudut 0 = bawah, 90 = kiri, 180 = atas, 270 = kanan (searah jarum jam).
void ikBusur(float x, float y, float r, float tebal, int dari, int sampai, uint16_t w) {
  tft.drawArc(ix(x), iy(y), iu(r), iu(r - tebal), dari, sampai, w, ikLawan(w));
}

// ---------- Gambar tiap ikon ----------

void ikonInfo() {                                          // huruf "i"
  ikBulat(0, -24, 7, IK_PUTIH);
  ikKotak(-6, -10, 12, 36, 3, IK_PUTIH);
  ikKotak(-13, 22, 26, 7, 3, IK_PUTIH);
}

void ikonPengumuman() {                                    // pengeras suara dengan gelombang
  ikKotak(-32, -9, 14, 18, 3, IK_PUTIH);                   // pangkal
  ikSegitiga(-19, -9, 4, -22, 4, 22, IK_PUTIH);             // corong
  ikSegitiga(-19, -9, 4, 22, -19, 9, IK_PUTIH);
  ikKotak(-28, 8, 9, 16, 3, IK_PUTIH);                     // pegangan
  ikBusur(4, 0, 18, 4, 225, 315, IK_PUTIH);                // gelombang suara
  ikBusur(4, 0, 29, 4, 225, 315, IK_PUTIH);
}

void ikonKalender() {                                      // lembar kalender dengan kotak tanggal
  ikKotak(-28, -22, 56, 50, 6, IK_PUTIH);
  ikKotak(-28, -9, 56, 3, 0, ikWarna);                     // garis bawah kepala kalender
  for (int i = 0; i < 2; i++) {                            // dua cincin di atas
    float x = i == 0 ? -17 : 10;
    ikKotak(x - 2, -34, 11, 20, 4, ikWarna);
    ikKotak(x, -32, 7, 16, 3, IK_PUTIH);
  }
  for (int b = 0; b < 2; b++)                              // kotak tanggal 3 x 2
    for (int k = 0; k < 3; k++) ikKotak(-20 + k * 15, 0 + b * 14, 10, 10, 2, ikWarna);
}

void ikonJam() {                                           // jam dinding menunjuk pukul 3
  ikBulat(0, 0, 31, IK_PUTIH);
  ikBulat(0, -24, 2.5, ikWarna);                           // penanda 12, 3, 6, 9
  ikBulat(24, 0, 2.5, ikWarna);
  ikBulat(0, 24, 2.5, ikWarna);
  ikBulat(-24, 0, 2.5, ikWarna);
  ikGaris(0, 0, 0, -18, 5, ikWarna);                       // jarum panjang
  ikGaris(0, 0, 13, 0, 5, ikWarna);                        // jarum pendek
  ikBulat(0, 0, 4, ikWarna);
}

void ikonPeringatan() {                                    // segitiga dengan tanda seru
  ikSegitiga(0, -30, -32, 26, 32, 26, IK_PUTIH);
  ikGaris(0, -30, -32, 26, 4, IK_PUTIH);                   // tepi dan sudut dibuat halus
  ikGaris(-32, 26, 32, 26, 4, IK_PUTIH);
  ikGaris(32, 26, 0, -30, 4, IK_PUTIH);
  ikKotak(-4, -10, 8, 21, 3, ikWarna);
  ikBulat(0, 18, 4.5, ikWarna);
}

// Satu orang (kepala + badan), dengan garis pemisah dari orang di belakangnya dan celah di leher.
// Bagian bawah badan dipotong rata oleh ikonRapat().
void ikOrang(float x, float yKepala, float r) {
  float yBadan = yKepala + r * 2.7;
  ikBulat(x, yBadan, r * 1.8 + 3, ikWarna);
  ikBulat(x, yBadan, r * 1.8, IK_PUTIH);
  ikBulat(x, yKepala, r + 2.5, ikWarna);
  ikBulat(x, yKepala, r, IK_PUTIH);
}

void ikonRapat() {                                         // tiga orang
  ikOrang(-20, -8, 7);
  ikOrang(20, -8, 7);
  ikOrang(0, -14, 9);
  ikKotak(-33, 22, 66, 14, 0, ikWarna);                    // potong rata di bawah
}

void ikonLibur() {                                         // payung pantai di atas pasir
  ikBulat(0, 0, 30, IK_PUTIH);                             // payung (setengah lingkaran)
  ikKotak(-31, 0, 62, 31, 0, ikWarna);
  for (int i = 0; i < 4; i++) ikBulat(-22.5 + i * 15, 0, 7.5, ikWarna);   // tepi bergelombang
  ikGaris(0, -35, 0, 28, 4, IK_PUTIH);                     // tiang
  ikKotak(-26, 27, 52, 6, 3, IK_PUTIH);                    // pasir
}

void ikonSelamat() {                                       // kotak hadiah dengan pita
  ikKotak(-28, -14, 56, 12, 3, IK_PUTIH);                  // tutup
  ikKotak(-24, 1, 48, 28, 3, IK_PUTIH);                    // kotak
  ikKotak(-4, -14, 8, 43, 0, ikWarna);                     // pita tegak
  ikBulat(-9, -21, 7, IK_PUTIH);                           // simpul pita
  ikBulat(9, -21, 7, IK_PUTIH);
  ikBulat(-9, -21, 2.5, ikWarna);
  ikBulat(9, -21, 2.5, ikWarna);
}

void ikonKesehatan() {                                     // tanda tambah (palang)
  ikKotak(-9, -28, 18, 56, 4, IK_PUTIH);
  ikKotak(-28, -9, 56, 18, 4, IK_PUTIH);
}

void ikonBuku() {                                          // buku terbuka
  ikSegitiga(-30, -18, -3, -12, -3, 24, IK_PUTIH);         // halaman kiri
  ikSegitiga(-30, -18, -3, 24, -30, 18, IK_PUTIH);
  ikSegitiga(30, -18, 3, -12, 3, 24, IK_PUTIH);            // halaman kanan
  ikSegitiga(30, -18, 3, 24, 30, 18, IK_PUTIH);
  for (int i = 0; i < 3; i++) {                            // baris tulisan
    ikGaris(-24, -8 + i * 9, -9, -5 + i * 9, 2, ikWarna);
    ikGaris(9, -5 + i * 9, 24, -8 + i * 9, 2, ikWarna);
  }
  ikGaris(-33, 22, 0, 29, 3, IK_PUTIH);                    // sampul bawah
  ikGaris(0, 29, 33, 22, 3, IK_PUTIH);
}

// Gambar ikon `jenis` di tengah (cx, cy) dengan garis tengah `ukuran` piksel. `latar` = warna di sekitarnya.
void gambarIkonPengumuman(int jenis, int cx, int cy, int ukuran, uint16_t latar) {
  if (jenis < 0 || jenis >= JUMLAH_IKON) jenis = IK_INFO;
  ikX = cx;
  ikY = cy;
  ikSkala = ukuran / 100.0f;
  ikWarna = WARNA_IKON[jenis];
  tft.fillSmoothCircle(cx, cy, ukuran / 2, ikWarna, latar);
  switch (jenis) {
    case IK_PENGUMUMAN: ikonPengumuman(); break;
    case IK_KALENDER:   ikonKalender();   break;
    case IK_JAM:        ikonJam();        break;
    case IK_PERINGATAN: ikonPeringatan(); break;
    case IK_RAPAT:      ikonRapat();      break;
    case IK_LIBUR:      ikonLibur();      break;
    case IK_SELAMAT:    ikonSelamat();    break;
    case IK_KESEHATAN:  ikonKesehatan();  break;
    case IK_BUKU:       ikonBuku();       break;
    default:            ikonInfo();       break;
  }
}
