// Screensaver pengumuman: muncul kalau layar utama dibiarkan diam (tanpa sentuhan dan tanpa kartu).
// Pengumuman tampil bergantian. Kartu tetap bisa di-tap, sentuh layar untuk kembali (lihat absensi.ino).
//
//  [ Judul instansi                           07:45 ]
//  [                                                ]
//  [   ( ikon )   Judul pengumuman                  ]
//  [              Deskripsi dibungkus menjadi       ]
//  [              beberapa baris                    ]
//  [                     o * o                      ]
//  [ Rabu, 30 September 2026        Antrean 2 * ||| ]
#pragma once

const int SS_BILAH = 36;                           // tinggi bilah atas dan bawah
const int SS_BAWAH_Y = 320 - SS_BILAH;             // awal bilah bawah
const int SS_IKON_X = 60, SS_IKON_Y = 148;         // tengah ikon
const int SS_IKON = 72;                            // garis tengah ikon
const int SS_TEKS_X = 112, SS_TEKS_W = 356;        // kolom teks di kanan ikon
const int SS_TENGAH_Y = 148;                       // teks diletakkan di tengah garis ini
const int SS_TITIK_Y = 266;                        // titik posisi pengumuman

int ssIndeks = 0;              // pengumuman yang sedang tampil
uint32_t ssMulaiTampil = 0;    // kapan pengumuman ini mulai tampil

// Bungkus teks menjadi baris-baris selebar `lebar` (font harus sudah dipilih).
// Hanya `maks` baris pertama yang disimpan. Mengembalikan jumlah baris yang dibutuhkan (bisa lebih dari `maks`).
int bungkusTeks(const String& s, int lebar, String baris[], int maks) {
  int n = 0;
  String sekarang = "";
  int i = 0, panjang = s.length();
  while (i < panjang) {
    int j = i;                                             // cari akhir kata
    while (j < panjang && s[j] != ' ' && s[j] != '\n') j++;
    String kata = s.substring(i, j);
    if (kata.length() > 0) {
      String coba = sekarang.length() > 0 ? sekarang + " " + kata : kata;
      if (sekarang.length() > 0 && tft.textWidth(coba) > lebar) {   // tidak muat: pindah baris
        if (n < maks) baris[n] = sekarang;
        n++;
        sekarang = kata;
      } else {
        sekarang = coba;
      }
    }
    if (j < panjang && s[j] == '\n') {                     // baris baru dari teks aslinya
      if (n < maks) baris[n] = sekarang;
      n++;
      sekarang = "";
    }
    i = j + 1;
  }
  if (sekarang.length() > 0) {
    if (n < maks) baris[n] = sekarang;
    n++;
  }
  return n;
}

// Baris terakhir diberi ".." karena teksnya masih berlanjut (font harus sudah dipilih).
String akhiriTitik(String s, int lebar) {
  while (s.length() > 0 && tft.textWidth(s + "..") > lebar) s.remove(s.length() - 1);
  return s + "..";
}

// ---------- Bilah atas dan bawah ----------

void ssJudul(bool paksa) {
  static String tadi = "";
  if (!paksa && atur.judul == tadi) return;
  tadi = atur.judul;
  tft.fillRect(0, 0, 380, SS_BILAH, W_PANEL);
  tulisMuat(atur.judul, 12, SS_BILAH / 2, 360, F_TEBAL, F_TEBAL9, F_TEBAL9, W_TEKS, ML_DATUM);
}

// Jam kecil (kanan atas) dan tanggal (kiri bawah). Digambar ulang hanya saat menit / hari berganti.
void ssWaktu(bool paksa) {
  static int menitTadi = -1, hariTadi = -1;
  static bool validTadi = false;
  bool valid = jamValid();
  if (valid != validTadi) { paksa = true; validTadi = valid; }

  tft.setTextFont(4);
  int lebarJam = tft.textWidth("88:88");
  if (!valid) {
    if (!paksa) return;
    tulisTimpa("--:--", 468, SS_BILAH / 2, 4, W_REDUP, W_PANEL, MR_DATUM, lebarJam);
    tft.fillRect(0, SS_BAWAH_Y, 300, SS_BILAH, W_PANEL);
    tulis("Menunggu jam...", 12, SS_BAWAH_Y + SS_BILAH / 2, F_KECIL, W_REDUP, ML_DATUM);
    return;
  }
  time_t t = time(nullptr);
  struct tm w;
  localtime_r(&t, &w);
  char teks[40];
  if (paksa || w.tm_min != menitTadi) {
    menitTadi = w.tm_min;
    snprintf(teks, sizeof(teks), "%02d:%02d", w.tm_hour, w.tm_min);
    tulisTimpa(teks, 468, SS_BILAH / 2, 4, W_TEKS, W_PANEL, MR_DATUM, lebarJam);
  }
  if (paksa || w.tm_yday != hariTadi) {
    hariTadi = w.tm_yday;
    snprintf(teks, sizeof(teks), "%s, %d %s %d", NAMA_HARI[w.tm_wday], w.tm_mday, NAMA_BULAN[w.tm_mon], w.tm_year + 1900);
    tft.fillRect(0, SS_BAWAH_Y, 300, SS_BILAH, W_PANEL);
    tulis(teks, 12, SS_BAWAH_Y + SS_BILAH / 2, F_KECIL, W_REDUP, ML_DATUM);
  }
}

// Status kecil di kanan bawah: jumlah antrean, titik server, sinyal WiFi. Hanya yang berubah digambar ulang.
void ssStatus(bool paksa) {
  static int barTadi = -1, antreanTadi = -1;
  static StatusServer serverTadi = SERVER_BELUM;
  const int yTengah = SS_BAWAH_Y + SS_BILAH / 2;

  int bar = wifiBar();
  if (paksa || bar != barTadi) {
    barTadi = bar;
    gambarSinyal(444, yTengah + 11, bar, bar == 0 ? W_MERAH : W_GARIS);   // merah semua = WiFi putus
  }
  if (paksa || statusServer != serverTadi) {
    serverTadi = statusServer;
    uint16_t w = statusServer == SERVER_OK ? W_HIJAU : statusServer == SERVER_GAGAL ? W_MERAH : W_GARIS;
    tft.fillSmoothCircle(428, yTengah, 6, w, W_PANEL);
  }
  if (paksa || jumlahAntrean != antreanTadi) {
    antreanTadi = jumlahAntrean;
    String s = jumlahAntrean > 0 ? "Antrean " + String(jumlahAntrean) : " ";
    tulisTimpa(s, 414, yTengah, 2, W_EMAS, W_PANEL, MR_DATUM, 100);
  }
}

// ---------- Isi: ikon, judul, deskripsi, titik posisi ----------

void ssGambarIsi() {
  const Pengumuman& p = daftarPengumuman[ssIndeks];

  // Judul: satu baris font besar kalau muat, kalau tidak dua baris font lebih kecil
  String judul[2];
  const GFXfont* fJudul = F_JUDUL;
  int tinggiJudul = 40, nJudul = 1;
  tft.setFreeFont(F_JUDUL);
  if (tft.textWidth(p.judul) <= SS_TEKS_W) {
    judul[0] = p.judul;
  } else {
    fJudul = F_TEBAL;
    tinggiJudul = 28;
    tft.setFreeFont(fJudul);
    nJudul = bungkusTeks(p.judul, SS_TEKS_W, judul, 2);
    if (nJudul > 2) { nJudul = 2; judul[1] = akhiriTitik(judul[1], SS_TEKS_W); }
  }

  // Deskripsi: sampai 5 baris font biasa. Kalau lebih panjang, font kecil (lebih banyak baris muat).
  const int BARIS_MAKS = 8;
  String isi[BARIS_MAKS];
  const GFXfont* fIsi = F_BIASA;
  int tinggiIsi = 26, maksIsi = 5;
  tft.setFreeFont(fIsi);
  int nIsi = bungkusTeks(p.isi, SS_TEKS_W, isi, maksIsi);
  if (nIsi > maksIsi) {
    fIsi = F_KECIL;
    tinggiIsi = 21;
    maksIsi = (196 - nJudul * tinggiJudul) / tinggiIsi;   // sisa tinggi daerah teks
    tft.setFreeFont(fIsi);
    nIsi = bungkusTeks(p.isi, SS_TEKS_W, isi, maksIsi);
    if (nIsi > maksIsi) { nIsi = maksIsi; isi[nIsi - 1] = akhiriTitik(isi[nIsi - 1], SS_TEKS_W); }
  }

  // Ikon digambar menimpa ikon lama (lingkaran sama besar), jadi tidak perlu dihapus dulu
  gambarIkonPengumuman(p.ikon, SS_IKON_X, SS_IKON_Y, SS_IKON, W_LATAR);

  // Hapus hanya kolom teks, lalu tulis judul dan deskripsi di tengah secara tegak
  tft.fillRect(SS_TEKS_X, SS_BILAH + 8, 480 - SS_TEKS_X, SS_TITIK_Y - SS_BILAH - 18, W_LATAR);
  int tinggi = nJudul * tinggiJudul + (nIsi > 0 ? 10 + nIsi * tinggiIsi : 0);
  int y = SS_TENGAH_Y - tinggi / 2;
  tft.setFreeFont(fJudul);                                 // potong() butuh font yang sedang dipakai
  for (int i = 0; i < nJudul; i++, y += tinggiJudul) tulis(potong(judul[i], SS_TEKS_W), SS_TEKS_X, y, fJudul, W_TEKS);
  y += 10;
  tft.setFreeFont(fIsi);
  for (int i = 0; i < nIsi; i++, y += tinggiIsi) tulis(potong(isi[i], SS_TEKS_W), SS_TEKS_X, y, fIsi, W_REDUP);

  // Titik posisi: yang sedang tampil penuh, lainnya kosong
  tft.fillRect(0, SS_TITIK_Y - 8, 480, 17, W_LATAR);
  if (jumlahPengumuman > 1) {
    int x0 = 240 - (jumlahPengumuman - 1) * 9;
    for (int i = 0; i < jumlahPengumuman; i++) {
      if (i == ssIndeks) tft.fillSmoothCircle(x0 + i * 18, SS_TITIK_Y, 5, W_TEKS, W_LATAR);
      else tft.drawSmoothCircle(x0 + i * 18, SS_TITIK_Y, 5, W_GARIS, W_LATAR);
    }
  }
}

// ---------- Buka dan urus ----------

// Gambar seluruh layar screensaver, mulai dari pengumuman pertama.
void uiScreensaver() {
  tft.fillScreen(W_LATAR);
  tft.fillRect(0, 0, 480, SS_BILAH, W_PANEL);
  tft.fillRect(0, SS_BAWAH_Y, 480, SS_BILAH, W_PANEL);
  ssJudul(true);
  ssWaktu(true);
  ssStatus(true);
  ssIndeks = 0;
  ssMulaiTampil = millis();
  pengumumanBerubah = false;
  ssGambarIsi();
}

// Dipanggil terus selama screensaver tampil. False kalau harus ditutup (daftar pengumuman kosong).
bool ssUrus() {
  if (jumlahPengumuman == 0) return false;
  if (pengumumanBerubah) {                                 // daftar baru dari server: mulai dari awal
    pengumumanBerubah = false;
    ssIndeks = 0;
    ssMulaiTampil = millis();
    ssGambarIsi();
  } else if (jumlahPengumuman > 1 && millis() - ssMulaiTampil >= intervalPengumuman * 1000UL) {
    ssIndeks = (ssIndeks + 1) % jumlahPengumuman;
    ssMulaiTampil = millis();
    ssGambarIsi();
  }
  static uint32_t terakhir = 0;                            // bilah cukup diperiksa tiap 500 ms
  if (millis() - terakhir >= 500) {
    terakhir = millis();
    ssWaktu(false);
    ssStatus(false);
    ssJudul(false);
  }
  return true;
}
