// Update firmware jarak jauh (OTA) dari server, lihat doc/spesifikasi-api.md (config.firmware_update).
//
// Alur:
//  1. Server mengirim config.firmware_update {version, url, size, md5} di /ping atau /heartbeat.
//  2. Saat alat diam OTA_DIAM_MS (tidak ada kartu / sentuhan) dan server tersambung, file .bin diunduh
//     ke slot OTA yang tidak dipakai (partisi min_spiffs punya 2 slot), dicek MD5-nya, lalu alat restart.
//  3. Firmware baru berstatus "percobaan". Kalau restart sebelum dinyatakan sehat (OTA_SEHAT_S),
//     bootloader kembali ke firmware lama. Firmware lama lalu melihat penyebab restart-nya:
//     - listrik / tombol EN (bukan salah firmware): update dicoba lagi, paling banyak OTA_ULANG_MAKS kali;
//     - crash, watchdog, brownout, dll.: versi itu dicatat di otaGagal, tidak diunduh lagi, dan
//       dilaporkan ke server (raw.ota_failed).
//     otaGagal hanya berlaku untuk firmware yang mencatatnya (upload USB versi lain = dihapus).
//  4. Unduhan gagal (jaringan putus, MD5 salah, dll.) dicoba lagi setelah OTA_ULANG_MS.
//
// Catatan keamanan: HTTPS tanpa cek sertifikat (sama seperti request lain), jadi siapa pun yang bisa
// menyadap jaringan alat bisa mengirim firmware palsu. Aktifkan update hanya untuk alat yang memang
// akan diperbarui (diatur per alat di server).
#pragma once
#include <Update.h>
#include <esp_ota_ops.h>
#include <ArduinoJson.h>

// Ada di ui.h (di-include setelah file ini)
enum JenisOta { OTA_PROSES, OTA_SELESAI, OTA_GAGAL };
void uiOta(const String& judul, const String& ket, JenisOta jenis);
void uiOtaPersen(int persen);

struct TawaranOta {
  String versi, url, md5;
  uint32_t ukuran = 0;
};
TawaranOta otaTawaran;           // dari config.firmware_update (versi kosong = tidak ada update)
String otaGagal;                 // versi yang gagal dipasang (alat kembali ke firmware lama): tidak dicoba lagi
String otaStatus;                // keterangan untuk Info alat
uint32_t otaCobaBerikut = 0;     // millis() paling cepat mencoba lagi setelah gagal (0 = boleh sekarang)
bool otaPerluSehat = false;      // firmware ini baru dipasang lewat OTA dan belum dinyatakan sehat

// Matikan pengesahan otomatis oleh core Arduino: firmware baru disahkan sendiri di otaUrusSehat().
extern "C" bool verifyRollbackLater() { return true; }

// Dipanggil sekali di setup(), setelah pengaturan dimuat.
void otaMulai() {
  otaGagal = prefs.getString("otaGagal", "");
  if (otaGagal.length() > 0 && prefs.getString("otaGagalDi", "") != VERSI_FIRMWARE) {
    otaGagal = "";                                         // dicatat oleh firmware lain: tidak berlaku lagi
    prefs.remove("otaGagal");
  }
  String coba = prefs.getString("otaCoba", "");           // versi yang terakhir dipasang lewat OTA

  const esp_partition_t* jalan = esp_ota_get_running_partition();
  esp_ota_img_states_t keadaan;
  otaPerluSehat = esp_ota_get_state_partition(jalan, &keadaan) == ESP_OK && keadaan == ESP_OTA_IMG_PENDING_VERIFY;

  if (coba.length() > 0 && coba != VERSI_FIRMWARE) {       // pasang versi baru, tapi yang jalan versi lama
    prefs.remove("otaCoba");
    esp_reset_reason_t alasan = esp_reset_reason();        // penyebab restart saat firmware baru berjalan
    bool terputus = alasan == ESP_RST_POWERON || alasan == ESP_RST_EXT;   // listrik / EN, bukan salah firmware
    int ulang = prefs.getUChar("otaUlang", 0) + 1;
    if (terputus && ulang < OTA_ULANG_MAKS) {
      prefs.putUChar("otaUlang", ulang);
      Serial.printf("OTA: firmware %s terputus (%s) sebelum dinyatakan sehat, kembali ke %s. Dicoba lagi (%d/%d)\n",
                    coba.c_str(), alasanResetTeks(), VERSI_FIRMWARE, ulang + 1, OTA_ULANG_MAKS);
      otaStatus = "v" + coba + " terputus, dicoba lagi";
    } else {
      prefs.remove("otaUlang");
      Serial.printf("OTA: firmware %s gagal (%s), kembali ke %s\n", coba.c_str(), alasanResetTeks(), VERSI_FIRMWARE);
      otaGagal = coba;
      prefs.putString("otaGagal", coba);
      prefs.putString("otaGagalDi", VERSI_FIRMWARE);
    }
  } else if (coba.length() > 0) {
    Serial.printf("OTA: berjalan dengan firmware baru %s%s\n", VERSI_FIRMWARE,
                  otaPerluSehat ? ", menunggu dinyatakan sehat" : "");
    if (!otaPerluSehat) prefs.remove("otaCoba");          // tidak berstatus percobaan: langsung dianggap sehat
  }
  if (otaGagal.length() > 0) otaStatus = "v" + otaGagal + " gagal, kembali ke versi ini";
}

// Dipanggil terus (urusLatar). Firmware baru dinyatakan sehat setelah menyala OTA_SEHAT_S detik,
// atau lebih cepat kalau sudah 1 menit dan server tersambung. Setelah itu tidak bisa kembali ke versi lama.
void otaUrusSehat() {
  if (!otaPerluSehat) return;
  uint32_t nyala = lamaNyalaDetik();
  if (nyala < OTA_SEHAT_S && !(nyala >= 60 && statusServer == SERVER_OK)) return;
  esp_ota_mark_app_valid_cancel_rollback();
  otaPerluSehat = false;
  prefs.remove("otaCoba");
  prefs.remove("otaUlang");
  if (otaGagal.length() > 0) {                             // versi gagal sebelumnya tidak relevan lagi
    otaGagal = "";
    prefs.remove("otaGagal");
  }
  otaStatus = "";
  Serial.printf("OTA: firmware %s dinyatakan sehat\n", VERSI_FIRMWARE);
}

bool md5Valid(const String& s) {
  if (s.length() != 32) return false;
  for (char c : s) if (!isxdigit((unsigned char)c)) return false;
  return true;
}

// Baca config.firmware_update (dari terapkanBalasanUmum di api.h). Tidak ada / tidak sah = tidak ada update.
void otaTerapkanConfig(JsonVariantConst fu) {
  TawaranOta t;
  if (fu.is<JsonObjectConst>()) {
    t.versi = fu["version"] | "";
    t.url = fu["url"] | "";
    t.md5 = fu["md5"] | "";
    t.md5.toLowerCase();
    t.ukuran = fu["size"] | 0;
    const esp_partition_t* slot = esp_ota_get_next_update_partition(NULL);
    bool sah = t.versi.length() > 0 && t.versi.length() <= 31 &&
               (t.url.startsWith("http://") || t.url.startsWith("https://")) && md5Valid(t.md5) &&
               t.ukuran > 0 && slot && t.ukuran <= slot->size;
    if (!sah) {
      Serial.println(slot ? "config.firmware_update diabaikan (isi tidak lengkap / tidak sah, atau file terlalu besar)"
                          : "config.firmware_update diabaikan (skema partisi tanpa OTA, upload ulang lewat USB)");
      t = TawaranOta();
    } else if (t.versi == VERSI_FIRMWARE || t.versi == otaGagal) {
      t = TawaranOta();                                    // sudah terpasang, atau dulu gagal
    }
  }
  if (t.versi != otaTawaran.versi) {
    if (t.versi.length() > 0) {
      Serial.printf("OTA: firmware %s tersedia (%lu byte), dipasang saat alat diam\n", t.versi.c_str(),
                    (unsigned long)t.ukuran);
      otaStatus = "v" + t.versi + " menunggu alat diam";
    } else if (otaGagal.length() == 0) {
      otaStatus = "";
    }
    otaCobaBerikut = 0;
  }
  otaTawaran = t;
}

// Unduh dan pasang otaTawaran. "" = berhasil (alat tinggal restart), selain itu keterangan galat.
String otaPasang() {
  const TawaranOta t = otaTawaran;
  Sambungan& s = sambunganFoto;                            // HTTP/1.0: isi file dibaca langsung tanpa "chunked"
  int kode = httpKirim(s, false, t.url, "", HTTP_TIMEOUT_MS, true);
  if (kode != 200) {
    if (kode > 0) sambunganPutus(s);
    return "unduh gagal (" + teksGalat(kode) + ")";
  }
  // Tanpa Content-Length (sebagian proxy / tunnel): pakai ukuran dari config, MD5 tetap diperiksa.
  int panjang = s.http.getSize();
  if (panjang < 0) panjang = t.ukuran;
  if (panjang != (int)t.ukuran) {
    sambunganPutus(s);
    return "ukuran file " + String(panjang) + ", seharusnya " + String(t.ukuran);
  }
  if (!Update.begin(panjang) || !Update.setMD5(t.md5.c_str())) {
    sambunganPutus(s);
    return String("tidak bisa mulai (") + Update.errorString() + ")";
  }

  const size_t UKURAN_BUF = 4096;
  uint8_t* buf = (uint8_t*)malloc(UKURAN_BUF);
  NetworkClient* aliran = s.http.getStreamPtr();
  int masuk = 0, persenTadi = -1;
  uint32_t dataTerakhir = millis();
  while (buf && masuk < panjang && !Update.hasError()) {
    watchdogPakan();
    size_t ada = aliran->available();
    if (ada == 0) {
      if (millis() - dataTerakhir > OTA_DATA_TIMEOUT_MS) break;     // unduhan macet / putus
      delay(2);
      continue;
    }
    int n = aliran->read(buf, min(ada, min(UKURAN_BUF, (size_t)(panjang - masuk))));
    if (n <= 0 || Update.write(buf, n) != (size_t)n) break;
    masuk += n;
    dataTerakhir = millis();
    int persen = (int)((int64_t)masuk * 100 / panjang);
    if (persen != persenTadi) {
      persenTadi = persen;
      uiOtaPersen(persen);
    }
  }
  free(buf);
  sambunganPutus(s);
  if (masuk < panjang) {
    String alasan = Update.hasError() ? String("tulis gagal (") + Update.errorString() + ")" : "unduhan terputus";
    Update.abort();
    return alasan + " di " + String(masuk / 1024) + " KB";
  }
  if (!Update.end()) return String("file rusak (") + Update.errorString() + ")";   // termasuk MD5 tidak cocok
  return "";
}

// Unduh + pasang otaTawaran sekarang juga (layar update tampil). Kalau berhasil, alat restart dan fungsi ini
// tidak kembali. Kalau gagal: mengembalikan alasannya, dan percobaan otomatis berikutnya menunggu OTA_ULANG_MS.
String otaJalankan() {
  bipMati();                                               // selama update buzzerUrus() tidak jalan
  ledNyala(LED_BIRU);
  feedbackUrus();                                          // LED biru tetap selama update
  Serial.printf("OTA: mengunduh firmware %s dari %s\n", otaTawaran.versi.c_str(), otaTawaran.url.c_str());
  uiOta("Memperbarui firmware", "v" + String(VERSI_FIRMWARE) + "  ->  v" + otaTawaran.versi, OTA_PROSES);
  uint32_t mulai = millis();
  String galat = otaPasang();
  if (galat.length() == 0) {
    Serial.printf("OTA: firmware %s terpasang (%lu detik), menyalakan ulang\n", otaTawaran.versi.c_str(),
                  (unsigned long)((millis() - mulai) / 1000));
    prefs.putString("otaCoba", otaTawaran.versi);
    uiOta("Firmware terpasang", "Menyalakan ulang...", OTA_SELESAI);
    delay(1500);
    ESP.restart();
  }
  Serial.printf("OTA: gagal, %s (RAM bebas %u, blok terbesar %u). Otomatis dicoba lagi %lu menit lagi\n",
                galat.c_str(), ESP.getFreeHeap(), ESP.getMaxAllocHeap(), (unsigned long)(OTA_ULANG_MS / 60000));
  otaStatus = "v" + otaTawaran.versi + " gagal: " + galat;
  otaCobaBerikut = (millis() + OTA_ULANG_MS) | 1;
  uiOta("Update firmware gagal", galat, OTA_GAGAL);
  delay(3000);
  return galat;
}

// Dipanggil dari layar utama / screensaver. `diam` = tidak ada kartu atau sentuhan selama OTA_DIAM_MS.
// True kalau update dicoba dan gagal (layar perlu digambar ulang). Kalau berhasil, alat restart.
// Pemasangan manual tanpa menunggu: menu Pengaturan -> Update firmware (menu.h).
bool urusOta(bool diam) {
  if (otaTawaran.versi.length() == 0 || !diam || !wifiTerhubung() || statusServer != SERVER_OK) return false;
  if (otaCobaBerikut != 0 && (int32_t)(millis() - otaCobaBerikut) < 0) return false;
  otaJalankan();
  return true;
}
