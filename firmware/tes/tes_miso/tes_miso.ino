// Cek apakah jalur MOSI (D13) dan MISO (D35) RFID saling tersambung (kabel bersentuhan).
// RC522 tidak dipilih (SDA/D33 HIGH), jadi RC522 diam: kalau D35 tetap ikut D13, pasti korslet kabel.
const int MOSI_ = 13, MISO_ = 35, SCK_ = 14, SS_ = 33, RST_ = 32;

void setup() {
  Serial.begin(115200);
  delay(500);
  pinMode(SS_, OUTPUT); digitalWrite(SS_, HIGH);     // RC522 tidak dipilih
  pinMode(SCK_, OUTPUT); digitalWrite(SCK_, LOW);
  pinMode(RST_, OUTPUT); digitalWrite(RST_, HIGH);
  pinMode(MOSI_, OUTPUT);
  pinMode(MISO_, INPUT);
  Serial.println("\n##### TES MOSI-MISO #####");
  int ikut = 0;
  for (int i = 0; i < 10; i++) {
    int v = i % 2;
    digitalWrite(MOSI_, v); delay(5);
    int b = digitalRead(MISO_);
    Serial.printf("D13=%d -> D35=%d\n", v, b);
    if (b == v) ikut++;
  }
  Serial.println(ikut == 10 ? "HASIL: D35 selalu ikut D13 -> kabel MOSI dan MISO BERSENTUHAN / tertukar ke pin yang sama"
                            : "HASIL: D35 tidak ikut D13 -> tidak ada korslet langsung");
}
// Pemantau langsung: goyang / cabut kabel sambil melihat tulisan KORSLET atau AMAN.
void loop() {
  int ikut = 0;
  for (int i = 0; i < 6; i++) {
    int v = i % 2;
    digitalWrite(MOSI_, v); delay(2);
    if (digitalRead(MISO_) == v) ikut++;
  }
  Serial.println(ikut == 6 ? "KORSLET  (MOSI dan MISO tersambung)" : "AMAN     (MOSI dan MISO terpisah)");
  delay(500);
}
