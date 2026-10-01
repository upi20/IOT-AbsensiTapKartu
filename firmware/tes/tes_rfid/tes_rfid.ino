// Tes RFID RC522: baca register versi dengan SPI manual, mencoba semua susunan kabel
// di pin D33, D14, D13, D32 (MISO tetap D35 karena D35 hanya bisa input).
// Versi yang umum: 0x91 / 0x92 (asli), 0x82 / 0x88 / 0x12 / 0xB2 (klon). 0x00 atau 0xFF = tidak menjawab.
// 0xEE = kabel MOSI dan MISO tersambung (cek dengan tes_miso), bukan RC522 yang menjawab.

const int PIN_MISO = 35;
const int KELUARAN[] = {33, 14, 13, 32};
const char* NAMA[] = {"D33", "D14", "D13", "D32"};

int sda_, sck_, mosi_, rst_;

uint8_t bacaVersi() {
  digitalWrite(sda_, LOW); delayMicroseconds(5);
  uint8_t alamat = 0x80 | ((0x37 << 1) & 0x7E);   // baca register VersionReg
  for (int i = 7; i >= 0; i--) {
    digitalWrite(mosi_, (alamat >> i) & 1); delayMicroseconds(5);
    digitalWrite(sck_, HIGH); delayMicroseconds(5);
    digitalWrite(sck_, LOW);
  }
  uint8_t v = 0;
  digitalWrite(mosi_, LOW);
  for (int i = 0; i < 8; i++) {
    digitalWrite(sck_, HIGH); delayMicroseconds(5);
    v = (v << 1) | digitalRead(PIN_MISO);
    digitalWrite(sck_, LOW); delayMicroseconds(5);
  }
  digitalWrite(sda_, HIGH);
  return v;
}

bool coba(int a, int b, int c, int d) {
  sda_ = KELUARAN[a]; sck_ = KELUARAN[b]; mosi_ = KELUARAN[c]; rst_ = KELUARAN[d];
  for (int p : KELUARAN) { pinMode(p, OUTPUT); digitalWrite(p, LOW); }
  digitalWrite(sda_, HIGH);
  digitalWrite(rst_, LOW); delay(5); digitalWrite(rst_, HIGH); delay(60);
  uint8_t v1 = bacaVersi(), v2 = bacaVersi();
  bool ok = (v1 == v2) && v1 != 0x00 && v1 != 0xFF && v1 != 0xEE;
  Serial.printf("SDA=%s SCK=%s MOSI=%s RST=%s -> versi 0x%02X 0x%02X %s\n",
                NAMA[a], NAMA[b], NAMA[c], NAMA[d], v1, v2, ok ? "<== RC522 MENJAWAB" : "");
  return ok;
}

void setup() {
  Serial.begin(115200);
  delay(500);
  pinMode(PIN_MISO, INPUT);
  Serial.println("\n##### TES RFID RC522 #####");
  Serial.println("Susunan yang direncanakan: SDA=D33 SCK=D14 MOSI=D13 RST=D32 (MISO=D35)");
  bool ketemu = coba(0, 1, 2, 3);
  if (ketemu) { Serial.println("Kabel sesuai rencana. RC522 berfungsi."); return; }
  Serial.println("Mencoba susunan lain...");
  int idx[4] = {0, 1, 2, 3};
  // semua permutasi 4 pin keluaran
  for (int a = 0; a < 4; a++) for (int b = 0; b < 4; b++) for (int c = 0; c < 4; c++) for (int d = 0; d < 4; d++) {
    if (a == b || a == c || a == d || b == c || b == d || c == d) continue;
    if (a == 0 && b == 1 && c == 2 && d == 3) continue;
    if (coba(a, b, c, d)) ketemu = true;
  }
  (void)idx;
  Serial.println(ketemu ? "Ada susunan yang menjawab (lihat tanda <==). Kabel tertukar." : "Tidak ada susunan yang menjawab.");
  Serial.println("Selesai.");
}

void loop() {}
