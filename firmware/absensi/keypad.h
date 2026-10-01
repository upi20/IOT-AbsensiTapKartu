// Keypad angka untuk PIN menu Pengaturan (PIN_MIN_ANGKA sampai PIN_MAKS_ANGKA angka, lihat config.h).
//
//  [ Judul                                  ]
//   ● ● ● ●          [ 1 ][ 2 ][ 3 ]
//   pesan            [ 4 ][ 5 ][ 6 ]
//                    [ 7 ][ 8 ][ 9 ]
//   [ Batal ]        [Hapus][ 0 ][ OK ]
#pragma once

int pinSalahKali = 0;          // PIN salah berturut-turut
uint32_t pinKunciSampai = 0;   // keypad terkunci sampai waktu ini (0 = tidak terkunci)

bool pinTerkunci() {
  if (pinKunciSampai == 0) return false;
  if ((int32_t)(pinKunciSampai - millis()) > 0) return true;
  pinKunciSampai = 0;
  return false;
}

const char* const LABEL_KEYPAD[12] = {"1", "2", "3", "4", "5", "6", "7", "8", "9", "Hapus", "0", "OK"};
const Tombol KP_BATAL = {10, 250, 160, 62, "Batal"};

Tombol kpTombol(int i) {
  return {184 + (i % 3) * 98, 52 + (i / 3) * 66, 92, 60, LABEL_KEYPAD[i]};
}

// Titik-titik jumlah angka yang sudah diketik.
void kpGambarTitik(int jumlah) {
  tft.fillRect(0, 70, 178, 60, W_LATAR);
  int n = max(jumlah, PIN_MIN_ANGKA);
  int lebar = n * 20;
  int x0 = 88 - lebar / 2 + 10;
  for (int i = 0; i < n; i++) {
    if (i < jumlah) tft.fillSmoothCircle(x0 + i * 20, 100, 7, W_TEKS, W_LATAR);
    else            tft.drawSmoothCircle(x0 + i * 20, 100, 7, W_GARIS, W_LATAR);
  }
}

void kpGambarPesan(const String& pesan, uint16_t warna) {
  tft.fillRect(0, 140, 178, 100, W_LATAR);
  // Pesan dipecah jadi 2 baris kalau panjang
  int spasi = pesan.length() > 16 ? pesan.lastIndexOf(' ', 16) : -1;
  if (spasi > 0) {
    tulis(pesan.substring(0, spasi), 88, 160, F_KECIL, warna, MC_DATUM);
    tulis(pesan.substring(spasi + 1), 88, 186, F_KECIL, warna, MC_DATUM);
  } else {
    tulis(pesan, 88, 170, F_KECIL, warna, MC_DATUM);
  }
}

// Minta PIN. `pesan` tampil di bawah titik-titik (misalnya "PIN salah").
// pakaiKunci = hormati kunci setelah PIN salah berkali-kali.
// False kalau Batal ditekan atau layar terlalu lama tidak disentuh.
bool ketikPin(const String& judul, String pesan, String& hasil, bool pakaiKunci) {
  uiJudul(judul);
  for (int i = 0; i < 12; i++) gambarTombol(kpTombol(i), i == 11 ? W_HIJAU : W_TOMBOL);
  gambarTombol(KP_BATAL, W_TOMBOL);
  hasil = "";
  kpGambarTitik(0);
  kpGambarPesan(pesan, W_MERAH);

  int sisaTadi = -1;
  sentuhTerakhir = millis();
  while (true) {
    if (terlaluLamaDiam()) return false;
    bool terkunci = pakaiKunci && pinTerkunci();
    if (terkunci) {                                         // tampilkan hitung mundur kunci
      int sisa = (pinKunciSampai - millis() + 999) / 1000;
      if (sisa != sisaTadi) {
        sisaTadi = sisa;
        kpGambarPesan("Terkunci " + String(sisa) + " detik", W_MERAH);
      }
    } else if (sisaTadi >= 0) {                             // kunci baru saja selesai
      sisaTadi = -1;
      kpGambarPesan("Silakan coba lagi", W_REDUP);
    }

    int x, y;
    if (!sentuh(x, y)) continue;
    if (kena(KP_BATAL, x, y)) { klik(); return false; }
    for (int i = 0; i < 12; i++) {
      if (!kena(kpTombol(i), x, y)) continue;
      if (terkunci) { bip(1, 200); break; }
      if (i == 9) {                                         // Hapus
        if (hasil.length() > 0) hasil.remove(hasil.length() - 1);
        klik();
      } else if (i == 11) {                                 // OK
        if ((int)hasil.length() >= PIN_MIN_ANGKA) { klik(); return true; }
        bip(2, 40);
        kpGambarPesan("Minimal " + String(PIN_MIN_ANGKA) + " angka", W_AMBER);
      } else if ((int)hasil.length() < PIN_MAKS_ANGKA) {    // angka
        hasil += LABEL_KEYPAD[i];
        klik();
      } else {
        bip(2, 40);                                         // sudah penuh
      }
      kpGambarTitik(hasil.length());
      break;
    }
  }
}
