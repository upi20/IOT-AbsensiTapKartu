// Pembaca kartu RFID RC522 di SPI kedua (HSPI), terpisah dari layar.
// UID 4 byte dikirim sebagai angka 10 digit (byte dibalik, desimal), contoh "0218893066".
// UID 7/10 byte dikirim sebagai hex huruf besar tanpa pemisah.
#pragma once
#include <SPI.h>
#include <MFRC522v2.h>
#include <MFRC522DriverSPI.h>
#include <MFRC522DriverPinSimple.h>

SPIClass rfidSpi(HSPI);
MFRC522DriverPinSimple rfidPinSs(RFID_SS);
// 1 MHz: RC522 klon di alat ini tidak terbaca di 4 MHz lewat kabel jumper
MFRC522DriverSPI rfidDriver{rfidPinSs, rfidSpi, SPISettings(1000000, MSBFIRST, SPI_MODE0)};
MFRC522 rfid{rfidDriver};

bool rfidAda = false;       // pembaca terdeteksi?
byte rfidVersi = 0;         // isi register versi (0x91/0x92 = RC522 asli, 0x88/0xB2/0x12/0x82 = klon)

String uidTerakhir = "";    // untuk mengabaikan kartu yang sama
uint32_t waktuUidTerakhir = 0;

// Cek register versi. Modul klon punya versi berbeda-beda (modul di alat ini: 0x82),
// jadi semua nilai diterima kecuali:
//   0x00 / 0xFF : tidak menjawab (MISO lepas)
//   0xEE        : "gema" perintah baca versi sendiri -> MISO tersambung ke MOSI (kabel bersentuhan)
bool rfidCekVersi() {
  // Baca register langsung: PCD_GetVersion() mengubah versi klon yang tidak dikenal menjadi 0xFF.
  rfidVersi = rfidDriver.PCD_ReadRegister(MFRC522::PCD_Register::VersionReg);
  byte ulang = rfidDriver.PCD_ReadRegister(MFRC522::PCD_Register::VersionReg);
  return rfidVersi == ulang && rfidVersi != 0x00 && rfidVersi != 0xFF && rfidVersi != 0xEE;
}

void rfidMulai() {
  pinMode(RFID_RST, OUTPUT);           // reset keras: LOW sebentar lalu HIGH
  digitalWrite(RFID_RST, LOW);
  delay(5);
  digitalWrite(RFID_RST, HIGH);
  delay(50);
  // SS sengaja -1: pin SDA dikendalikan driver sendiri. Kalau ikut didaftarkan ke SPI, RC522 selalu terbaca 0xFF.
  rfidSpi.begin(RFID_SCK, RFID_MISO, RFID_MOSI, -1);
  // RC522 klon kadang belum siap sesaat setelah menyala: coba beberapa kali (maks. ±1 detik)
  for (int coba = 1; coba <= 10; coba++) {
    rfid.PCD_Init();
    rfidAda = rfidCekVersi();
    if (rfidAda) {
      Serial.printf("RFID: RC522 terdeteksi (versi 0x%02X, percobaan ke-%d)\n", rfidVersi, coba);
      return;
    }
    delay(100);
  }
  Serial.printf("RFID: tidak terdeteksi (versi 0x%02X). Cek kabel.\n", rfidVersi);
}

// True kalau antena RC522 menyala (bit 0 dan 1 register TxControlReg).
// Kalau RC522 sempat ter-reset diam-diam (misalnya tegangan turun sesaat), versinya tetap
// terbaca normal tetapi antenanya mati, sehingga kartu tidak terbaca sama sekali.
bool rfidAntenaNyala() {
  return (rfidDriver.PCD_ReadRegister(MFRC522::PCD_Register::TxControlReg) & 0x03) == 0x03;
}

// Cek kesehatan pembaca tiap 5 detik. Kalau hilang, coba nyalakan ulang.
// Mengembalikan true kalau status berubah (supaya layar diperbarui).
bool rfidUrus() {
  static uint32_t cekTerakhir = 0;
  if (millis() - cekTerakhir < 5000) return false;
  cekTerakhir = millis();

  bool tadi = rfidAda;
  rfidAda = rfidCekVersi();
  if (!rfidAda) {
    rfid.PCD_Init();
    rfidAda = rfidCekVersi();
  } else if (!rfidAntenaNyala()) {
    Serial.println("RFID: antena mati (RC522 sempat ter-reset), dinyalakan ulang");
    rfid.PCD_Init();
  }
  if (rfidAda != tadi) {
    Serial.println(rfidAda ? "RFID: pembaca tersambung lagi" : "RFID: pembaca hilang! Cek kabel.");
    return true;
  }
  return false;
}

// Baca kartu. True kalau ada kartu BARU. `uid` = nomor kartu (10 digit / hex),
// `uidHex` = byte UID apa adanya dalam hex (contoh "0A0B0C0D", untuk raw.uid_hex).
bool rfidBaca(String& uid, String& uidHex) {
  static uint32_t cekTerakhir = 0;
  if (!rfidAda || millis() - cekTerakhir < 80) return false;   // cukup dicek tiap 80 ms
  cekTerakhir = millis();

  if (!rfid.PICC_IsNewCardPresent() || !rfid.PICC_ReadCardSerial()) return false;

  // UID apa adanya dalam hex (UID paling panjang 10 byte)
  char hex[2 * 10 + 1];
  for (byte i = 0; i < rfid.uid.size; i++) sprintf(hex + 2 * i, "%02X", rfid.uid.uidByte[i]);
  hex[2 * rfid.uid.size] = '\0';

  char teks[2 * 10 + 1];
  if (rfid.uid.size == 4) {
    // Format 10 digit seperti pembaca RFID USB / mesin absensi umum:
    // urutan byte dibalik lalu ditulis desimal. Contoh 0A 0B 0C 0D -> 0x0D0C0B0A -> 0218893066
    uint32_t nomor = 0;
    for (int i = 3; i >= 0; i--) nomor = (nomor << 8) | rfid.uid.uidByte[i];
    snprintf(teks, sizeof(teks), "%010lu", (unsigned long)nomor);
  } else {
    // UID 7 atau 10 byte tidak punya format 10 digit baku, tetap ditulis hex
    strcpy(teks, hex);
  }
  rfid.PICC_HaltA();                                      // kartu diam sampai diangkat
  rfid.PCD_StopCrypto1();

  uid = teks;
  uidHex = hex;
  if (uid == uidTerakhir && millis() - waktuUidTerakhir < KARTU_JEDA_MS) {
    Serial.printf("Kartu %s diabaikan (baru saja di-tap)\n", teks);
    return false;
  }
  uidTerakhir = uid;
  waktuUidTerakhir = millis();
  return true;
}

// Mulai hitung jeda dari sekarang (dipanggil setelah server selesai menjawab).
void rfidMulaiJeda() { waktuUidTerakhir = millis(); }

// ---------- Cek kabel (hanya dipakai halaman "Cek kabel RFID" di menu Pengaturan) ----------

// True kalau kabel MOSI (D13) dan MISO (D35) tersambung satu sama lain.
// RC522 sengaja TIDAK dipilih (SDA HIGH), jadi RC522 diam. Kalau data yang dikirim tetap
// terbaca kembali persis, berarti kedua kabel bersentuhan.
bool rfidKorslet() {
  rfidSpi.beginTransaction(SPISettings(1000000, MSBFIRST, SPI_MODE0));
  digitalWrite(RFID_SS, HIGH);
  byte a = rfidSpi.transfer(0xA5);
  byte b = rfidSpi.transfer(0x5A);
  rfidSpi.endTransaction();
  return a == 0xA5 && b == 0x5A;
}

// Baca register versi RC522 sekali (0x00/0xFF = tidak menjawab, 0xEE = gema kabel).
byte rfidBacaVersi() { return rfidDriver.PCD_ReadRegister(MFRC522::PCD_Register::VersionReg); }
