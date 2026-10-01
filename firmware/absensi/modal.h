// Alat bantu untuk layar yang menunggu sentuhan (pengaturan, papan ketik, dialog).
// Selama layar seperti ini terbuka, buzzer, LED, WiFi, dan cek RFID tetap diurus lewat urusLatar().
// Kartu RFID tidak dibaca di layar pengaturan.
#pragma once

uint32_t sentuhTerakhir = 0;    // kapan layar terakhir disentuh
bool batasDiamAktif = false;    // true di menu Pengaturan: layar ditutup sendiri setelah 60 detik tidak disentuh
bool heartbeatDiMenu = false;   // true di menu Pengaturan: heartbeat tetap dikirim tiap menit, supaya server
                                // tidak menganggap alat mati. False selama "Tes koneksi" (pengaturan sementara).

// Pekerjaan latar yang harus tetap jalan di semua layar.
void urusLatar() {
  watchdogPakan();                                        // layar menu & panduan juga memanggil ini
  feedbackUrus();
  wifiUrus();
  rfidUrus();
  bootHitungUrus();
  lampuUrus();
  otaUrusSehat();                                         // firmware baru dari OTA: sahkan kalau sudah stabil
  // Paling sering sekali per menit (layar berhenti sebentar). Tidak saat WiFi sedang dipindai / disetel.
  if (heartbeatDiMenu && !wifiJeda) urusHeartbeat();
}

// Urus latar, lalu cek sentuhan baru. True sekali per tekanan.
bool sentuh(int& x, int& y) {
  urusLatar();
  if (sentuhBaru(x, y)) {
    sentuhTerakhir = millis();
    Serial.printf("Sentuh: x=%d y=%d\n", x, y);
    return true;
  }
  delay(1);
  return false;
}

// Sudah terlalu lama tidak disentuh? (hanya di menu Pengaturan)
bool terlaluLamaDiam() { return batasDiamAktif && millis() - sentuhTerakhir > MENU_TIMEOUT_MS; }

void klik() { bip(1, 25); }

// Layar "tunggu sebentar" sebelum pekerjaan yang membuat program berhenti (HTTP, dll.).
void uiTunggu(const String& judul, const String& ket) {
  uiJudul(judul);
  tulis("Mohon tunggu...", 240, 130, F_JUDUL, W_TEKS, TC_DATUM);
  tulisTengahMuat(ket, 185, F_BIASA, F_KECIL, F_KECIL, W_REDUP);
}

// Pesan dengan satu tombol OK. `warna` untuk baris pertama (misal hijau = berhasil).
void layarPesan(const String& judul, const String& baris1, const String& baris2, uint16_t warna) {
  const Tombol OK = {160, 240, 160, 64, "OK"};
  uiJudul(judul);
  tulisTengahMuat(baris1, 100, F_JUDUL, F_TEBAL, F_TEBAL9, warna);
  tulisTengahMuat(baris2, 160, F_BIASA, F_KECIL, F_KECIL, W_REDUP);
  gambarTombol(OK, W_TOMBOL);
  sentuhTerakhir = millis();
  int x, y;
  while (!terlaluLamaDiam()) {
    if (sentuh(x, y) && kena(OK, x, y)) { klik(); return; }
  }
}

// Pertanyaan Ya/Batal dengan tombol besar. Batal otomatis setelah `batasMs` (0 = tidak ada batas selain
// batas diam menu). True kalau "Ya" ditekan.
bool layarTanya(const String& judul, const String& baris1, const String& baris2, const char* teksYa,
                uint16_t warnaYa, uint32_t batasMs) {
  const Tombol YA = {14, 214, 220, 90, teksYa};
  const Tombol BATAL = {246, 214, 220, 90, "Batal"};
  uiJudul(judul);
  tulisTengahMuat(baris1, 70, F_TEBAL, F_TEBAL9, F_TEBAL9, W_TEKS);
  tulisTengahMuat(baris2, 110, F_BIASA, F_KECIL, F_KECIL, W_REDUP);
  gambarTombol(YA, warnaYa);
  gambarTombol(BATAL, W_TOMBOL);
  uint32_t mulai = millis();
  int sisaTadi = -1;
  sentuhTerakhir = millis();
  int x, y;
  while (!terlaluLamaDiam()) {
    if (batasMs > 0) {
      uint32_t lewat = millis() - mulai;
      if (lewat >= batasMs) return false;
      int sisa = (batasMs - lewat + 999) / 1000;
      if (sisa != sisaTadi) {                               // hitung mundur
        sisaTadi = sisa;
        tulisTimpa("Batal otomatis dalam " + String(sisa) + " detik", 240, 170, 2, W_REDUP, W_LATAR, TC_DATUM, 300);
      }
    }
    if (!sentuh(x, y)) continue;
    if (kena(YA, x, y)) { klik(); return true; }
    if (kena(BATAL, x, y)) { klik(); return false; }
  }
  return false;
}
