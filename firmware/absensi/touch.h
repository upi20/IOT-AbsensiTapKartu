// Layar sentuh XPT2046 dengan jalur sendiri (bit-bang, tanpa SPI hardware).
// Touch cukup lambat, jadi SPI hardware kedua dipakai untuk RFID.
// Kalibrasi 3 titik disimpan di memori (Preferences `prefs` dari pengaturan.h, kunci "kal").
#pragma once

// Data kalibrasi: nilai mentah di dua posisi layar per sumbu, plus apakah sumbu tertukar
struct Kal { bool tukar; int16_t x1, x2, y1, y2; } kal;
const int PX1 = 30, PX2 = 450, PY1 = 30, PY2 = 290;   // posisi tanda silang di layar 480x320

const int TEKANAN_MIN = 600;    // di bawah ini dianggap tidak ditekan
const int SEBARAN_MAKS = 60;    // 5 sampel harus berdekatan, kalau tidak = titik liar

// ---------- Komunikasi dengan chip XPT2046 ----------

// Kirim 1 perintah (8 bit), lalu baca hasil 12 bit (dibaca 16 bit, digeser 3).
int xptBaca(uint8_t perintah) {
  for (int i = 7; i >= 0; i--) {
    digitalWrite(T_DIN, (perintah >> i) & 1);
    delayMicroseconds(1);
    digitalWrite(T_CLK, HIGH);
    delayMicroseconds(1);
    digitalWrite(T_CLK, LOW);
  }
  digitalWrite(T_DIN, LOW);
  uint16_t hasil = 0;
  for (int i = 0; i < 16; i++) {
    delayMicroseconds(1);
    digitalWrite(T_CLK, HIGH);
    delayMicroseconds(1);
    hasil = (hasil << 1) | digitalRead(T_OUT);
    digitalWrite(T_CLK, LOW);
  }
  return hasil >> 3;
}

struct Mentah { int x, y, z; };

// Baca posisi dan tekanan mentah satu kali.
Mentah bacaMentah() {
  Mentah m;
  digitalWrite(T_CS, LOW);
  int z1 = xptBaca(0xB1);
  int z2 = xptBaca(0xC1);
  m.z = z1 + 4095 - z2;
  xptBaca(0xD1);            // bacaan pertama biasanya goyah, dibuang
  m.x = xptBaca(0xD1);
  m.y = xptBaca(0x91);
  xptBaca(0x90);            // perintah terakhir: chip kembali hemat daya
  digitalWrite(T_CS, HIGH);
  return m;
}

bool ditekan() { return bacaMentah().z > TEKANAN_MIN; }

void touchMulai() {
  pinMode(T_CLK, OUTPUT);
  pinMode(T_DIN, OUTPUT);
  pinMode(T_CS, OUTPUT);
  pinMode(T_OUT, INPUT);
  digitalWrite(T_CS, HIGH);
  digitalWrite(T_CLK, LOW);
}

// ---------- Posisi di layar (dengan penyaring) ----------

// Baca posisi layar: abaikan awal sentuhan, ambil 5 sampel, buang kalau tidak konsisten.
bool bacaLayar(int& x, int& y) {
  static uint32_t mulaiTekan = 0;
  if (!ditekan()) { mulaiTekan = 0; return false; }
  if (mulaiTekan == 0) { mulaiTekan = millis(); return false; }
  if (millis() - mulaiTekan < 30) return false;           // bacaan awal masih goyah

  const int N = 5;
  int sx[N], sy[N];
  for (int i = 0; i < N; i++) {
    Mentah m = bacaMentah();
    if (m.z < TEKANAN_MIN) return false;                  // stylus mulai diangkat
    sx[i] = kal.tukar ? m.y : m.x;
    sy[i] = kal.tukar ? m.x : m.y;
    delayMicroseconds(500);
  }
  int minX = sx[0], maxX = sx[0], minY = sy[0], maxY = sy[0];
  long jx = 0, jy = 0;
  for (int i = 0; i < N; i++) {
    minX = min(minX, sx[i]); maxX = max(maxX, sx[i]);
    minY = min(minY, sy[i]); maxY = max(maxY, sy[i]);
    jx += sx[i]; jy += sy[i];
  }
  if (maxX - minX > SEBARAN_MAKS || maxY - minY > SEBARAN_MAKS) return false;
  x = constrain(map(jx / N, kal.x1, kal.x2, PX1, PX2), 0, 479);
  y = constrain(map(jy / N, kal.y1, kal.y2, PY1, PY2), 0, 319);
  return true;
}

// Sentuhan baru: bernilai true SEKALI per tekanan. Tekanan berikutnya baru dihitung
// setelah layar benar-benar dilepas.
bool sentuhBaru(int& x, int& y) {
  static uint32_t cekTerakhir = 0;
  static uint32_t terakhirDitekan = 0;
  static bool sudahDilapor = false;

  if (millis() - cekTerakhir < 10) return false;          // cukup dicek tiap 10 ms
  cekTerakhir = millis();

  if (sudahDilapor) {
    if (ditekan()) terakhirDitekan = millis();
    else if (millis() - terakhirDitekan > 80) sudahDilapor = false;   // sudah dilepas
    return false;
  }
  if (bacaLayar(x, y)) {
    sudahDilapor = true;
    terakhirDitekan = millis();
    return lampuBangun();                                 // layar redup: sentuhan ini hanya menyalakan lampu
  }
  return false;
}

// ---------- Kalibrasi ----------

bool kalValid() { return abs(kal.x2 - kal.x1) > 500 && abs(kal.y2 - kal.y1) > 500; }

// Tunggu layar ditekan lalu dilepas, isi `hasil` dengan rata-rata posisi mentah.
// False kalau `batasMs` lewat tanpa sentuhan (0 = tunggu terus).
bool tungguSentuh(Mentah& hasil, uint32_t batasMs) {
  uint32_t mulai = millis();
  while (true) {
    while (!ditekan()) {
      if (batasMs > 0 && millis() - mulai > batasMs) return false;
      delay(5);
    }
    delay(30);                                            // lewati awal sentuhan
    long sx = 0, sy = 0;
    int n = 0;
    while (n < 30) {
      Mentah m = bacaMentah();
      if (m.z < TEKANAN_MIN) break;
      sx += m.x; sy += m.y; n++;
      delay(10);
    }
    uint32_t lepas = millis();                            // tunggu dilepas (minimal 150 ms)
    while (millis() - lepas < 150) { if (ditekan()) lepas = millis(); delay(5); }
    if (n >= 5) {
      hasil = {(int)(sx / n), (int)(sy / n), 0};
      return true;
    }
    mulai = millis();                                     // sentuhan terlalu singkat: hitung ulang batas waktu
  }
}

// Posisi mentah -> posisi layar dengan data kalibrasi `k` (sama seperti bacaLayar()).
void mentahKeLayar(const Mentah& m, const Kal& k, int& x, int& y) {
  x = constrain(map(k.tukar ? m.y : m.x, k.x1, k.x2, PX1, PX2), 0, 479);
  y = constrain(map(k.tukar ? m.x : m.y, k.y1, k.y2, PY1, PY2), 0, 319);
}

// Ambil satu titik kalibrasi. Kalau `bolehBatal` (dari menu): tombol Batal dibaca dengan kalibrasi `lama`,
// dan batal sendiri kalau MENU_TIMEOUT_MS tidak disentuh. False kalau dibatalkan.
bool ambilTitik(int nomor, int x, int y, bool bolehBatal, const Kal& lama, Mentah& m) {
  uiKalibrasiTitik(nomor, x, y, bolehBatal);
  if (!tungguSentuh(m, bolehBatal ? MENU_TIMEOUT_MS : 0)) return false;
  if (bolehBatal) {
    int lx, ly;
    mentahKeLayar(m, lama, lx, ly);
    if (kena(KAL_BATAL, lx, ly)) { bip(1, 25); bipTunggu(); return false; }
  }
  bip(1, 40);
  bipTunggu();
  Serial.printf("  titik %d: mentah x=%d y=%d\n", nomor, m.x, m.y);
  return true;
}

// Kalibrasi 3 titik: kiri atas, kanan atas, kiri bawah. Program menunggu sampai selesai.
// bolehBatal = true dari menu Pengaturan: bisa dibatalkan (tombol Batal, atau MENU_TIMEOUT_MS tidak disentuh),
// dan kalibrasi lama tetap dipakai. Saat pertama kali (belum ada kalibrasi) wajib diselesaikan.
// True kalau kalibrasi baru disimpan.
bool touchKalibrasi(bool bolehBatal) {
  bipTunggu();                                            // selama kalibrasi buzzer tidak diurus loop()
  Serial.println("Kalibrasi layar sentuh...");
  const Kal lama = kal;
  while (true) {
    Mentah a, b, c;
    if (!ambilTitik(1, PX1, PY1, bolehBatal, lama, a) || !ambilTitik(2, PX2, PY1, bolehBatal, lama, b) ||
        !ambilTitik(3, PX1, PY2, bolehBatal, lama, c)) {
      kal = lama;
      Serial.println("Kalibrasi dibatalkan: kalibrasi lama tetap dipakai.");
      return false;
    }
    // dari kiri atas ke kanan atas, sumbu mentah mana yang paling berubah = sumbu X layar
    kal.tukar = abs(b.y - a.y) > abs(b.x - a.x);
    kal.x1 = kal.tukar ? a.y : a.x;  kal.x2 = kal.tukar ? b.y : b.x;
    kal.y1 = kal.tukar ? a.x : a.y;  kal.y2 = kal.tukar ? c.x : c.y;
    if (kalValid()) break;
    Serial.println("Hasil kalibrasi tidak masuk akal, diulang.");
    uiKalibrasiUlang();
    bip(3, 80);
    bipTunggu();
    delay(1000);
  }
  prefs.putBytes("kal", &kal, sizeof(kal));
  Serial.printf("Kalibrasi disimpan: tukar=%d x(%d..%d) y(%d..%d)\n", kal.tukar, kal.x1, kal.x2, kal.y1, kal.y2);
  return true;
}

// Muat kalibrasi tersimpan. Kalibrasi ulang kalau belum ada, tidak valid, atau BOOT ditekan saat menyala.
void touchSiapkan() {
  bool ada = prefs.getBytes("kal", &kal, sizeof(kal)) == sizeof(kal);

  // BOOT yang ditahan saat EN dilepas membuat ESP32 masuk mode upload, jadi beri waktu 1 detik
  // setelah menyala untuk menekan BOOT.
  bool tombolBoot = false;
  if (ada && kalValid()) {
    uiKalibrasiTawaran();
    uint32_t mulai = millis();
    while (millis() - mulai < 1000 && !tombolBoot) {
      tombolBoot = digitalRead(PIN_TOMBOL_BOOT) == LOW;
      delay(10);
    }
  }
  if (!ada) Serial.println("Belum ada data kalibrasi.");
  else if (!kalValid()) Serial.println("Kalibrasi tersimpan tidak valid.");
  else if (tombolBoot) Serial.println("Tombol BOOT ditahan: kalibrasi ulang.");

  if (!ada || !kalValid() || tombolBoot) {
    if (tombolBoot) {                                     // tunggu BOOT dilepas dulu
      uiKalibrasiLepasBoot();
      while (digitalRead(PIN_TOMBOL_BOOT) == LOW) delay(10);
    }
    touchKalibrasi(false);
  } else {
    Serial.printf("Memakai kalibrasi tersimpan: tukar=%d x(%d..%d) y(%d..%d)\n", kal.tukar, kal.x1, kal.x2, kal.y1, kal.y2);
  }
}
