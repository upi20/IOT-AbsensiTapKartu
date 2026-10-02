// Menu Pengaturan (dibuka dengan tombol gir di layar utama, dilindungi PIN).
// Menu ditutup sendiri kalau 60 detik tidak disentuh.
#pragma once

bool tesScreensaver = false;    // true = "Tes screensaver" dipilih: loop() membuka screensaver setelah menu ditutup

// ---------- Reset pabrik ----------

// Hapus semua pengaturan (WiFi, URL, API key, PIN, judul, zona waktu, kalibrasi), antrean, pengumuman,
// lalu mulai ulang. ID alat tidak ikut terhapus (ada di config.h).
void resetPabrik() {
  Serial.println("RESET PABRIK: semua pengaturan dihapus");
  uiTunggu("Reset pabrik", "Menghapus semua pengaturan...");
  prefs.clear();
  antreanHapusSemua();
  pengumumanHapus();
  WiFi.disconnect(true, true);                             // hapus juga data WiFi bawaan ESP32
  bip(1, 400);
  bipTunggu();
  delay(300);
  ESP.restart();
}

// Layar konfirmasi setelah EN ditekan 3 kali. Batal sendiri setelah 15 detik.
void tanyaResetPabrik() {
  Serial.println("EN ditekan 3 kali: tanya reset pabrik");
  bip(2, 100);
  batasDiamAktif = false;
  if (layarTanya("Reset pabrik?", "Semua pengaturan akan dihapus", "WiFi, URL, API key, PIN, judul, antrean, kalibrasi",
                 "Ya, reset", W_MERAH, RESET_TANYA_MS)) {
    resetPabrik();
  }
}

// ---------- PIN ----------

// Minta PIN menu. Setelah 5 kali salah, keypad dikunci 60 detik.
bool mintaPin() {
  String pesan = "";
  while (true) {
    String pin;
    if (!ketikPin("PIN Pengaturan", pesan, pin, true)) return false;
    if (pin == atur.pin) {
      pinSalahKali = 0;
      return true;
    }
    pinSalahKali++;
    bip(3, 80);
    Serial.printf("PIN salah (%d kali)\n", pinSalahKali);
    if (pinSalahKali >= PIN_SALAH_MAKS) {
      pinSalahKali = 0;
      pinKunciSampai = millis() + PIN_KUNCI_MS;
      pesan = "";
    } else {
      pesan = "PIN salah";
    }
  }
}

void gantiPin() {
  String pesan = "";
  while (true) {
    String pin1, pin2;
    String judul = "PIN baru (" + String(PIN_MIN_ANGKA) + "-" + String(PIN_MAKS_ANGKA) + " angka)";
    if (!ketikPin(judul, pesan, pin1, false)) return;
    if (!ketikPin("Ulangi PIN baru", "", pin2, false)) return;
    if (pin1 == pin2) {
      aturSimpanPin(pin1);
      layarPesan("Ganti PIN", "PIN sudah diganti", "Ingat PIN baru ini", W_HIJAU);
      return;
    }
    bip(3, 80);
    pesan = "PIN tidak sama, ulangi";
  }
}

// ---------- Info alat ----------

const char* const LABEL_INFO[] = {"ID alat", "Firmware", "WiFi", "IP", "Sinyal", "Base URL", "Antrean",
                                  "Heartbeat", "Pengumuman", "RFID", "Jam", "RAM bebas", "Layar", "Restart", "Error",
                                  "Mode absen"};
const int JUMLAH_INFO = 16;

int yInfo(int i) { return 52 + i * 17; }

void infoNilai() {
  String nilai[JUMLAH_INFO];
  nilai[0] = idAlat;
  nilai[1] = String(VERSI_FIRMWARE) + (otaStatus.length() > 0 ? " (update " + otaStatus + ")" : "");
  nilai[2] = atur.ssid.length() > 0 ? atur.ssid : "Belum diatur";
  nilai[3] = wifiTerhubung() ? WiFi.localIP().toString() : "-";
  nilai[4] = wifiTerhubung() ? String(WiFi.RSSI()) + " dBm (" + String(wifiBar()) + "/4)" : "Tidak tersambung";
  nilai[5] = atur.url;
  nilai[6] = String(jumlahAntrean) + " tap" + (antreanSiap ? "" : " (penyimpanan rusak)");
  nilai[7] = hbHasil;
  nilai[8] = String(jumlahPengumuman) + " item - " + pengumumanHasil;
  char versi[8];
  snprintf(versi, sizeof(versi), "0x%02X", rfidVersi);
  nilai[9] = rfidAda ? String("OK (versi ") + versi + ")" : "Tidak terdeteksi";
  String sumber = jamDariServer ? "server" : jamDariNtp ? "NTP" : "belum sinkron";
  nilai[10] = jamValid() ? waktuIso().substring(11, 19) + " " + offsetTeks() + " (" + sumber + ")" : "Belum sinkron";
  nilai[11] = String(ESP.getFreeHeap() / 1024) + " KB (paling sedikit " + String(ESP.getMinFreeHeap() / 1024) + " KB)";
  nilai[12] = atur.redupDetik == 0 ? String("Tidak pernah redup")
            : "Redup " + String(atur.redupPersen) + "% setelah " + String(atur.redupDetik) + " detik";
  nilai[13] = (atur.restartAt.length() ? "Tiap hari " + atur.restartAt : String("Tidak otomatis")) +
              " (terakhir: " + alasanResetTeks() + ")";
  String galat = galatSekarang();
  nilai[14] = galat.length() > 0 ? galatTeks(galat) : String("Tidak ada");
  nilai[15] = !modePilih() ? String("Otomatis (/tap)")
            : "Pilih Datang/Pulang - " + String(pilihanMode == "check_in" ? "DATANG" : pilihanMode == "check_out" ? "PULANG" : "belum dipilih");

  tft.setTextFont(2);
  for (int i = 0; i < JUMLAH_INFO; i++) {
    bool buruk = (i == 9 && !rfidAda) || (i == 7 && hbHasil.startsWith("Gagal")) ||
                 (i == 8 && pengumumanHasil.startsWith("Gagal")) || (i == 14 && galat.length() > 0) ||
                 (i == 1 && otaStatus.indexOf("gagal") >= 0);
    tulisTimpa(potong(nilai[i], 340, 2), 120, yInfo(i), 2, buruk ? W_MERAH : W_TEKS, W_LATAR, ML_DATUM, 350);
  }
}

void layarInfo() {
  const Tombol KEMBALI = {372, 2, 104, 40, "Kembali"};
  uiJudul("Info alat");
  gambarTombol(KEMBALI, W_TOMBOL, true, W_PANEL);
  for (int i = 0; i < JUMLAH_INFO; i++) tulisTimpa(LABEL_INFO[i], 14, yInfo(i), 2, W_REDUP, W_LATAR, ML_DATUM, 0);
  infoNilai();
  uint32_t perbarui = millis();
  sentuhTerakhir = millis();
  while (!terlaluLamaDiam()) {
    if (millis() - perbarui > 1000) {                      // nilai diperbarui tiap detik
      perbarui = millis();
      infoNilai();
    }
    int x, y;
    if (sentuh(x, y) && kena(KEMBALI, x, y)) { klik(); return; }
  }
}

// ---------- Cek kabel RFID ----------

// Pemeriksaan hanya berjalan selama halaman ini terbuka (tidak ada proses latar / log tersimpan).
void layarCekRfid() {
  const Tombol KEMBALI = {372, 2, 104, 40, "Kembali"};
  uiJudul("Cek kabel RFID");
  gambarTombol(KEMBALI, W_TOMBOL, true, W_PANEL);
  const char* const LABEL[] = {"MOSI - MISO", "RC522", "Kartu"};
  for (int i = 0; i < 3; i++) tulisTimpa(LABEL[i], 14, 72 + i * 40, 2, W_REDUP, W_LATAR, ML_DATUM, 0);
  tulisTimpa("Diperiksa tiap 0,5 detik selama halaman ini terbuka", 240, 300, 2, W_REDUP, W_LATAR, TC_DATUM, 0);

  int statusLama = -1;                                    // 0 = OK, 1 = korslet, 2 = tidak menjawab
  uint32_t periksa = 0;
  sentuhTerakhir = millis();
  while (!terlaluLamaDiam()) {
    if (millis() - periksa > 500) {
      periksa = millis();
      bool korslet = rfidKorslet();
      byte versi = rfidBacaVersi();
      bool menjawab = !korslet && versi != 0x00 && versi != 0xFF && versi != 0xEE;
      int status = korslet || versi == 0xEE ? 1 : menjawab ? 0 : 2;

      char teksVersi[8];
      snprintf(teksVersi, sizeof(teksVersi), "0x%02X", versi);
      tulisTimpa(korslet ? "KORSLET - kabel bersentuhan" : "AMAN - terpisah", 140, 72, 2,
                 korslet ? W_MERAH : W_HIJAU, W_LATAR, ML_DATUM, 330);
      tulisTimpa(menjawab ? String("OK (versi ") + teksVersi + ")" : String("Tidak menjawab (") + teksVersi + ")",
                 140, 112, 2, menjawab ? W_HIJAU : W_MERAH, W_LATAR, ML_DATUM, 330);

      if (status != statusLama) {                          // petunjuk hanya digambar ulang kalau status berubah
        if (status == 0 && statusLama != 0) {              // baru saja pulih: siapkan RC522 lagi
          rfid.PCD_Init();
          rfidAda = rfidCekVersi();
        }
        if (status != 0) rfidAda = false;
        statusLama = status;
        tft.fillRect(0, 180, 480, 110, W_LATAR);
        if (status == 1) {
          tulisTengahMuat("Kabel MOSI dan MISO bersentuhan", 190, F_TEBAL9, F_KECIL, F_KECIL, W_MERAH);
          tulisTengahMuat("Pisahkan konektor MOSI (D13) dan MISO (D35) di RC522", 225, F_KECIL, F_KECIL, F_KECIL, W_TEKS);
          tulisTengahMuat("Pastikan tiap konektor hanya masuk ke satu pin", 250, F_KECIL, F_KECIL, F_KECIL, W_REDUP);
        } else if (status == 2) {
          tulisTengahMuat("RC522 tidak menjawab", 190, F_TEBAL9, F_KECIL, F_KECIL, W_MERAH);
          tulisTengahMuat("Cek 3.3V (D34 V), GND, SDA (D33), SCK (D14)", 225, F_KECIL, F_KECIL, F_KECIL, W_TEKS);
          tulisTengahMuat("MISO (D35), MOSI (D13), RST (D32)", 250, F_KECIL, F_KECIL, F_KECIL, W_TEKS);
        } else {
          tulisTengahMuat("Kabel RFID baik", 190, F_TEBAL9, F_KECIL, F_KECIL, W_HIJAU);
          tulisTengahMuat("Tempelkan kartu untuk tes baca", 225, F_KECIL, F_KECIL, F_KECIL, W_TEKS);
        }
      }
      if (status != 0) tulisTimpa("-", 140, 152, 2, W_REDUP, W_LATAR, ML_DATUM, 330);
      else if (statusLama == 0) {
        String nomor, uidHex;
        if (rfidBaca(nomor, uidHex)) {
          bip(1, 60);
          tulisTimpa(nomor + "  (" + uidHex + ")", 140, 152, 2, W_HIJAU, W_LATAR, ML_DATUM, 330);
          sentuhTerakhir = millis();                       // tes kartu dihitung sebagai aktivitas
        }
      }
    }
    int x, y;
    if (sentuh(x, y) && kena(KEMBALI, x, y)) { klik(); break; }
  }
  // Keluar: siapkan RC522 dari awal supaya pembacaan kartu di layar utama normal lagi
  rfid.PCD_Init();
  rfidAda = rfidCekVersi();
}

// ---------- Menu ----------

const Tombol MENU_KEMBALI = {372, 2, 104, 40, "Kembali"};
const char* const LABEL_MENU[] = {"WiFi", "Server", "Tes koneksi", "Info alat", "Ganti PIN",
                                  "Kalibrasi layar", "Tes buzzer & LED", "Tes screensaver", "Cek kabel RFID",
                                  "Mode absen", "Update firmware", "Reset pabrik"};
enum { M_WIFI, M_SERVER, M_TES, M_INFO, M_PIN, M_KALIBRASI, M_BUZZER, M_SCREENSAVER, M_RFID, M_MODE, M_UPDATE,
       M_RESET, JUMLAH_MENU };

// 12 tombol, 2 kolom x 6 baris
Tombol menuTombol(int i) { return {8 + (i % 2) * 236, 49 + (i / 2) * 45, 228, 41, LABEL_MENU[i]}; }

// ---------- Update firmware manual ----------

// Tanya server sekarang (GET /ping), lalu pasang update yang dijadwalkan untuk alat ini tanpa menunggu alat
// diam dan tanpa jeda 30 menit setelah gagal. Server hanya menawarkan versi yang dijadwalkan untuk alat ini
// (website: Alat -> Update firmware ke ..., atau Firmware -> Terapkan ke semua alat).
void layarUpdateFirmware() {
  const Tombol KEMBALI = {372, 2, 104, 40, "Kembali"};
  const Tombol PASANG = {14, 214, 220, 90, "Pasang sekarang"};
  const Tombol CEK = {246, 214, 220, 90, "Cek lagi"};
  while (true) {
    uiJudul("Update firmware");
    gambarTombol(KEMBALI, W_TOMBOL, true, W_PANEL);
    tulisTengahMuat("Versi terpasang: v" + String(VERSI_FIRMWARE), 68, F_TEBAL, F_TEBAL9, F_TEBAL9, W_TEKS);
    tulisTengahMuat("Memeriksa server...", 120, F_BIASA, F_KECIL, F_KECIL, W_REDUP);

    bool ada = false;
    String baris1, baris2;
    uint16_t warna = W_TEKS;
    if (!wifiTerhubung()) {
      String kode = kodeGalatWifi();
      baris1 = "WiFi belum tersambung";
      baris2 = kode.length() > 0 ? galatTeks(kode) : String("Tunggu WiFi tersambung, lalu Cek lagi");
      warna = W_MERAH;
    } else {
      HasilPing p = apiPing(true);                         // ikut membaca config.firmware_update terbaru
      if (!p.ok) {
        baris1 = "Server tidak bisa dihubungi";
        baris2 = p.pesan;
        warna = W_MERAH;
      } else if (otaTawaran.versi.length() > 0) {
        ada = true;
        baris1 = "Tersedia v" + otaTawaran.versi;
        baris2 = String(otaTawaran.ukuran / 1024) + " KB, pemasangan +- 15-60 detik";
        warna = W_HIJAU;
      } else if (otaGagal.length() > 0) {
        baris1 = "Tidak ada update";
        baris2 = "v" + otaGagal + " pernah gagal dipasang; naikkan versi lalu unggah ulang";
        warna = W_AMBER;
      } else {
        baris1 = "Sudah versi terbaru";
        baris2 = "Tidak ada update yang dijadwalkan untuk " + idAlat + " di website";
      }
    }
    tft.fillRect(0, 100, 480, 100, W_LATAR);
    tulisTengahMuat(baris1, 124, F_JUDUL, F_TEBAL, F_TEBAL9, warna);
    tulisTengahMuat(baris2, 170, F_KECIL, F_KECIL, F_KECIL, W_REDUP);
    if (ada) gambarTombol(PASANG, W_HIJAU);
    gambarTombol(CEK, W_TOMBOL);

    sentuhTerakhir = millis();
    bool ulang = false;
    int x, y;
    while (!terlaluLamaDiam() && !ulang) {
      if (!sentuh(x, y)) continue;
      if (kena(KEMBALI, x, y)) { klik(); return; }
      if (kena(CEK, x, y)) { klik(); ulang = true; }
      else if (ada && kena(PASANG, x, y)) {
        klik();
        if (layarTanya("Pasang v" + otaTawaran.versi + "?", "Jangan cabut listrik +- 1 menit",
                       "Alat restart sendiri setelah selesai", "Pasang", W_HIJAU, 0)) {
          otaJalankan();                                   // berhasil = restart; gagal = alasan tampil 3 detik
        }
        ulang = true;                                      // kembali ke layar ini dan cek ulang
      }
    }
    if (!ulang) return;
  }
}

// ---------- Mode absen ----------

// Pilih "Otomatis" (semua tap ke /tap) atau "Pilih Datang/Pulang" (tombol di layar utama, /check-in & /check-out).
// Kalau server mengirim config.tap_mode, pilihan di sini akan ditimpa server pada heartbeat berikutnya.
void layarModeAbsen() {
  const Tombol KEMBALI = {372, 2, 104, 40, "Kembali"};
  const Tombol OTOMATIS = {14, 60, 452, 92, "Otomatis"};
  const Tombol PILIH = {14, 164, 452, 92, "Pilih Datang / Pulang"};
  while (true) {
    uiJudul("Mode absen");
    gambarTombol(KEMBALI, W_TOMBOL, true, W_PANEL);
    for (int i = 0; i < 2; i++) {
      const Tombol& t = i == 0 ? OTOMATIS : PILIH;
      bool aktif = (atur.modeAbsen == "select") == (i == 1);
      tft.fillSmoothRoundRect(t.x, t.y, t.w, t.h, 12, aktif ? W_HIJAU : W_PANEL, W_LATAR);
      tulis(String(aktif ? "> " : "") + t.teks, t.x + 18, t.y + 30, F_TEBAL, W_TEKS, ML_DATUM);
      tulis(i == 0 ? "Server menentukan datang / pulang (1 endpoint /tap)"
                   : "Tombol DATANG & PULANG di layar utama (/check-in, /check-out)",
            t.x + 18, t.y + 64, F_KECIL, aktif ? W_TEKS : W_REDUP, ML_DATUM);
    }
    tulisTengahMuat("Bisa juga diatur per alat dari website (config.tap_mode)", 282, F_KECIL, F_KECIL, F_KECIL, W_REDUP);
    sentuhTerakhir = millis();
    int x, y;
    bool ganti = false;
    while (!terlaluLamaDiam() && !ganti) {
      if (!sentuh(x, y)) continue;
      if (kena(KEMBALI, x, y)) { klik(); return; }
      String m = kena(OTOMATIS, x, y) ? "auto" : kena(PILIH, x, y) ? "select" : "";
      if (m.length() == 0) continue;
      klik();
      if (m != atur.modeAbsen) {
        aturSimpanModeAbsen(m);
        heartbeatSegera = true;                            // server segera tahu mode baru
      }
      ganti = true;                                        // gambar ulang dengan pilihan baru
    }
    if (!ganti) return;
  }
}

void menuGambar() {
  uiJudul("Pengaturan");
  gambarTombol(MENU_KEMBALI, W_TOMBOL, true, W_PANEL);
  for (int i = 0; i < JUMLAH_MENU; i++) gambarTombol(menuTombol(i), i == M_RESET ? W_MERAH : rgb(3, 105, 161));
}

void menuPengaturan() {
  menuGambar();
  sentuhTerakhir = millis();
  while (!terlaluLamaDiam()) {
    int x, y;
    if (!sentuh(x, y)) continue;
    if (kena(MENU_KEMBALI, x, y)) { klik(); return; }
    int pilih = -1;
    for (int i = 0; i < JUMLAH_MENU; i++) if (kena(menuTombol(i), x, y)) pilih = i;
    if (pilih < 0) continue;
    klik();

    Pengaturan c = atur;                                   // salinan untuk diubah (baru disimpan setelah tes)
    switch (pilih) {
      case M_WIFI:
        if (aturWifi(c, "Pilih WiFi", true)) layarTes(c, TES_MENU);
        break;
      case M_SERVER:
        if (aturUrl(c, "Base URL (http:// atau https://)") && aturKey(c, "API key")) layarTes(c, TES_MENU);
        break;
      case M_TES:
        layarTes(c, TES_SAJA);
        break;
      case M_INFO:
        layarInfo();
        break;
      case M_PIN:
        gantiPin();
        break;
      case M_KALIBRASI:
        touchKalibrasi(true);                              // menunggu 3 titik disentuh (bisa Batal)
        sentuhTerakhir = millis();
        break;
      case M_BUZZER:
        feedbackTes();
        continue;                                          // tetap di menu, tidak perlu digambar ulang
      case M_SCREENSAVER:
        if (jumlahPengumuman > 0) {
          tesScreensaver = true;                           // menu ditutup, loop() membuka screensaver
          return;
        }
        layarPesan("Tes screensaver", "Belum ada pengumuman", "Pengumuman diambil dari server (GET /announcements)", W_AMBER);
        break;
      case M_RFID:
        layarCekRfid();
        break;
      case M_MODE:
        layarModeAbsen();
        break;
      case M_UPDATE:
        layarUpdateFirmware();
        break;
      case M_RESET:
        if (layarTanya("Reset pabrik?", "Semua pengaturan akan dihapus",
                       "WiFi, URL, API key, PIN, judul, antrean, kalibrasi", "Ya, reset", W_MERAH, 0)) {
          resetPabrik();
        }
        break;
    }
    if (terlaluLamaDiam()) return;
    menuGambar();
  }
}

// Dari tombol gir: minta PIN, lalu buka menu. Kembali ke layar utama setelah selesai.
void bukaPengaturan() {
  batasDiamAktif = true;
  heartbeatDiMenu = true;                                  // heartbeat tetap jalan selama menu terbuka
  sentuhTerakhir = millis();
  if (mintaPin()) menuPengaturan();
  batasDiamAktif = false;
  heartbeatDiMenu = false;
}
