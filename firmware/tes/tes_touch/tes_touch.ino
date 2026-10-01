// Tes touch dengan jalur SPI sendiri (tidak berbagi kabel dengan layar).
// Touch: T_CLK=D22, T_DIN=D21 (header I2C expansion), T_OUT=D19, T_CS=D15.
// Layar: lihat tft_setup.h.
// Lampu latar LCD di D2 dinyalakan penuh (sama dengan kabel firmware utama).
// Kalibrasi 3 titik disimpan di memori ESP32 (terpisah dari kalibrasi firmware utama).
// Mengulang kalibrasi: tekan EN, lalu tekan BOOT saat layar menampilkan "Tekan BOOT sekarang"
// (jangan menahan BOOT sambil menekan EN: ESP32 akan masuk mode upload).

#include <SPI.h>
#include <TFT_eSPI.h>
#include <XPT2046_Touchscreen.h>
#include <Preferences.h>

const int T_CLK = 22, T_DIN = 21, T_OUT = 19, T_CS = 15;
const int PIN_TOMBOL_BOOT = 0;
const int PIN_LAMPU_LCD = 2;      // kabel LED LCD

TFT_eSPI tft = TFT_eSPI();
SPIClass touchSpi(HSPI);
XPT2046_Touchscreen ts(T_CS);
Preferences prefs;

// Data kalibrasi: nilai mentah di dua posisi layar per sumbu, plus apakah sumbu tertukar
struct Kal { bool tukar; int16_t x1, x2, y1, y2; } kal;
const int PX1 = 30, PX2 = 450, PY1 = 30, PY2 = 290;   // posisi silang di layar 480x320

void silang(int x, int y, uint16_t warna) {
  tft.drawLine(x - 12, y, x + 12, y, warna);
  tft.drawLine(x, y - 12, x, y + 12, warna);
}

const int TEKANAN_MIN = 600;
bool ditekan() { return ts.touched() && ts.getPoint().z > TEKANAN_MIN; }

TS_Point tungguSentuh() {
  while (!ditekan()) delay(5);
  long sx = 0, sy = 0; int n = 0;
  while (ditekan() && n < 30) { TS_Point p = ts.getPoint(); sx += p.x; sy += p.y; n++; delay(10); }
  while (ditekan()) delay(5);
  delay(200);
  TS_Point p; p.x = sx / max(n, 1); p.y = sy / max(n, 1); p.z = 0;
  return p;
}

TS_Point ambilTitik(int x, int y, const char* nama) {
  tft.fillScreen(TFT_BLACK);
  tft.setTextColor(TFT_WHITE, TFT_BLACK);
  tft.drawCentreString("Kalibrasi: tekan tengah tanda silang", 240, 150, 2);
  silang(x, y, TFT_MAGENTA);
  TS_Point p = tungguSentuh();
  Serial.printf("  %s: mentah x=%d y=%d\n", nama, p.x, p.y);
  return p;
}

void kalibrasi() {
  Serial.println("Kalibrasi 3 titik...");
  TS_Point a = ambilTitik(PX1, PY1, "kiri atas");
  TS_Point b = ambilTitik(PX2, PY1, "kanan atas");
  TS_Point c = ambilTitik(PX1, PY2, "kiri bawah");
  // dari kiri atas ke kanan atas, sumbu mentah mana yang paling berubah = sumbu X layar
  kal.tukar = abs(b.y - a.y) > abs(b.x - a.x);
  kal.x1 = kal.tukar ? a.y : a.x;  kal.x2 = kal.tukar ? b.y : b.x;
  kal.y1 = kal.tukar ? a.x : a.y;  kal.y2 = kal.tukar ? c.x : c.y;
  if (abs(kal.x2 - kal.x1) < 500 || abs(kal.y2 - kal.y1) < 500) {
    Serial.println("Hasil kalibrasi tidak masuk akal, diulang. Tekan tepat di tengah tanda silang.");
    kalibrasi();
    return;
  }
  prefs.putBytes("kal", &kal, sizeof(kal));
  Serial.printf("Kalibrasi disimpan: tukar=%d x(%d..%d) y(%d..%d)\n", kal.tukar, kal.x1, kal.x2, kal.y1, kal.y2);
}

// Baca posisi dengan penyaring: abaikan awal sentuhan, ambil 5 sampel, buang kalau tidak konsisten.
bool bacaLayar(int& x, int& y) {
  static unsigned long mulaiTekan = 0;
  if (!ditekan()) { mulaiTekan = 0; return false; }
  if (mulaiTekan == 0) { mulaiTekan = millis(); return false; }
  if (millis() - mulaiTekan < 30) return false;          // bacaan awal masih goyah

  const int N = 5;
  int sx[N], sy[N];
  for (int i = 0; i < N; i++) {
    TS_Point p = ts.getPoint();
    if (p.z < TEKANAN_MIN) return false;                  // stylus mulai diangkat
    sx[i] = kal.tukar ? p.y : p.x;
    sy[i] = kal.tukar ? p.x : p.y;
    delayMicroseconds(500);
  }
  int minX = sx[0], maxX = sx[0], minY = sy[0], maxY = sy[0];
  long jx = 0, jy = 0;
  for (int i = 0; i < N; i++) {
    minX = min(minX, sx[i]); maxX = max(maxX, sx[i]);
    minY = min(minY, sy[i]); maxY = max(maxY, sy[i]);
    jx += sx[i]; jy += sy[i];
  }
  if (maxX - minX > 60 || maxY - minY > 60) return false; // sampel tidak konsisten = titik liar
  x = constrain(map(jx / N, kal.x1, kal.x2, PX1, PX2), 0, 479);
  y = constrain(map(jy / N, kal.y1, kal.y2, PY1, PY2), 0, 319);
  return true;
}

void layarGambar() {
  tft.fillScreen(TFT_BLACK);
  tft.setTextColor(TFT_WHITE, TFT_BLACK);
  tft.drawString("Sentuh layar untuk menggambar", 10, 12, 2);
  tft.fillRoundRect(375, 5, 100, 40, 8, TFT_RED);
  tft.setTextColor(TFT_WHITE, TFT_RED);
  tft.drawCentreString("Hapus", 425, 17, 2);
}

void setup() {
  Serial.begin(115200);
  delay(300);
  Serial.println("\n##### TES TOUCH #####");
  pinMode(PIN_TOMBOL_BOOT, INPUT_PULLUP);
  pinMode(PIN_LAMPU_LCD, OUTPUT);
  digitalWrite(PIN_LAMPU_LCD, HIGH);       // lampu layar menyala penuh

  tft.init();
  tft.setRotation(1);
  touchSpi.begin(T_CLK, T_OUT, T_DIN, T_CS);
  ts.begin(touchSpi);

  prefs.begin("touch", false);
  bool ada = prefs.getBytes("kal", &kal, sizeof(kal)) == sizeof(kal);
  bool valid = ada && abs(kal.x2 - kal.x1) > 500 && abs(kal.y2 - kal.y1) > 500;
  if (ada && !valid) Serial.println("Kalibrasi tersimpan tidak valid, diulang.");
  bool ulang = false;
  if (valid) {                             // beri 1.5 detik untuk meminta kalibrasi ulang
    tft.fillScreen(TFT_BLACK);
    tft.setTextColor(TFT_WHITE, TFT_BLACK);
    tft.drawCentreString("Tekan BOOT sekarang untuk kalibrasi ulang", 240, 150, 2);
    unsigned long mulai = millis();
    while (millis() - mulai < 1500 && !ulang) { ulang = digitalRead(PIN_TOMBOL_BOOT) == LOW; delay(10); }
  }
  if (!valid || ulang) kalibrasi();
  else Serial.printf("Memakai kalibrasi tersimpan: tukar=%d x(%d..%d) y(%d..%d)\n", kal.tukar, kal.x1, kal.x2, kal.y1, kal.y2);

  layarGambar();
  Serial.println("Mode gambar: titik kuning harus muncul tepat di bawah stylus.");
}

void loop() {
  static unsigned long lastRaw = 0;
  if (millis() - lastRaw > 1000) {
    lastRaw = millis();
    TS_Point r = ts.getPoint();
    Serial.printf("  (mentah) x=%4d y=%4d tekanan=%4d touched=%d\n", r.x, r.y, r.z, ts.touched());
  }
  int x, y;
  if (bacaLayar(x, y)) {
    if (x > 375 && y < 45) { layarGambar(); Serial.println("Layar dihapus"); delay(300); return; }
    tft.fillCircle(x, y, 3, TFT_YELLOW);
    static unsigned long last = 0;
    if (millis() - last > 200) { last = millis(); Serial.printf("Sentuh: x=%d y=%d\n", x, y); }
  }
}
