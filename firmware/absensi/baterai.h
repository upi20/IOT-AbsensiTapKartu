// Indikator baterai UPS (opsional): modul LX-2BUPS + 2 x 18650, output UPS+ 5V ke VIN.
// Dua pembagi tegangan, masing-masing resistor 10k + 10k:
//   + baterai   --10k--+--10k-- GND    titik tengah -> D34 (PIN_BATERAI): setengah tegangan baterai
//   5V+ charger --10k--+--10k-- GND    titik tengah -> VP  (PIN_LISTRIK): +-2,5 V = listrik ada, 0 V = padam
// Resistor pertama disolder menempel di modul UPS, supaya kabel panjang ke ESP32 tidak membawa arus besar
// kalau tergores. Arus lewat pembagi hanya +-0,2 mA.
//
// Terdeteksi otomatis: kalau tegangan baterai tidak masuk akal (pembagi tidak terpasang), ikon tidak tampil
// dan heartbeat mengirim raw.power = null. Alat tanpa UPS bekerja seperti biasa.
// Baterai habis diputus sendiri oleh proteksi FM5056 di modul UPS; alat hanya memberi peringatan.
#pragma once
#include <ArduinoJson.h>

const uint32_t BATERAI_BACA_MS    = 1000;  // baca tegangan tiap detik
const int      BATERAI_MIN_MV     = 2500;  // di luar rentang ini = pembagi baterai tidak terpasang
const int      BATERAI_MAKS_MV    = 4600;
const int      LISTRIK_ADA_MV     = 1500;  // tegangan di pin VP: di atas ini = listrik ada (normal +-2500)
const int      LISTRIK_PADAM_MV   = 1000;  // di bawah ini = padam (di antaranya: status tidak berubah)
const uint32_t BATERAI_TENANG_MS  = 30000; // setelah listrik padam, angka baterai baru dihitung setelah ini

bool upsAda = false;        // pembagi baterai terpasang
bool listrikAda = true;     // input 5V charger ada (listrik PLN)
bool bateraiLemah = false;  // listrik padam dan baterai di bawah BATERAI_LEMAH_MV
int  bateraiMv = 0;         // tegangan baterai (dirata-rata)
int  bateraiMvSekarang = 0; // bacaan terakhir apa adanya (untuk Info alat), juga saat UPS tidak terdeteksi
int  bateraiPersen = 0;     // perkiraan 0-100 (saat dicas terbaca lebih tinggi)
int  persenTersimpan = -1;  // angka terakhir saat listrik padam, di memori (NVS "bat_pct"); -1 = tidak ada

// Simpan angka saat padam (-1 = hapus). Hanya ditulis kalau berubah: paling banyak +-100 kali tiap padam.
void bateraiSimpanPersen(int p) {
  if (p == persenTersimpan) return;
  persenTersimpan = p;
  prefs.putInt("bat_pct", p);
}

// Rata-rata 16 bacaan, dalam milivolt di pin (sudah dikoreksi kalibrasi pabrik ESP32).
int bateraiBacaPin(int pin) {
  uint32_t total = 0;
  for (int i = 0; i < 16; i++) total += analogReadMilliVolts(pin);
  return total / 16;
}

// Perkiraan isi baterai Li-ion dari tegangannya (garis lurus di antara titik-titik ini).
int bateraiPersenDari(int mv) {
  static const int16_t TITIK[][2] = {{3300, 0}, {3500, 8}, {3600, 15}, {3700, 35}, {3800, 55},
                                     {3900, 70}, {4000, 82}, {4100, 93}, {4180, 100}};
  const int n = sizeof(TITIK) / sizeof(TITIK[0]);
  if (mv <= TITIK[0][0]) return 0;
  for (int i = 1; i < n; i++) {
    if (mv <= TITIK[i][0]) {
      int dv = TITIK[i][0] - TITIK[i - 1][0], dp = TITIK[i][1] - TITIK[i - 1][1];
      return TITIK[i - 1][1] + (mv - TITIK[i - 1][0]) * dp / dv;
    }
  }
  return 100;
}

int bateraiBacaMv() { return bateraiBacaPin(PIN_BATERAI) * 2 * BATERAI_KALIBRASI; }   // x2: pembagi setengah

void bateraiBaca(bool pertama) {
  static int mvTadi = 0;
  if (pertama) {
    mvTadi = bateraiBacaMv();
    delay(20);
  }
  int mv = bateraiBacaMv();
  bateraiMvSekarang = mv;
  int listrikMv = bateraiBacaPin(PIN_LISTRIK);

  // Pasang / lepas pembagi baterai: baru dianggap berubah setelah 3 bacaan berturut-turut.
  // Pin tanpa pembagi mengambang dan angkanya loncat-loncat; baterai asli stabil.
  static int beda = 0;
  bool masukAkal = mv >= BATERAI_MIN_MV && mv <= BATERAI_MAKS_MV && abs(mv - mvTadi) < 200;
  mvTadi = mv;
  bool baru = pertama;                                            // baru menyala / UPS baru terdeteksi
  if (pertama || masukAkal == upsAda) {
    beda = 0;
  } else if (++beda >= 3) {
    beda = 0;
    baru = true;
    heartbeatSegera = true;
  }
  if (baru) {
    upsAda = masukAkal;
    Serial.printf("Baterai: %s (%d mV)\n", upsAda ? "UPS terpasang" : "tidak ada UPS", mv);
  }
  if (!upsAda) {
    bateraiLemah = false;
    return;
  }
  // Tegangan diperhalus (+-16 detik): beban naik-turun saat tap, WiFi, dan lampu layar
  bateraiMv = baru ? mv : (bateraiMv * 15 + mv + 8) / 16;

  // Persen seperti di HP: saat listrik padam angkanya hanya boleh turun. Tegangan yang naik lagi setelah
  // beban berat (layar meredup, WiFi diam, alat dimatikan sebentar) bukan tanda baterai terisi.
  // - 30 detik pertama setelah padam diabaikan: beban paling berat, layar memakai angka terakhir saat dicas.
  // - Angka saat padam disimpan di memori, jadi alat yang dimatikan lalu dinyalakan lagi (masih padam)
  //   langsung memakai angka itu dan tetap tidak naik. Dihapus begitu listrik ada lagi.
  int persen = bateraiPersenDari(bateraiMv);
  static uint32_t padamSejak = 0;
  static bool ikutTegangan = false;    // belum ada angka tersimpan: ikut tegangan sampai 30 detik
  bool listrik = listrikAda;
  if (listrikMv >= LISTRIK_ADA_MV) listrik = true;
  else if (listrikMv <= LISTRIK_PADAM_MV) listrik = false;
  if (baru || listrik != listrikAda) {
    listrikAda = listrik;
    if (!listrikAda) padamSejak = millis();
    if (baru && !listrikAda) {                                    // menyala saat listrik padam
      ikutTegangan = persenTersimpan < 0;
      bateraiPersen = ikutTegangan ? persen : persenTersimpan;
    }
    Serial.printf("Baterai: listrik %s, baterai %d mV\n", listrikAda ? "ada" : "PADAM", bateraiMv);
    if (!baru) heartbeatSegera = true;                            // server segera tahu
  }

  bool tenang = millis() - padamSejak >= BATERAI_TENANG_MS;
  if (listrikAda) {                                               // sedang dicas: ikut tegangan
    bateraiPersen = persen;
    bateraiSimpanPersen(-1);
  } else if (!tenang) {
    if (ikutTegangan) bateraiPersen = persen;
  } else {
    bateraiPersen = ikutTegangan ? persen : min(bateraiPersen, persen);
    ikutTegangan = false;
    bateraiSimpanPersen(bateraiPersen);
  }

  // Lemah: sekali lemah tetap lemah sampai listrik kembali
  bool lemah = bateraiLemah;
  if (listrikAda) lemah = false;
  else if (tenang && bateraiMv < BATERAI_LEMAH_MV) lemah = true;
  if (lemah != bateraiLemah) {
    bateraiLemah = lemah;
    Serial.printf("Baterai: %s (%d mV)\n", bateraiLemah ? "LEMAH" : "tidak lemah lagi", bateraiMv);
    if (bateraiLemah) heartbeatSegera = true;
  }
}

// Dipanggil sekali di setup().
void bateraiMulai() {
  analogSetPinAttenuation(PIN_BATERAI, ADC_11db);                  // rentang pin sampai +-3,1 V
  analogSetPinAttenuation(PIN_LISTRIK, ADC_11db);
  persenTersimpan = prefs.getInt("bat_pct", -1);
  bateraiBaca(true);
}

// Dipanggil terus dari urusLatar().
void bateraiUrus() {
  static uint32_t terakhir = 0;
  if (millis() - terakhir < BATERAI_BACA_MS) return;
  terakhir = millis();
  bateraiBaca(false);
}

// Untuk heartbeat (raw.power). Tidak ada UPS = null.
void bateraiIsiRaw(JsonObject raw) {
  if (!upsAda) {
    raw["power"] = nullptr;
    return;
  }
  JsonObject p = raw["power"].to<JsonObject>();
  p["source"] = listrikAda ? "mains" : "battery";
  p["battery_mv"] = bateraiMv;
  p["battery_pct"] = bateraiPersen;
  p["battery_low"] = bateraiLemah;
}

String bateraiPersenTeks() { return String(bateraiPersen) + "%"; }

// "3,72 V"
String bateraiVolt(int mv) {
  char v[12];
  snprintf(v, sizeof(v), "%d,%02d V", mv / 1000, (mv % 1000) / 10);
  return v;
}

// Info alat, baris "Tegangan baterai": bacaan saat ini apa adanya (berubah-ubah mengikuti beban), dan persen
// menurut tegangan itu. Berbeda dengan angka di layar utama yang dirata-rata dan tidak naik saat padam.
// Tanpa UPS: tegangan di pin tetap ditampilkan, untuk memeriksa kabel pembagi.
String bateraiTeksReal() {
  if (!upsAda) return "Tidak terdeteksi (terbaca " + bateraiVolt(max(0, bateraiMvSekarang)) + ")";
  return bateraiVolt(bateraiMvSekarang) + " sekarang = " + String(bateraiPersenDari(bateraiMvSekarang)) +
         "% (rata-rata " + bateraiVolt(bateraiMv) + ")";
}

// Untuk layar Info alat.
String bateraiTeks() {
  if (!upsAda) return "Tanpa UPS";
  String v = bateraiVolt(bateraiMv);
  String s = listrikAda ? String("Listrik ada, baterai ") + bateraiPersenTeks() + " (dicas)"
                        : String("LISTRIK PADAM, baterai ") + bateraiPersenTeks();
  return s + " - " + v + (bateraiLemah ? " LEMAH" : "");
}
