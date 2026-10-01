// Pengaturan yang diisi lewat layar dan disimpan di memori alat (NVS / Preferences).
// Semua disimpan di namespace "absensi", termasuk kalibrasi layar sentuh (kunci "kal").
// Reset pabrik cukup menghapus seluruh namespace ini.
#pragma once
#include <Preferences.h>
#include <esp_mac.h>

Preferences prefs;

struct Pengaturan {
  String ssid;         // nama WiFi
  String pass;         // password WiFi (boleh kosong untuk WiFi terbuka)
  String url;          // Base URL, contoh http://192.168.1.10/api/absensi (tanpa "/" di akhir)
  String key;          // API key (header X-API-Key)
  String pin;          // PIN menu Pengaturan
  String judul;        // judul layar utama
  int offsetMenit;     // zona waktu tampilan, contoh +07:00 = 420
  int redupDetik;      // layar meredup setelah diam sekian detik (0 = tidak pernah)
  int redupPersen;     // kecerahan layar saat redup (0-100 %)
  String restartAt;    // jam restart harian "HH:MM" (jam alat), "" = tidak restart otomatis
};
Pengaturan atur;

String idAlat;         // contoh "ABS-0001", dari ID_ALAT di config.h (atau dari MAC kalau kosong)
String idAlatHex;      // 3 byte terakhir MAC, contoh "79438C" (untuk tap_id, selalu unik per alat)

// WiFi, URL, dan API key sudah diisi? Kalau belum, alat membuka panduan penyetelan.
bool aturLengkap() { return atur.ssid.length() > 0 && atur.url.length() > 0 && atur.key.length() > 0; }

// PIN harus PIN_MIN_ANGKA sampai PIN_MAKS_ANGKA angka (4-8).
bool pinValid(const String& p) {
  if ((int)p.length() < PIN_MIN_ANGKA || (int)p.length() > PIN_MAKS_ANGKA) return false;
  for (char c : p) if (c < '0' || c > '9') return false;
  return true;
}

// Hitung jumlah huruf (bukan byte) supaya huruf beraksen dihitung satu.
int jumlahHuruf(const String& s) {
  int n = 0;
  for (char c : s) if ((c & 0xC0) != 0x80) n++;
  return n;
}

bool judulValid(const String& j) { return j.length() > 0 && jumlahHuruf(j) <= JUDUL_UTAMA_MAKS; }

bool redupDetikValid(long d) { return d == 0 || (d >= 10 && d <= 3600); }

// "" (mati) atau "HH:MM" 24 jam.
bool restartValid(const String& r) {
  if (r.length() == 0) return true;
  if (r.length() != 5 || r[2] != ':') return false;
  for (int i : {0, 1, 3, 4}) if (r[i] < '0' || r[i] > '9') return false;
  return r.substring(0, 2).toInt() <= 23 && r.substring(3, 5).toInt() <= 59;
}
bool redupPersenValid(long p) { return p >= 0 && p <= 100; }

// Rapikan Base URL: buang spasi dan "/" di akhir.
String urlRapikan(String u) {
  u.trim();
  while (u.endsWith("/")) u.remove(u.length() - 1);
  return u;
}

bool urlValid(const String& u) {
  return (u.startsWith("http://") && u.length() > 7) || (u.startsWith("https://") && u.length() > 8);
}

void aturMuat() {
  atur.ssid        = prefs.getString("ssid", "");
  atur.pass        = prefs.getString("pass", "");
  atur.url         = prefs.getString("url", "");
  atur.key         = prefs.getString("key", "");
  atur.pin         = prefs.getString("pin", PIN_BAWAAN);
  atur.judul       = prefs.getString("judul", JUDUL_BAWAAN);
  atur.offsetMenit = prefs.getInt("offset", OFFSET_BAWAAN);
  atur.redupDetik  = prefs.getInt("redup", REDUP_DETIK_BAWAAN);
  atur.redupPersen = prefs.getInt("redupP", REDUP_PERSEN_BAWAAN);
  atur.restartAt   = prefs.getString("restartAt", RESTART_BAWAAN);
  if (!pinValid(atur.pin)) atur.pin = PIN_BAWAAN;
  if (!judulValid(atur.judul)) atur.judul = JUDUL_BAWAAN;
  if (!redupDetikValid(atur.redupDetik)) atur.redupDetik = REDUP_DETIK_BAWAAN;
  if (!redupPersenValid(atur.redupPersen)) atur.redupPersen = REDUP_PERSEN_BAWAAN;
  if (!restartValid(atur.restartAt)) atur.restartAt = RESTART_BAWAAN;
}

// Simpan WiFi dan server (dipanggil setelah penyetelan).
void aturSimpanKoneksi() {
  prefs.putString("ssid", atur.ssid);
  prefs.putString("pass", atur.pass);
  prefs.putString("url", atur.url);
  prefs.putString("key", atur.key);
  Serial.printf("Pengaturan disimpan: WiFi \"%s\", URL %s\n", atur.ssid.c_str(), atur.url.c_str());
}

void aturSimpanPin(const String& pin) {
  atur.pin = pin;
  prefs.putString("pin", pin);
  Serial.println("PIN menu Pengaturan diganti");
}

void aturSimpanJudul(const String& judul) {
  atur.judul = judul;
  prefs.putString("judul", judul);
  Serial.printf("Judul diganti: \"%s\"\n", judul.c_str());
}

void aturSimpanOffset(int menit) {
  atur.offsetMenit = menit;
  prefs.putInt("offset", menit);
  Serial.printf("Zona waktu diganti: %+d menit\n", menit);
}

void aturSimpanRedup(int detik, int persen) {
  atur.redupDetik = detik;
  atur.redupPersen = persen;
  prefs.putInt("redup", detik);
  prefs.putInt("redupP", persen);
  Serial.printf("Lampu layar: redup setelah %d detik ke %d%%\n", detik, persen);
}

void aturSimpanRestart(const String& r) {
  atur.restartAt = r;
  prefs.putString("restartAt", r);
  Serial.printf("Restart harian: %s\n", r.length() ? r.c_str() : "mati");
}

// ID alat: diambil dari ID_ALAT di config.h (diatur manual sebelum upload lewat USB).
// Kalau ID_ALAT kosong, dibuat dari MAC: "ABS-" + 3 byte terakhir MAC WiFi.
// ID yang dipakai juga disimpan di namespace "identitas" (tidak ikut terhapus saat reset pabrik).
// Firmware untuk update jarak jauh (./upload.sh -b, flag OTA_BUILD) TIDAK memakai ID_ALAT, tapi ID
// yang tersimpan itu, jadi satu file .bin bisa dikirim ke semua alat tanpa membuat ID-nya sama.
void idAlatBuat() {
  uint8_t mac[6];
  esp_read_mac(mac, ESP_MAC_WIFI_STA);
  char hex[7];
  snprintf(hex, sizeof(hex), "%02X%02X%02X", mac[3], mac[4], mac[5]);
  idAlatHex = hex;

  Preferences identitas;
  identitas.begin("identitas", false);
  String tersimpan = identitas.getString("id", "");
#ifdef OTA_BUILD
  idAlat = tersimpan.length() > 0 ? tersimpan : "ABS-" + idAlatHex;
#else
  idAlat = strlen(ID_ALAT) > 0 ? String(ID_ALAT) : "ABS-" + idAlatHex;
#endif
  if (idAlat != tersimpan) identitas.putString("id", idAlat);
  identitas.end();
}

void aturMulai() {
  prefs.begin("absensi", false);
  idAlatBuat();
  aturMuat();
  Serial.printf("ID alat: %s\n", idAlat.c_str());
  if (!aturLengkap()) Serial.println("Pengaturan belum lengkap: panduan penyetelan akan dibuka.");
}

// ---------- Tekan EN 3 kali = tawaran reset pabrik ----------
// Setiap menyala, penghitung di memori dinaikkan. Kalau alat sudah menyala lebih dari 5 detik,
// penghitung dikosongkan lagi. Jadi penghitung hanya mencapai 3 kalau EN ditekan beruntun.

bool bootHitungSelesai = false;

// Dipanggil paling awal di setup(). True kalau EN baru saja ditekan 3 kali.
bool bootHitungNaik() {
  int n = prefs.getUChar("bootN", 0) + 1;
  Serial.printf("Menyala beruntun ke-%d\n", n);
  if (n >= BOOT_RESET_KALI) {
    prefs.putUChar("bootN", 0);
    bootHitungSelesai = true;
    return true;
  }
  prefs.putUChar("bootN", n);
  return false;
}

// Dipanggil terus. Setelah 5 detik menyala, penghitung dikosongkan (sekali saja).
void bootHitungUrus() {
  if (bootHitungSelesai || millis() < BOOT_AMAN_MS) return;
  prefs.putUChar("bootN", 0);
  bootHitungSelesai = true;
}
