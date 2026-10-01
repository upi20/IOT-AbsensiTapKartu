// Tiga endpoint spesifikasi: GET /ping, POST /tap, POST /heartbeat (lihat doc/spesifikasi-api.md).
// GET /announcements ada di pengumuman.h.
#pragma once
#include <ArduinoJson.h>

String hbHasil = "Belum dikirim";     // hasil heartbeat terakhir (untuk layar Info alat)
uint32_t hbTerakhir = 0;              // kapan heartbeat terakhir dikirim
int kodeServerTerakhir = 0;           // kode HTTP terakhir yang mengubah statusServer
String revPengumuman = "";            // config.announcements_rev terakhir yang terlihat

// ---------- Bagian yang sama untuk /ping dan /heartbeat ----------

// Pakai server_time (jam) dan config (pin, title, dim_after, dim_level, restart_at, announcements_rev). Nilai yang tidak sesuai aturan diabaikan.
void terapkanBalasanUmum(JsonDocument& doc) {
  const char* jam = doc["server_time"];
  if (jam) jamDariTeksServer(jam);

  JsonObject cfg = doc["config"];
  if (cfg.isNull()) return;
  if (cfg["pin"].is<const char*>()) {
    String pin = cfg["pin"].as<const char*>();
    if (!pinValid(pin)) Serial.printf("config.pin diabaikan (harus %d-%d angka)\n", PIN_MIN_ANGKA, PIN_MAKS_ANGKA);
    else if (pin != atur.pin) aturSimpanPin(pin);
  }
  if (cfg["title"].is<const char*>()) {
    String judul = cfg["title"].as<const char*>();
    judul.trim();
    if (!judulValid(judul)) Serial.printf("config.title diabaikan (kosong atau lebih dari %d huruf)\n", JUDUL_UTAMA_MAKS);
    else if (judul != atur.judul) aturSimpanJudul(judul);
  }
  // Lampu layar: dua nilai terpisah, yang tidak dikirim / tidak sah memakai nilai tersimpan
  int redupDetik = atur.redupDetik, redupPersen = atur.redupPersen;
  if (cfg["dim_after"].is<long>()) {
    long d = cfg["dim_after"].as<long>();
    if (redupDetikValid(d)) redupDetik = d;
    else Serial.println("config.dim_after diabaikan (harus 0 atau 10-3600)");
  }
  if (cfg["dim_level"].is<long>()) {
    long p = cfg["dim_level"].as<long>();
    if (redupPersenValid(p)) redupPersen = p;
    else Serial.println("config.dim_level diabaikan (harus 0-100)");
  }
  if (redupDetik != atur.redupDetik || redupPersen != atur.redupPersen) {
    aturSimpanRedup(redupDetik, redupPersen);
    lampuTerapkan();
  }
  // Jam restart harian per alat: "HH:MM" atau "" (mati)
  if (cfg["restart_at"].is<const char*>()) {
    String r = cfg["restart_at"].as<const char*>();
    r.trim();
    if (!restartValid(r)) Serial.println("config.restart_at diabaikan (harus \"HH:MM\" atau kosong)");
    else if (r != atur.restartAt) aturSimpanRestart(r);
  }
  // Penanda versi pengumuman (teks bebas). Kalau berubah, daftar pengumuman diambil ulang.
  if (!cfg["announcements_rev"].isNull()) {
    String rev;
    serializeJson(cfg["announcements_rev"], rev);          // teks atau angka, cukup dibandingkan
    if (rev != revPengumuman) {
      Serial.printf("config.announcements_rev berubah: %s\n", rev.c_str());
      revPengumuman = rev;
      pengumumanSegera = true;
    }
  }
}

// Isi "message" dari balasan JSON (kosong kalau tidak ada atau bukan JSON).
String pesanDari(const String& balasan) {
  JsonDocument doc;
  if (deserializeJson(doc, balasan)) return "";
  return doc["message"] | "";
}

// Informasi jaringan yang sama untuk isi "raw".
void isiRawJaringan(JsonObject raw) {
  raw["wifi_ssid"] = atur.ssid;
  raw["rssi"] = wifiTerhubung() ? WiFi.RSSI() : 0;
  raw["ip"] = wifiTerhubung() ? WiFi.localIP().toString() : "";
  raw["uptime_s"] = millis() / 1000;
}

// ---------- GET /ping ----------

struct HasilPing {
  bool ok;
  int kode;
  String pesan;    // message dari server, atau keterangan galat
};

// terapkan = false saat "Tes koneksi" dengan pengaturan yang belum disimpan: jam, zona waktu, dan config
// (PIN, judul) dari server yang sedang dites TIDAK dipakai. Baru dipakai setelah "Simpan" (lihat penyetelan.h).
HasilPing apiPing(bool terapkan = true) {
  HasilPing h;
  String balasan;
  h.kode = apiKirim(false, "/ping", "", balasan, HTTP_TIMEOUT_MS);
  kodeServerTerakhir = h.kode;

  JsonDocument doc;
  bool json = h.kode > 0 && !deserializeJson(doc, balasan);
  h.pesan = json ? (doc["message"] | "") : "";
  h.ok = kode2xx(h.kode) && json && (doc["ok"] | false);

  if (h.ok) {
    statusServer = SERVER_OK;
    if (terapkan) terapkanBalasanUmum(doc);
    if (h.pesan.length() == 0) h.pesan = "Terhubung";
  } else {
    statusServer = h.kode == KODE_OFFLINE ? SERVER_BELUM : SERVER_GAGAL;
    String galat = kode2xx(h.kode) ? (json ? "Server menjawab ok=false" : "Balasan bukan JSON") : teksGalat(h.kode);
    h.pesan = h.pesan.length() > 0 ? galat + ": " + h.pesan : galat;
  }
  Serial.printf("Ping: %s\n", h.pesan.c_str());
  return h;
}

// ---------- POST /heartbeat ----------

bool apiHeartbeat() {
  JsonDocument isi;
  isi["device_id"] = idAlat;
  isi["firmware"] = VERSI_FIRMWARE;
  String waktu = waktuIso();
  if (waktu.length() > 0) isi["time"] = waktu;
  else isi["time"] = nullptr;
  JsonObject raw = isi["raw"].to<JsonObject>();
  isiRawJaringan(raw);
  raw["free_heap"] = ESP.getFreeHeap();
  raw["reset_reason"] = alasanResetKode();
  raw["rfid_ok"] = rfidAda;
  raw["queue"] = jumlahAntrean;
  String teks;
  serializeJson(isi, teks);

  String balasan;
  int kode = apiKirim(true, "/heartbeat", teks, balasan, LATAR_TIMEOUT_MS);
  kodeServerTerakhir = kode;
  bool ok = kode2xx(kode);
  if (ok) {
    JsonDocument doc;
    if (!deserializeJson(doc, balasan)) terapkanBalasanUmum(doc);
    statusServer = SERVER_OK;
    hbHasil = "OK";
  } else {
    statusServer = kode == KODE_OFFLINE ? SERVER_BELUM : SERVER_GAGAL;
    hbHasil = "Gagal: " + teksGalat(kode);
  }
  String jam = jamMenit();
  if (jam.length() > 0) hbHasil += " (" + jam + ")";
  return ok;
}

// Kirim heartbeat kalau sudah waktunya (tiap HEARTBEAT_INTERVAL_MS, atau segera kalau heartbeatSegera).
// Dipanggil dari layar utama (saat alat diam) dan dari menu Pengaturan (lihat urusLatar di modal.h).
// Saat server gagal, jadwalnya tetap tiap menit (heartbeat sekaligus memeriksa apakah server sudah pulih).
// True kalau request dikirim.
bool urusHeartbeat() {
  if (!wifiTerhubung()) return false;
  if (!heartbeatSegera && millis() - hbTerakhir < HEARTBEAT_INTERVAL_MS) return false;
  heartbeatSegera = false;
  hbTerakhir = millis();
  apiHeartbeat();
  return true;
}

// ---------- POST /tap ----------

enum JenisHasil { HASIL_MASUK, HASIL_PULANG, HASIL_DUPLIKAT, HASIL_TIDAK_TERDAFTAR, HASIL_DITOLAK,
                  HASIL_INFO, HASIL_TERSIMPAN, HASIL_GAGAL };

struct HasilTap {
  JenisHasil jenis;
  int kode;                    // kode HTTP (negatif = gagal tersambung)
  String rfid, nama, pesan, jam, fotoUrl;
  String info[2];
};

// Kirim satu tap. `waktu` kosong = jam alat belum tersinkron (tapped_at null).
// Hasil HASIL_TERSIMPAN berarti gagal karena jaringan / server sibuk: pemanggil yang menyimpan ke antrean.
// Tap langsung paling lama TAP_TIMEOUT_MS dan tidak pernah menunggu DNS; tap dari antrean LATAR_TIMEOUT_MS.
HasilTap apiTap(const TapAntrean& t, bool dariAntrean) {
  HasilTap h;
  h.rfid = t.rfid;

  JsonDocument isi;
  isi["device_id"] = idAlat;
  isi["tap_id"] = t.tapId;
  isi["rfid"] = t.rfid;
  if (t.waktu.length() > 0) isi["tapped_at"] = t.waktu;
  else isi["tapped_at"] = nullptr;
  isi["queued"] = dariAntrean;
  JsonObject raw = isi["raw"].to<JsonObject>();
  raw["uid_hex"] = t.uidHex;
  isiRawJaringan(raw);
  raw["firmware"] = VERSI_FIRMWARE;
  raw["server_status"] = statusServer == SERVER_OK ? "online" : statusServer == SERVER_GAGAL ? "offline" : "unknown";
  String teks;
  serializeJson(isi, teks);

  String balasan;
  h.kode = apiKirim(true, "/tap", teks, balasan, dariAntrean ? LATAR_TIMEOUT_MS : TAP_TIMEOUT_MS, dariAntrean);

  if (gagalJaringan(h.kode)) {                             // timeout, tanpa WiFi, HTTP 5xx / 429
    // Server dianggap gagal: antrean menunggu heartbeat berhasil dulu, tidak langsung mencoba lagi.
    statusServer = h.kode == KODE_OFFLINE ? SERVER_BELUM : SERVER_GAGAL;
    kodeServerTerakhir = h.kode;
    h.jenis = HASIL_TERSIMPAN;
    String pesan = pesanDari(balasan);                     // pesan server (kalau ada), contoh saat HTTP 503
    h.pesan = pesan.length() > 0 ? teksGalat(h.kode) + ": " + pesan : teksGalat(h.kode);
    return h;
  }
  if (kode2xx(h.kode)) {                                   // server menjawab: status server OK lagi
    statusServer = SERVER_OK;
    kodeServerTerakhir = h.kode;
  }
  JsonDocument doc;
  bool json = !deserializeJson(doc, balasan);
  if (!kode2xx(h.kode) || !json) {                         // HTTP 3xx / 4xx, atau balasan bukan JSON
    h.jenis = HASIL_GAGAL;
    h.pesan = json ? (doc["message"] | "") : "";
    if (h.pesan.length() > 0) return h;
    if (kode2xx(h.kode)) h.pesan = "Balasan server tidak valid";
    else if (h.kode < 400) h.pesan = "Cek Base URL (http / https)";   // 3xx: redirect tidak diikuti
    else h.pesan = "Permintaan ditolak server";
    return h;
  }

  String status = doc["status"] | "";
  h.nama  = doc["name"] | "";
  h.pesan = doc["message"] | "";
  h.jam   = doc["time"] | "";
  h.fotoUrl = doc["photo_url"] | "";
  JsonArray info = doc["info"];
  for (int i = 0; i < 2 && i < (int)info.size(); i++) h.info[i] = info[i] | "";

  if      (status == "check_in")  h.jenis = HASIL_MASUK;
  else if (status == "check_out") h.jenis = HASIL_PULANG;
  else if (status == "duplicate") h.jenis = HASIL_DUPLIKAT;
  else if (status == "unknown")   h.jenis = HASIL_TIDAK_TERDAFTAR;
  else if (status == "rejected")  h.jenis = HASIL_DITOLAK;
  else                            h.jenis = HASIL_INFO;    // status lain yang tidak dikenal
  if (h.jam.length() == 0) h.jam = jamMenit();             // server tidak mengirim jam: pakai jam alat
  Serial.printf("Tap: status=%s nama=\"%s\" pesan=\"%s\" jam=%s\n", status.c_str(), h.nama.c_str(),
                h.pesan.c_str(), h.jam.c_str());
  return h;
}

// Buat tap_id unik: 6 hex terakhir MAC + 8 hex acak, contoh "1A2B3C-5F3A9C21".
String tapIdBaru() {
  char s[32];
  snprintf(s, sizeof(s), "%s-%08lX", idAlatHex.c_str(), (unsigned long)esp_random());
  return s;
}
