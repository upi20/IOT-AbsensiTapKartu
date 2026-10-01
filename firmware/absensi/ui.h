// Tampilan layar dasar (480x320, landscape): warna, alat bantu menggambar, layar kalibrasi,
// layar menyala, layar utama, dan layar hasil tap.
// Bagian yang sering berubah (jam, status) digambar ulang sebagian saja supaya tidak berkedip.
#pragma once

// ---------- Warna ----------

constexpr uint16_t rgb(uint8_t r, uint8_t g, uint8_t b) {
  return ((r & 0xF8) << 8) | ((g & 0xFC) << 3) | (b >> 3);
}
const uint16_t W_LATAR  = rgb(12, 18, 30);     // latar gelap
const uint16_t W_PANEL  = rgb(28, 38, 58);     // bilah atas, tombol
const uint16_t W_GARIS  = rgb(55, 70, 95);
const uint16_t W_TEKS   = TFT_WHITE;
const uint16_t W_GELAP  = rgb(30, 30, 30);     // teks di atas latar kuning
const uint16_t W_REDUP  = rgb(150, 162, 180);  // teks keterangan
const uint16_t W_AKSEN  = rgb(56, 189, 248);   // biru muda
const uint16_t W_HIJAU  = rgb(22, 163, 74);
const uint16_t W_BIRU   = rgb(37, 99, 235);
const uint16_t W_KUNING = rgb(234, 179, 8);
const uint16_t W_AMBER  = rgb(217, 119, 6);
const uint16_t W_MERAH  = rgb(200, 30, 30);
const uint16_t W_ORANYE = rgb(234, 88, 12);
const uint16_t W_ABU    = rgb(71, 85, 105);
const uint16_t W_EMAS   = rgb(250, 204, 21);
const uint16_t W_TOMBOL = rgb(51, 65, 85);     // tombol biasa
// Warna tambahan untuk ikon pengumuman (ikon.h)
const uint16_t W_TOSCA  = rgb(13, 148, 136);
const uint16_t W_UNGU   = rgb(124, 58, 237);
const uint16_t W_LANGIT = rgb(2, 132, 199);
const uint16_t W_PINK   = rgb(219, 39, 119);
const uint16_t W_COKLAT = rgb(146, 84, 30);

// ---------- Font ----------
// Font 2/4/8 bawaan TFT_eSPI bisa menimpa latar (tidak berkedip), dipakai untuk teks yang sering berubah.
// Font FreeSans lebih halus, dipakai untuk teks yang jarang berubah.
const GFXfont* const F_BESAR  = &FreeSansBold24pt7b;
const GFXfont* const F_JUDUL  = &FreeSansBold18pt7b;
const GFXfont* const F_TEBAL  = &FreeSansBold12pt7b;
const GFXfont* const F_TEBAL9 = &FreeSansBold9pt7b;
const GFXfont* const F_SEDANG = &FreeSans18pt7b;
const GFXfont* const F_BIASA  = &FreeSans12pt7b;
const GFXfont* const F_KECIL  = &FreeSans9pt7b;
const GFXfont* const F_MONO   = &FreeMonoBold24pt7b;
const GFXfont* const F_MONO18 = &FreeMonoBold18pt7b;

const char* const NAMA_HARI[]  = {"Minggu", "Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu"};
const char* const NAMA_BULAN[] = {"Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli",
                                  "Agustus", "September", "Oktober", "November", "Desember"};

// ---------- Alat bantu menggambar ----------

// Tulis teks dengan font FreeSans (latar tidak ditimpa).
void tulis(const String& s, int x, int y, const GFXfont* f, uint16_t warna, uint8_t datum = TL_DATUM) {
  tft.setFreeFont(f);
  tft.setTextColor(warna);
  tft.setTextDatum(datum);
  tft.drawString(s, x, y);
}

// Tulis teks dengan font bawaan (2, 4, 8) sambil menimpa latar selebar `lebar` piksel.
void tulisTimpa(const String& s, int x, int y, int font, uint16_t warna, uint16_t latar, uint8_t datum, int lebar) {
  tft.setTextFont(font);
  tft.setTextColor(warna, latar);
  tft.setTextDatum(datum);
  tft.setTextPadding(lebar);
  tft.drawString(s, x, y);
  tft.setTextPadding(0);
}

// Pilih font terbesar yang muat di lebar tertentu.
const GFXfont* fontMuat(const String& s, int lebar, const GFXfont* a, const GFXfont* b, const GFXfont* c) {
  for (const GFXfont* f : {a, b}) {
    tft.setFreeFont(f);
    if (tft.textWidth(s) <= lebar) return f;
  }
  return c;
}

// Potong teks yang terlalu panjang dan beri ".." (font harus sudah dipilih).
String potong(String s, int lebar, int font = 1) {
  if (tft.textWidth(s, font) <= lebar) return s;
  while (s.length() > 1 && tft.textWidth(s + "..", font) > lebar) s.remove(s.length() - 1);
  return s + "..";
}

// Tulis teks dengan font terbesar yang muat di `lebar`, dipotong kalau masih kepanjangan.
void tulisMuat(const String& s, int x, int y, int lebar, const GFXfont* a, const GFXfont* b, const GFXfont* c,
               uint16_t warna, uint8_t datum) {
  if (s.length() == 0) return;
  const GFXfont* f = fontMuat(s, lebar, a, b, c);
  tft.setFreeFont(f);
  tulis(potong(s, lebar), x, y, f, warna, datum);
}

void tulisTengahMuat(const String& s, int y, const GFXfont* a, const GFXfont* b, const GFXfont* c, uint16_t warna) {
  tulisMuat(s, 240, y, 470, a, b, c, warna, TC_DATUM);
}

struct Tombol { int x, y, w, h; const char* teks; };

// `latar` = warna di sekitar tombol (W_PANEL kalau tombol ada di bilah judul).
void gambarTombol(const Tombol& t, uint16_t warna, bool aktif = true, uint16_t latar = W_LATAR) {
  tft.fillSmoothRoundRect(t.x, t.y, t.w, t.h, 10, aktif ? warna : W_PANEL, latar);
  const GFXfont* f = fontMuat(t.teks, t.w - 8, F_TEBAL, F_TEBAL9, F_TEBAL9);
  tulis(t.teks, t.x + t.w / 2, t.y + t.h / 2, f, aktif ? W_TEKS : W_GARIS, MC_DATUM);
}

// Apakah titik sentuh (x, y) mengenai tombol? Diberi kelonggaran 6 piksel.
bool kena(const Tombol& t, int x, int y) {
  return x >= t.x - 6 && x <= t.x + t.w + 6 && y >= t.y - 6 && y <= t.y + t.h + 6;
}

void gambarGir(int cx, int cy, uint16_t warna, uint16_t latar) {
  for (int i = 0; i < 4; i++) {                             // 8 gigi dari 4 garis silang
    float a = i * PI / 4;
    float dx = cos(a) * 14, dy = sin(a) * 14;
    tft.drawWideLine(cx - dx, cy - dy, cx + dx, cy + dy, 6, warna, latar);
  }
  tft.fillSmoothCircle(cx, cy, 10, warna, latar);
  tft.fillSmoothCircle(cx, cy, 4, latar, warna);
}

void gambarSinyal(int x, int yBawah, int bar, uint16_t mati = W_GARIS) {
  for (int i = 0; i < 4; i++) {
    int tinggi = 6 + i * 5;
    tft.fillRect(x + i * 7, yBawah - tinggi, 5, tinggi, i < bar ? W_TEKS : mati);
  }
}

// Gembok kecil (WiFi berpassword), kira-kira 14 x 18 piksel.
void gambarGembok(int x, int y, uint16_t warna, uint16_t latar) {
  tft.drawSmoothArc(x + 7, y + 7, 6, 4, 90, 270, warna, latar);
  tft.fillRoundRect(x, y + 7, 14, 11, 2, warna);
}

// Ikon kartu dengan gelombang (seperti logo "tap").
void gambarKartu(int x, int y, uint16_t latar) {
  tft.fillSmoothRoundRect(x, y, 60, 42, 6, W_AKSEN, latar);
  tft.fillRoundRect(x + 9, y + 13, 15, 12, 2, W_EMAS);         // chip
  for (int i = 0; i < 3; i++) {
    int r = 10 + i * 8;
    tft.drawArc(x + 66, y + 21, r, r - 3, 220, 320, W_AKSEN, latar);
  }
}

// Ikon bulat putih di layar hasil: centang, silang, tanda seru, atau tanda tanya.
enum Ikon { IKON_CENTANG, IKON_SILANG, IKON_SERU, IKON_TANYA };
void gambarIkon(Ikon ikon, int cx, int cy, uint16_t latar) {
  tft.fillSmoothCircle(cx, cy, 34, W_TEKS, latar);
  if (ikon == IKON_CENTANG) {
    tft.drawWideLine(cx - 16, cy + 1, cx - 5, cy + 13, 8, latar, W_TEKS);
    tft.drawWideLine(cx - 5, cy + 13, cx + 17, cy - 11, 8, latar, W_TEKS);
  } else if (ikon == IKON_SILANG) {
    tft.drawWideLine(cx - 13, cy - 13, cx + 13, cy + 13, 8, latar, W_TEKS);
    tft.drawWideLine(cx + 13, cy - 13, cx - 13, cy + 13, 8, latar, W_TEKS);
  } else if (ikon == IKON_SERU) {
    tft.drawWideLine(cx, cy - 18, cx, cy + 4, 8, latar, W_TEKS);
    tft.fillSmoothCircle(cx, cy + 16, 5, latar, W_TEKS);
  } else {
    tulis("?", cx, cy + 2, F_BESAR, latar, MC_DATUM);
  }
}

// Bilah judul di atas layar pengaturan.
const int TINGGI_BILAH = 44;
void uiJudul(const String& judul) {
  tft.fillScreen(W_LATAR);
  tft.fillRect(0, 0, 480, TINGGI_BILAH, W_PANEL);
  tft.setFreeFont(F_TEBAL);
  tulis(potong(judul, 290), 14, 22, F_TEBAL, W_TEKS, ML_DATUM);
}

// ---------- Layar kalibrasi ----------

// Tombol Batal saat kalibrasi dari menu (dibaca dengan kalibrasi lama, lihat touch.h).
const Tombol KAL_BATAL = {170, 216, 140, 50, "Batal"};

void uiKalibrasiTitik(int nomor, int x, int y, bool bolehBatal) {
  tft.fillScreen(TFT_BLACK);
  tulis("Kalibrasi layar sentuh", 240, 105, F_TEBAL, W_TEKS, TC_DATUM);
  tulis("Tekan tepat di tengah tanda +", 240, 140, F_BIASA, W_REDUP, TC_DATUM);
  tulis("Titik " + String(nomor) + " dari 3", 240, 180, F_KECIL, W_AKSEN, TC_DATUM);
  if (bolehBatal) {
    gambarTombol(KAL_BATAL, W_TOMBOL, true, TFT_BLACK);
    tulis("Batal = kalibrasi lama tetap dipakai", 240, 284, F_KECIL, W_REDUP, TC_DATUM);
  }
  tft.drawCircle(x, y, 10, TFT_MAGENTA);
  tft.drawLine(x - 16, y, x + 16, y, TFT_YELLOW);
  tft.drawLine(x, y - 16, x, y + 16, TFT_YELLOW);
}

void uiKalibrasiUlang() {
  tft.fillScreen(TFT_BLACK);
  tulis("Kurang tepat, diulang", 240, 140, F_TEBAL, W_MERAH, TC_DATUM);
  tulis("Tekan tepat di tengah tanda +", 240, 175, F_BIASA, W_REDUP, TC_DATUM);
}

void uiKalibrasiTawaran() {
  tft.fillScreen(W_LATAR);
  tulis("Absensi RFID", 240, 120, F_JUDUL, W_TEKS, TC_DATUM);
  tulis("Tekan BOOT sekarang untuk kalibrasi layar sentuh", 240, 190, F_KECIL, W_REDUP, TC_DATUM);
}

void uiKalibrasiLepasBoot() {
  tft.fillScreen(TFT_BLACK);
  tulis("Lepaskan tombol BOOT", 240, 150, F_TEBAL, W_TEKS, TC_DATUM);
}

// ---------- Layar menyala (boot) ----------

enum StatusLangkah { LANGKAH_TUNGGU, LANGKAH_PROSES, LANGKAH_OK, LANGKAH_GAGAL, LANGKAH_LEWAT };
const char* const NAMA_LANGKAH[] = {"Layar", "Layar sentuh", "Pembaca RFID", "WiFi", "Server", "Jam"};
const int JUMLAH_LANGKAH = 6;

int yLangkah(int i) { return 104 + i * 34; }

void uiLangkah(int i, StatusLangkah s, const String& ket) {
  int y = yLangkah(i);
  uint16_t warna = s == LANGKAH_OK ? W_HIJAU : s == LANGKAH_GAGAL ? W_MERAH : s == LANGKAH_PROSES ? W_AMBER : W_GARIS;
  tft.fillRect(56, y - 15, 30, 30, W_LATAR);
  if (s == LANGKAH_OK) {
    tft.fillSmoothCircle(70, y, 11, warna, W_LATAR);
    tft.drawWideLine(64, y, 68, y + 5, 3, W_TEKS, warna);
    tft.drawWideLine(68, y + 5, 76, y - 5, 3, W_TEKS, warna);
  } else if (s == LANGKAH_GAGAL) {
    tft.fillSmoothCircle(70, y, 11, warna, W_LATAR);
    tft.drawWideLine(65, y - 5, 75, y + 5, 3, W_TEKS, warna);
    tft.drawWideLine(75, y - 5, 65, y + 5, 3, W_TEKS, warna);
  } else if (s == LANGKAH_LEWAT) {
    tft.fillRect(63, y - 1, 14, 3, warna);
  } else {
    tft.drawCircle(70, y, 10, warna);
    tft.drawCircle(70, y, 9, warna);
  }
  tft.fillRect(262, y - 15, 218, 30, W_LATAR);
  uint16_t warnaKet = s == LANGKAH_GAGAL ? W_MERAH : s == LANGKAH_PROSES ? W_AMBER : W_REDUP;
  tulisMuat(ket, 262, y, 210, F_BIASA, F_KECIL, F_KECIL, warnaKet, ML_DATUM);
}

void uiBoot() {
  tft.fillScreen(W_LATAR);
  gambarKartu(118, 22, W_LATAR);
  tulis("Absensi RFID", 212, 18, F_JUDUL, W_TEKS);
  tulis("Terintegrasi  -  ID " + idAlat + "  -  v" + VERSI_FIRMWARE, 214, 58, F_KECIL, W_REDUP);
  tft.drawFastHLine(40, 84, 400, W_GARIS);
  for (int i = 0; i < JUMLAH_LANGKAH; i++) {
    tulis(NAMA_LANGKAH[i], 94, yLangkah(i), F_BIASA, W_TEKS, ML_DATUM);
    uiLangkah(i, LANGKAH_TUNGGU, "");
  }
}

// ---------- Layar utama ----------

const Tombol TOMBOL_GIR = {404, 0, 76, 60, ""};   // daerah sentuh tombol pengaturan (pojok kanan atas)

// Bilah status atas: sinyal WiFi, titik server, jumlah antrean, ID alat, tombol gir.
// Hanya bagian yang berubah yang digambar ulang.
void uiBilahStatus(bool paksa) {
  static int barTadi = -1;
  static StatusServer serverTadi = SERVER_BELUM;
  static int antreanTadi = -1;

  int bar = wifiBar();
  if (paksa || bar != barTadi) {
    barTadi = bar;
    gambarSinyal(12, 32, bar);
    tft.fillRect(42, 6, 12, 14, W_PANEL);
    if (bar == 0) {                                          // tanda silang kecil = WiFi putus
      tft.drawWideLine(44, 8, 51, 15, 2, W_MERAH, W_PANEL);
      tft.drawWideLine(51, 8, 44, 15, 2, W_MERAH, W_PANEL);
    }
  }
  if (paksa || statusServer != serverTadi) {
    serverTadi = statusServer;
    uint16_t w = statusServer == SERVER_OK ? W_HIJAU : statusServer == SERVER_GAGAL ? W_MERAH : W_GARIS;
    tft.fillSmoothCircle(62, 22, 6, w, W_PANEL);
    const char* s = statusServer == SERVER_OK ? "Server OK" : statusServer == SERVER_GAGAL ? "Server gagal" : "Server -";
    tulisTimpa(s, 74, 22, 2, W_TEKS, W_PANEL, ML_DATUM, 86);
  }
  if (paksa || jumlahAntrean != antreanTadi) {
    antreanTadi = jumlahAntrean;
    String s = jumlahAntrean > 0 ? "Antrean " + String(jumlahAntrean) : " ";
    tulisTimpa(s, 166, 22, 2, W_EMAS, W_PANEL, ML_DATUM, 90);
  }
  if (paksa) tulisTimpa(idAlat, 396, 22, 2, W_REDUP, W_PANEL, MR_DATUM, 0);
}

// Judul dari pengaturan (bisa diganti server lewat config.title).
void uiJudulUtama(bool paksa) {
  static String tadi = "";
  if (!paksa && atur.judul == tadi) return;
  tadi = atur.judul;
  tft.fillRect(0, TINGGI_BILAH, 480, 42, W_LATAR);
  tulisMuat(atur.judul, 240, 66, 460, F_JUDUL, F_TEBAL, F_TEBAL9, W_TEKS, MC_DATUM);
}

// Jam besar, detik dan tanggal. Hanya bagian yang berubah yang digambar ulang.
void uiJam(bool paksa) {
  static time_t tadi = 0;
  static int menitTadi = -1, hariTadi = -1;
  static bool validTadi = false;

  bool valid = jamValid();
  if (valid != validTadi) { paksa = true; validTadi = valid; }

  tft.setTextFont(8);
  int lebarHM = tft.textWidth("88:88");
  tft.setTextFont(4);
  int lebarDetik = tft.textWidth("88");
  int x = 240 - (lebarHM + 12 + lebarDetik) / 2;
  const int yJam = 90;
  const int yTanggal = 172;

  if (!valid) {
    if (paksa) {
      tulisTimpa("--:--", x, yJam, 8, W_GARIS, W_LATAR, TL_DATUM, lebarHM);
      tulisTimpa("  ", x + lebarHM + 12, yJam + 54, 4, W_GARIS, W_LATAR, TL_DATUM, lebarDetik);
      tulisTimpa("Menunggu jam...", 240, yTanggal, 4, W_REDUP, W_LATAR, TC_DATUM, 440);
    }
    return;
  }
  time_t t = time(nullptr);
  if (!paksa && t == tadi) return;
  tadi = t;
  struct tm w;
  localtime_r(&t, &w);
  char teks[40];

  if (paksa || w.tm_min != menitTadi) {
    menitTadi = w.tm_min;
    snprintf(teks, sizeof(teks), "%02d:%02d", w.tm_hour, w.tm_min);
    tulisTimpa(teks, x, yJam, 8, W_TEKS, W_LATAR, TL_DATUM, lebarHM);
  }
  snprintf(teks, sizeof(teks), "%02d", w.tm_sec);
  tulisTimpa(teks, x + lebarHM + 12, yJam + 54, 4, W_AKSEN, W_LATAR, TL_DATUM, lebarDetik);

  if (paksa || w.tm_yday != hariTadi) {
    hariTadi = w.tm_yday;
    snprintf(teks, sizeof(teks), "%s, %d %s %d", NAMA_HARI[w.tm_wday], w.tm_mday, NAMA_BULAN[w.tm_mon], w.tm_year + 1900);
    tulisTimpa(teks, 240, yTanggal, 4, W_REDUP, W_LATAR, TC_DATUM, 440);
  }
}

// Baris informasi paling bawah: peringatan kalau ada masalah, atau "Siap".
void uiInfo(bool paksa) {
  static String teksTadi = "";
  static uint16_t latarTadi = 0;
  String teks;
  uint16_t latar = W_PANEL, warna = W_REDUP;

  if (!rfidAda)                          { teks = "RFID tidak terdeteksi, cek kabel";        latar = W_MERAH;  warna = W_TEKS; }
  else if (!wifiTerhubung())             { teks = "WiFi terputus - tap tetap disimpan";      latar = W_ORANYE; warna = W_TEKS; }
  else if (statusServer == SERVER_GAGAL && kodeServerTerakhir == 401)
                                         { teks = "API key ditolak server (401)";            latar = W_MERAH;  warna = W_TEKS; }
  else if (statusServer == SERVER_GAGAL && kodeServerTerakhir == 429)
                                         { teks = "Server sibuk, dicoba lagi";               latar = W_MERAH;  warna = W_TEKS; }
  else if (statusServer == SERVER_GAGAL) { teks = "Server tidak menjawab, dicoba lagi";      latar = W_MERAH;  warna = W_TEKS; }
  else if (jumlahAntrean > 0)            { teks = String(jumlahAntrean) + " tap menunggu dikirim"; latar = W_AMBER; warna = W_TEKS; }
  else if (!jamValid())                  { teks = "Menunggu jam dari server"; }
  else                                   { teks = "Siap"; }
  if (!paksa && teks == teksTadi && latar == latarTadi) return;
  teksTadi = teks;
  latarTadi = latar;

  tft.fillRect(0, 282, 480, 38, latar);
  tulisMuat(teks, 240, 301, 460, F_TEBAL, F_TEBAL9, F_TEBAL9, warna, MC_DATUM);
}

void uiUtama() {
  tft.fillScreen(W_LATAR);
  tft.fillRect(0, 0, 480, TINGGI_BILAH, W_PANEL);
  gambarGir(452, 22, W_REDUP, W_PANEL);
  uiBilahStatus(true);
  uiJudulUtama(true);
  uiJam(true);
  tft.drawFastHLine(40, 206, 400, W_GARIS);
  gambarKartu(62, 220, W_LATAR);
  tulis("Tempelkan kartu", 168, 222, F_JUDUL, W_TEKS);
  tulis("untuk absen masuk atau pulang", 170, 260, F_KECIL, W_REDUP);
  uiInfo(true);
}

// Dipanggil terus selama layar utama tampil.
void uiUtamaUrus() {
  uiJam(false);
  static uint32_t terakhir = 0;
  if (millis() - terakhir < 500) return;
  terakhir = millis();
  uiBilahStatus(false);
  uiJudulUtama(false);
  uiInfo(false);
}

// ---------- Layar mengirim & hasil ----------

// Tanda "sedang mengirim" di bilah bawah, di atas layar apa pun yang sedang tampil.
// Sengaja kecil: menggambar ulang seluruh layar makan waktu sekitar 0,15 detik.
void uiMengirim(const String& rfid) {
  tft.fillRect(0, 282, 480, 38, W_BIRU);
  tulisMuat("Mengirim...  Kartu " + rfid, 240, 301, 460, F_TEBAL, F_TEBAL9, F_TEBAL9, W_TEKS, MC_DATUM);
}

// Tata letak layar hasil: judul di atas, kotak foto/ikon 160x160 di kiri, keterangan di kanan.
const int FOTO_X = 14, FOTO_Y = 84;
const int KANAN_X = 190, KANAN_W = 280;

uint16_t latarHasil = W_LATAR;
Ikon ikonHasil = IKON_CENTANG;
int lebarBilahHasil = 0;

// Isi kotak kiri dengan ikon (dipakai kalau tidak ada foto, atau foto gagal).
void uiHasilKotakIkon() {
  tft.fillRect(FOTO_X, FOTO_Y, FOTO_MAKS_PX, FOTO_MAKS_PX, latarHasil);
  gambarIkon(ikonHasil, FOTO_X + FOTO_MAKS_PX / 2, FOTO_Y + FOTO_MAKS_PX / 2, latarHasil);
}

void uiHasil(const HasilTap& h) {
  String judul, utama, pesan = h.pesan, jam = h.jam, info0 = h.info[0], info1 = h.info[1];
  uint16_t warnaTeks = W_TEKS;
  bool utamaAngka = false;                                  // nomor kartu memakai font angka besar
  utama = h.nama;

  switch (h.jenis) {
    case HASIL_MASUK:
      latarHasil = W_HIJAU; ikonHasil = IKON_CENTANG; judul = "MASUK";
      break;
    case HASIL_PULANG:
      latarHasil = W_BIRU; ikonHasil = IKON_CENTANG; judul = "PULANG";
      break;
    case HASIL_DUPLIKAT:
      latarHasil = W_KUNING; ikonHasil = IKON_SERU; judul = "SUDAH TERCATAT"; warnaTeks = W_GELAP;
      break;
    case HASIL_TIDAK_TERDAFTAR:
      latarHasil = W_MERAH; ikonHasil = IKON_TANYA; judul = "KARTU TIDAK TERDAFTAR";
      utama = h.rfid; utamaAngka = true;
      if (pesan.length() == 0) pesan = "Minta admin mendaftarkan kartu";
      break;
    case HASIL_DITOLAK:
      latarHasil = W_MERAH; ikonHasil = IKON_SILANG; judul = "DITOLAK";
      break;
    case HASIL_INFO:
      latarHasil = W_ABU; ikonHasil = IKON_SERU; judul = "INFO";
      break;
    case HASIL_TERSIMPAN:
      latarHasil = W_AMBER; ikonHasil = IKON_SERU; judul = "TERSIMPAN";
      utama = "Akan dikirim"; pesan = "Server belum bisa dihubungi";
      info0 = "Kartu " + h.rfid; info1 = h.pesan;
      break;
    default:   // HASIL_GAGAL (HTTP 4xx atau balasan rusak): tidak disimpan
      latarHasil = W_ORANYE; ikonHasil = IKON_SILANG; judul = "GAGAL";
      utama = teksGalat(h.kode); info0 = "Tap tidak tercatat"; info1 = "Kartu " + h.rfid;
      break;
  }
  if (utama.length() == 0) { utama = pesan; pesan = ""; }  // tidak ada nama: pesan naik ke baris utama

  tft.fillScreen(latarHasil);
  tulisMuat(judul, 240, 38, 460, F_BESAR, F_JUDUL, F_TEBAL, warnaTeks, MC_DATUM);
  tft.fillRect(14, 72, 452, 2, warnaTeks);
  uiHasilKotakIkon();

  if (utamaAngka) tulisMuat(utama, KANAN_X, 110, KANAN_W, F_MONO, F_MONO18, F_TEBAL, warnaTeks, ML_DATUM);
  else            tulisMuat(utama, KANAN_X, 110, KANAN_W, F_JUDUL, F_TEBAL, F_TEBAL9, warnaTeks, ML_DATUM);
  tulisMuat(pesan, KANAN_X, 152, KANAN_W, F_BIASA, F_KECIL, F_KECIL, warnaTeks, ML_DATUM);
  if (jam.length() > 0 && h.jenis != HASIL_GAGAL)
    tulisMuat("Pukul " + jam, KANAN_X, 188, KANAN_W, F_TEBAL, F_TEBAL9, F_TEBAL9, warnaTeks, ML_DATUM);
  tulisMuat(info0, KANAN_X, 222, KANAN_W, F_KECIL, F_KECIL, F_KECIL, warnaTeks, ML_DATUM);
  tulisMuat(info1, KANAN_X, 246, KANAN_W, F_KECIL, F_KECIL, F_KECIL, warnaTeks, ML_DATUM);
  tulis("Sentuh layar untuk kembali", 240, 290, F_KECIL, warnaTeks, TC_DATUM);

  lebarBilahHasil = 480;
  tft.fillRect(0, 312, 480, 8, warnaTeks);                  // bilah hitung mundur
}

// Perpendek bilah hitung mundur. `sisa` = 0.0 .. 1.0
void uiHasilSisa(float sisa) {
  int lebar = constrain((int)(480 * sisa), 0, 480);
  if (lebar >= lebarBilahHasil) return;
  tft.fillRect(lebar, 312, lebarBilahHasil - lebar, 8, latarHasil);
  lebarBilahHasil = lebar;
}
