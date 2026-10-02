// Absensi RFID Terintegrasi: alat absensi kartu RFID dengan layar sentuh 3.5" yang terhubung ke website.
// Alat umum: bisa dihubungkan ke aplikasi apa saja yang mengikuti doc/spesifikasi-api.md.
// Kartu ditempel -> POST {Base URL}/tap -> layar menampilkan MASUK / PULANG / dst.
// Semua pengaturan (WiFi, Base URL, API key, PIN) diisi lewat layar sentuh.
//
// File:
//   config.h      pin, waktu, versi, nilai bawaan pabrik (PIN 2026)
//   tft_setup.h   pin layar untuk TFT_eSPI
//   pengaturan.h  pengaturan tersimpan di memori (NVS), ID alat, hitungan tekan EN
//   feedback.h    buzzer dan LED RGB
//   lampu.h       lampu latar layar (D2): meredup saat alat diam
//   perawatan.h   watchdog, restart harian terjadwal, alasan restart terakhir, ringkasan crash
//   modeabsen.h   mode absen: otomatis (/tap) atau pilih DATANG/PULANG (/check-in, /check-out)
//   rfid.h        pembaca kartu RC522 (dan cek kabel MOSI/MISO)
//   waktu.h       jam (server_time / NTP) dan zona waktu
//   antrean.h     antrean tap saat server tidak bisa dihubungi (LittleFS)
//   jaringan.h    WiFi dan HTTP/HTTPS (sambungan keep-alive, satu batas waktu per request)
//   galat.h       kode error singkat di layar (E10 WiFi tidak ditemukan, E21 API key salah, dst.)
//   ota.h         update firmware jarak jauh dari server, kembali ke versi lama kalau gagal
//   api.h         /ping, /tap, /heartbeat, pengaturan jarak jauh
//   ui.h          warna, alat bantu gambar, layar utama, layar hasil
//   ikon.h        ikon pengumuman (digambar dengan garis dan bentuk dasar)
//   pengumuman.h  daftar pengumuman dari GET /announcements (disimpan di LittleFS)
//   screensaver.h layar pengumuman bergantian saat alat diam
//   foto.h        foto pemilik kartu (JPEG)
//   touch.h       layar sentuh dan kalibrasinya
//   modal.h       alat bantu layar pengaturan (pesan, tanya Ya/Batal)
//   keyboard.h    papan ketik di layar
//   keypad.h      keypad PIN
//   penyetelan.h  pilih WiFi, Base URL, API key, tes koneksi, panduan pertama kali
//   menu.h        menu Pengaturan: info alat, ganti PIN, kalibrasi, tes buzzer & LED, tes screensaver,
//                 cek kabel RFID, reset pabrik
//
// Kalibrasi ulang layar sentuh: tekan EN, lalu tekan BOOT saat muncul tulisan "Tekan BOOT sekarang"
// (dalam 1 detik), atau lewat Pengaturan (gir) > "Kalibrasi layar".
// Reset pabrik: tekan EN 3 kali dalam 5 detik, lalu pilih "Ya, reset".
// Lampu layar: meredup setelah diam 60 detik (atau config.dim_after dari server). Kartu langsung membuatnya
// terang; sentuhan pertama hanya menyalakan lampu.
// Screensaver: layar utama diam (tanpa sentuhan dan kartu) selama "idle" detik dari server -> pengumuman
// tampil bergantian. Kartu tetap bisa di-tap. Sentuh layar untuk kembali.
// Tap cepat: hasil tampil begitu server menjawab (paling lama TAP_TIMEOUT_MS, lewat dari itu tap disimpan
// di antrean). Request lain (heartbeat, pengumuman, antrean) hanya dikirim saat alat diam, supaya tidak
// membuat orang yang tap menunggu.
// Buka Serial Monitor 115200 baud untuk melihat catatan program (termasuk lama tiap tap, contoh "Tap: 245 ms").

#include <SPI.h>
#include <TFT_eSPI.h>
#include "config.h"

TFT_eSPI tft = TFT_eSPI();   // layar, dipakai oleh file-file di bawah

#include "pengaturan.h"
#include "feedback.h"
#include "lampu.h"
#include "rfid.h"
#include "waktu.h"
#include "perawatan.h"
#include "modeabsen.h"
#include "antrean.h"
#include "jaringan.h"
#include "galat.h"
#include "ota.h"
#include "api.h"
#include "ui.h"
#include "ikon.h"
#include "pengumuman.h"
#include "screensaver.h"
#include "foto.h"
#include "touch.h"
#include "modal.h"
#include "keyboard.h"
#include "keypad.h"
#include "penyetelan.h"
#include "menu.h"

enum Layar { LAYAR_BOOT, LAYAR_UTAMA, LAYAR_HASIL, LAYAR_SCREENSAVER };
Layar layar = LAYAR_BOOT;
uint32_t waktuLayar = 0;          // kapan layar sekarang dibuka (di layar utama: kapan terakhir dipakai)

uint32_t antreanBerikut = 0;      // antrean boleh dikirim lagi mulai waktu ini
uint32_t aktifTerakhir = 0;       // kapan terakhir ada kartu atau sentuhan (request latar menunggu alat diam)

// ---------- Pindah layar ----------

// LED saat menunggu: napas pelan kalau semua normal, berkedip kalau ada masalah.
void ledSiaga() {
  if (modeLed == LED_TES) return;                         // tes LED sedang berjalan
  if (!wifiTerhubung() || statusServer == SERVER_GAGAL) ledKedip(LED_ORANYE);
  else if (!rfidAda) ledKedip(LED_MERAH);
  else ledNapas(LED_SIAGA);
}

void bukaUtama() {
  layar = LAYAR_UTAMA;
  waktuLayar = millis();                                  // hitung diam untuk screensaver mulai dari sini
  uiUtama();
  ledSiaga();
}

void bukaScreensaver() {
  Serial.printf("Screensaver: mulai (%d pengumuman)\n", jumlahPengumuman);
  layar = LAYAR_SCREENSAVER;
  uiScreensaver();
}

// ---------- Kartu di-tap ----------

void prosesKartu(const String& rfid, const String& uidHex) {
  uint32_t mulai = millis();
  lampuBangun();                                          // layar redup: langsung terang, kartu tetap diproses
  bip(1, 60);                                             // langsung: tanda kartu sudah terbaca
  ledNyala(LED_BIRU);
  uiMengirim(rfid);
  // Mode pilih tapi DATANG/PULANG belum dipilih: tap tidak dikirim, minta petugas memilih dulu.
  if (modePilih() && pilihanMode.length() == 0) {
    Serial.printf("Kartu terbaca: %s, tapi mode DATANG/PULANG belum dipilih (tidak dikirim)\n", rfid.c_str());
    HasilTap h;
    h.jenis = HASIL_PILIH_MODE;
    h.kode = 0;
    h.rfid = rfid;
    rfidMulaiJeda();
    uiHasil(h);
    bip(2, 200);
    ledNyala(LED_KUNING);
    layar = LAYAR_HASIL;
    waktuLayar = millis();
    aktifTerakhir = millis();
    return;
  }
  // tap_id, waktu, dan mode dibuat sekarang, dan ikut tersimpan kalau tap masuk antrean
  TapAntrean t = {tapIdBaru(), rfid, uidHex, waktuIso(), modeTap()};
  Serial.printf("Kartu terbaca: %s (UID %s), tap_id %s%s\n", rfid.c_str(), uidHex.c_str(), t.tapId.c_str(),
                t.mode == "check_in" ? ", mode DATANG" : t.mode == "check_out" ? ", mode PULANG" : "");

  // Program menunggu di sini paling lama TAP_TIMEOUT_MS. Gagal / terlalu lama = tap disimpan di antrean
  // dan dikirim nanti dengan tap_id yang sama (server tidak mencatat dua kali).
  HasilTap h = apiTap(t, false);
  if (h.jenis == HASIL_TERSIMPAN) {
    if (antreanSiap) antreanTambah(t);
    else h.jenis = HASIL_GAGAL;                           // penyimpanan rusak: tidak bisa disimpan
  }
  rfidMulaiJeda();

  uiHasil(h);
  switch (h.jenis) {
    case HASIL_MASUK:           bip(1, 150); ledNyala(LED_HIJAU);  break;
    case HASIL_PULANG:          bip(1, 150); ledNyala(LED_BIRU);   break;
    case HASIL_DUPLIKAT:        bip(2, 90);  ledNyala(LED_KUNING); break;
    case HASIL_TIDAK_TERDAFTAR:
    case HASIL_DITOLAK:         bip(3, 90);  ledNyala(LED_MERAH);  break;
    case HASIL_INFO:            bip(1, 150); ledNyala(LED_PUTIH);  break;
    case HASIL_TERSIMPAN:       bip(2, 200); ledNyala(LED_ORANYE); break;
    default:                    bip(1, 700); ledNyala(LED_MERAH);  break;   // HASIL_GAGAL
  }

  Serial.printf("Tap: %lu ms\n", (unsigned long)(millis() - mulai));   // dari kartu terbaca sampai hasil tampil

  if (h.fotoUrl.length() > 0) {
    bipTunggu();                                          // bunyi selesai dulu, unduh foto membuat program berhenti
    if (!fotoTampilkan(h.fotoUrl, FOTO_X, FOTO_Y, latarHasil)) uiHasilKotakIkon();
  }
  layar = LAYAR_HASIL;
  waktuLayar = millis();                                  // hitung mundur dimulai setelah foto tampil
  aktifTerakhir = millis();
}

// ---------- Pekerjaan latar (di layar utama dan screensaver) ----------
// Setiap request membuat layar berhenti sebentar (paling lama LATAR_TIMEOUT_MS), jadi paling banyak
// satu request per putaran loop, dan hanya saat alat diam.

// Kirim satu tap dari antrean. 2xx, 3xx, 4xx = selesai, dihapus dari antrean.
// Galat jaringan, timeout, 5xx, atau 429 (server sibuk) = tetap di antrean, dicoba lagi nanti.
void urusAntrean() {
  if (jumlahAntrean == 0 || !wifiTerhubung() || statusServer != SERVER_OK) return;
  if ((int32_t)(millis() - antreanBerikut) < 0) return;

  TapAntrean t;
  if (!antreanPertama(t)) {                               // baris rusak: dibuang
    Serial.println("Antrean: baris rusak dibuang");
    antreanBuangPertama();
    antreanBerikut = millis() + ANTREAN_JEDA_MS;
    return;
  }
  Serial.printf("Antrean: mengirim tap %s (%s)\n", t.rfid.c_str(), t.waktu.c_str());
  HasilTap h = apiTap(t, true);
  if (!gagalJaringan(h.kode)) {
    antreanBuangPertama();
    antreanBerikut = millis() + ANTREAN_JEDA_MS;
    if (kode2xx(h.kode)) Serial.printf("Antrean: terkirim (HTTP %d), sisa %d\n", h.kode, jumlahAntrean);
    else Serial.printf("Antrean: tap %s DIBUANG, ditolak server (%s), sisa %d\n", t.tapId.c_str(),
                       teksGalat(h.kode).c_str(), jumlahAntrean);
  } else {
    antreanBerikut = millis() + ANTREAN_ULANG_MS;         // server belum bisa: berhenti dulu
    Serial.printf("Antrean: gagal (%s), dicoba lagi %lu detik lagi\n", teksGalat(h.kode).c_str(),
                  (unsigned long)(ANTREAN_ULANG_MS / 1000));
  }
}

// Paling banyak satu request per putaran: heartbeat dulu, lalu pengumuman, lalu antrean.
// Hanya kalau tidak ada kartu dan sentuhan selama DIAM_LATAR_MS, jadi antrean tidak dikirim
// selama orang masih antre tap, dan kartu tidak pernah menunggu request latar yang baru dimulai.
void urusServer() {
  if (millis() - aktifTerakhir < DIAM_LATAR_MS) return;
  if (urusHeartbeat()) return;
  if (urusPengumuman()) return;
  urusAntrean();
}

// ---------- Urutan menyala (boot) ----------

enum LangkahBoot { BOOT_WIFI, BOOT_SERVER, BOOT_JAM, BOOT_SELESAI };
LangkahBoot langkahBoot = BOOT_WIFI;
uint32_t waktuLangkah = 0;

void bootSelesai() {
  langkahBoot = BOOT_SELESAI;
  waktuLangkah = millis();
  Serial.println("Siap menerima kartu.");
}

void urusBoot() {
  switch (langkahBoot) {
    case BOOT_WIFI:
      if (wifiTerhubung()) {
        uiLangkah(3, LANGKAH_OK, WiFi.localIP().toString());
        uiLangkah(4, LANGKAH_PROSES, "Memeriksa...");
        langkahBoot = BOOT_SERVER;
      } else if (millis() - waktuLangkah > WIFI_BOOT_TIMEOUT_MS) {
        Serial.println("WiFi: belum tersambung, lanjut dulu (dicoba terus di belakang)");
        String kode = kodeGalatWifi();
        uiLangkah(3, LANGKAH_GAGAL, kode.length() > 0 ? galatTeks(kode) : String("Belum tersambung"));
        uiLangkah(4, LANGKAH_LEWAT, "Dilewati");
        uiLangkah(5, LANGKAH_LEWAT, "Dilewati");
        bootSelesai();
      } else {
        static uint32_t animasi = 0;                      // "Menyambung." ".." "..."
        static int titik = 0;
        if (millis() - animasi > 400) {
          animasi = millis();
          titik = (titik + 1) % 4;
          uiLangkah(3, LANGKAH_PROSES, String("Menyambung") + String("...").substring(0, titik));
        }
      }
      break;

    case BOOT_SERVER: {
      HasilPing p = apiPing();
      uiLangkah(4, p.ok ? LANGKAH_OK : LANGKAH_GAGAL, p.pesan);
      uiLangkah(5, LANGKAH_PROSES, "Sinkron...");
      langkahBoot = BOOT_JAM;
      waktuLangkah = millis();
      break;
    }

    case BOOT_JAM:
      // Jam dari server_time (langsung) atau NTP (tunggu maks. 8 detik)
      if (jamValid()) {
        uiLangkah(5, LANGKAH_OK, jamMenit() + " " + offsetTeks() + (jamDariServer ? " (server)" : " (NTP)"));
        bootSelesai();
      } else if (millis() - waktuLangkah > 8000) {
        Serial.println("Jam: belum sinkron, dicoba terus di belakang");
        uiLangkah(5, LANGKAH_GAGAL, "E31 Belum sinkron");
        bootSelesai();
      }
      break;

    case BOOT_SELESAI:
      if (millis() - waktuLangkah > 1500) bukaUtama();    // beri waktu membaca daftar
      break;
  }
}

// ---------- setup & loop ----------

void setup() {
  Serial.begin(115200);
  delay(300);
  Serial.printf("\n##### ABSENSI RFID TERINTEGRASI v%s #####\n", VERSI_FIRMWARE);
  Serial.printf("Restart terakhir: %s\n", alasanResetTeks());
  watchdogMulai();
  crashBaca();                                            // ringkasan crash sebelumnya (kalau ada)
  aturMulai();                                            // pengaturan tersimpan + ID alat
  pilihanMuat();                                          // pilihan DATANG/PULANG terakhir (mode pilih)
  otaMulai();                                             // firmware baru dari OTA? / dulu gagal?
  bool tanyaReset = bootHitungNaik();                     // EN ditekan 3 kali?
  pinMode(PIN_TOMBOL_BOOT, INPUT_PULLUP);
  feedbackMulai();
  lampuMulai();                                           // lampu layar terang (D2)
  zonaTerapkan();

  tft.init();
  tft.setRotation(1);                                     // landscape 480 x 320
  tft.fillScreen(TFT_BLACK);
  Serial.println("Layar: OK");

  touchMulai();
  touchSiapkan();                                         // kalibrasi dulu kalau perlu

  rfidMulai();
  antreanMulai();                                         // memasang LittleFS (antrean + pengumuman)
  if (tanyaReset) tanyaResetPabrik();                     // setelah LittleFS terpasang, supaya ikut terhapus
  pengumumanMuat();                                       // daftar terakhir, supaya tetap tampil walau server mati
  wifiMulai();
  if (!aturLengkap()) panduanPenyetelan();                // alat baru / setelah reset pabrik

  uiBoot();
  uiLangkah(0, LANGKAH_OK, "480 x 320");
  uiLangkah(1, LANGKAH_OK, "Terkalibrasi");
  char versi[24];
  snprintf(versi, sizeof(versi), "RC522 (0x%02X)", rfidVersi);
  if (rfidAda) uiLangkah(2, LANGKAH_OK, versi);
  else         uiLangkah(2, LANGKAH_GAGAL, "E30 Tidak terdeteksi");
  uiLangkah(3, LANGKAH_PROSES, "Menyambung");
  waktuLangkah = millis();
}

void loop() {
  urusLatar();                                            // buzzer, LED, WiFi, cek RFID

  if (layar == LAYAR_BOOT) {
    urusBoot();
    return;
  }
  if (layar == LAYAR_UTAMA || layar == LAYAR_SCREENSAVER) ledSiaga();

  // Kartu bisa di-tap di layar utama, saat screensaver, atau langsung saat hasil orang sebelumnya masih tampil.
  // Setelah hasil tampil, alat kembali ke layar utama (bukan ke screensaver).
  String rfidNomor, uidHex;
  if (rfidBaca(rfidNomor, uidHex)) {
    prosesKartu(rfidNomor, uidHex);
    return;
  }

  int x = 0, y = 0;
  bool disentuh = sentuhBaru(x, y);
  if (disentuh) {
    Serial.printf("Sentuh: x=%d y=%d\n", x, y);
    aktifTerakhir = millis();
  }

  if (layar == LAYAR_UTAMA) {
    if (disentuh) waktuLayar = millis();                  // layar dipakai: hitung diam mulai lagi
    // Mode diganti server (config.tap_mode), atau pilihan dikosongkan karena tanggal berganti
    if (modeAbsenBerubah || pilihanCekTanggal()) {
      modeAbsenBerubah = false;
      bukaUtama();
      return;
    }
    // Mode pilih: tombol DATANG / PULANG (sekali pilih, berlaku untuk tap berikutnya)
    if (disentuh && modePilih() && (kena(TOMBOL_DATANG, x, y) || kena(TOMBOL_PULANG, x, y))) {
      String pilih = kena(TOMBOL_DATANG, x, y) ? "check_in" : "check_out";
      klik();
      if (pilih != pilihanMode) {
        pilihanSimpan(pilih);
        heartbeatSegera = true;                           // server segera tahu pilihan baru
      }
      uiTombolMode();
      uiInfo(true);
      return;
    }
    uiUtamaUrus();
    if (disentuh && kena(TOMBOL_GIR, x, y)) {
      klik();
      bukaPengaturan();                                   // PIN, lalu menu (menunggu sampai ditutup)
      if (tesScreensaver) {                               // "Tes screensaver" dipilih di menu
        tesScreensaver = false;
        bukaScreensaver();
      } else {
        bukaUtama();
      }
      return;
    }
    if (jumlahPengumuman > 0 && millis() - waktuLayar >= diamPengumuman * 1000UL) {
      bukaScreensaver();
      return;
    }
    urusRestartHarian(millis() - aktifTerakhir >= RESTART_DIAM_MS);
    if (urusOta(millis() - aktifTerakhir >= OTA_DIAM_MS)) {   // update gagal: gambar ulang layar utama
      bukaUtama();
      return;
    }
    urusServer();
  } else if (layar == LAYAR_SCREENSAVER) {
    // Sentuhan apa pun hanya menutup screensaver (tidak menekan tombol di layar utama)
    if (disentuh || !ssUrus()) {
      Serial.println("Screensaver: selesai");
      bukaUtama();
      return;
    }
    urusRestartHarian(millis() - aktifTerakhir >= RESTART_DIAM_MS);
    if (urusOta(millis() - aktifTerakhir >= OTA_DIAM_MS)) {
      bukaUtama();
      return;
    }
    urusServer();
  } else {                                                // LAYAR_HASIL
    uint32_t lewat = millis() - waktuLayar;
    uiHasilSisa(1.0f - (float)lewat / HASIL_TAMPIL_MS);
    if (disentuh || lewat >= HASIL_TAMPIL_MS) bukaUtama();
  }
}
