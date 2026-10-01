// WiFi dan kirim-terima HTTP/HTTPS ke aplikasi (Base URL + API key dari pengaturan).
// HTTPS tanpa pemeriksaan sertifikat (setInsecure), sesuai spesifikasi.
//
// Supaya tap cepat:
// - Sambungan ke server dibiarkan terbuka (keep-alive) dan dipakai bersama oleh ping, tap, heartbeat,
//   pengumuman, dan antrean. Sambungan HTTPS baru butuh sekitar 1 detik, sambungan lama hanya sekejap.
// - Satu request punya SATU batas waktu untuk semuanya (sambung, TLS, tunggu jawaban, dan coba ulang).
// - Alamat IP server disimpan (DNS tidak dicari tiap kali), karena DNS saat internet putus bisa
//   makan waktu lebih dari 10 detik dan tidak bisa dibatasi.
#pragma once
#include <WiFi.h>
#include <NetworkClient.h>
#include <NetworkClientSecure.h>
#include <HTTPClient.h>

const int KODE_OFFLINE = -100;         // kode buatan: WiFi tidak tersambung
const int KODE_URL_SALAH = -101;       // kode buatan: Base URL tidak bisa dipakai

// Status server untuk titik di bilah status = hasil heartbeat/ping terakhir
enum StatusServer { SERVER_BELUM, SERVER_OK, SERVER_GAGAL };
StatusServer statusServer = SERVER_BELUM;

bool wifiJeda = false;          // true = jangan menyambung ulang otomatis (saat memindai / menyetel WiFi)
bool heartbeatSegera = false;   // true = kirim heartbeat secepatnya (misalnya WiFi baru tersambung)
bool pengumumanSegera = false;  // true = ambil daftar pengumuman secepatnya (lihat pengumuman.h)
uint32_t wifiCobaTerakhir = 0;
String wifiSsidDipakai, wifiPassDipakai;   // nama & password yang terakhir dipakai menyambung

// Satu sambungan ke satu server. Ada dua: untuk API aplikasi, dan untuk foto (supaya unduh foto
// tidak menutup sambungan API).
struct Sambungan {
  NetworkClient biasa;          // untuk http://
  NetworkClientSecure aman;     // untuk https://
  HTTPClient http;
  String asal;                  // server yang sedang tersambung, contoh "https://app.contoh.com"
  String dnsNama;               // nama server yang alamat IP-nya tersimpan
  IPAddress dnsIp;              // alamat IP-nya
  uint32_t dnsWaktu = 0;        // kapan terakhir dicari (DNS)
};
Sambungan sambunganApi, sambunganFoto;

// ---------- WiFi ----------

bool wifiTerhubung() { return WiFi.status() == WL_CONNECTED; }

// Kekuatan sinyal 0..4 garis
int wifiBarDari(int rssi) {
  if (rssi > -55) return 4;
  if (rssi > -65) return 3;
  if (rssi > -75) return 2;
  return 1;
}
int wifiBar() { return wifiTerhubung() ? wifiBarDari(WiFi.RSSI()) : 0; }

// Mulai menyambung ke WiFi tersimpan (tidak menunggu).
void wifiSambung() {
  wifiCobaTerakhir = millis();
  if (atur.ssid.length() == 0) return;
  Serial.printf("WiFi: menyambung ke \"%s\"...\n", atur.ssid.c_str());
  wifiSsidDipakai = atur.ssid;
  wifiPassDipakai = atur.pass;
  WiFi.disconnect();
  WiFi.begin(atur.ssid.c_str(), atur.pass.c_str());
}

// Mulai menyambung ke WiFi tertentu di latar, tanpa mengubah pengaturan tersimpan.
// Dipakai panduan penyetelan: WiFi sudah menyambung sambil pengguna mengisi Base URL dan API key.
void wifiSambungLatar(const String& ssid, const String& pass) {
  if (ssid.length() == 0) return;
  bool sama = wifiSsidDipakai == ssid && wifiPassDipakai == pass;
  if (sama && wifiTerhubung()) return;                   // sudah tersambung ke WiFi ini
  wifiCobaTerakhir = millis();
  Serial.printf("WiFi: menyambung di latar ke \"%s\"...\n", ssid.c_str());
  wifiSsidDipakai = ssid;
  wifiPassDipakai = pass;
  WiFi.disconnect();
  WiFi.begin(ssid.c_str(), pass.c_str());
}

void wifiMulai() {
  WiFi.persistent(false);               // data WiFi hanya disimpan oleh program ini (pengaturan.h)
  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false);                 // lebih cepat merespons
  WiFi.setAutoReconnect(true);
  for (Sambungan* s : {&sambunganApi, &sambunganFoto}) {
    s->aman.setInsecure();              // HTTPS tanpa cek sertifikat
    s->http.setUserAgent(String("AbsensiTap/") + VERSI_FIRMWARE);
  }
  sambunganApi.http.setReuse(true);     // sambungan dipakai ulang supaya tap lebih cepat
  // Foto memakai HTTP/1.0 (tanpa "chunked", jadi isi bisa dibaca langsung), tapi tetap minta keep-alive.
  sambunganFoto.http.useHTTP10(true);
  sambunganFoto.http.setReuse(true);
  wifiSambung();
}

void sambunganPutus(Sambungan& s) {
  s.http.end();
  s.biasa.stop();
  s.aman.stop();
}

// Putuskan semua sambungan HTTP lama (dipanggil kalau Base URL diganti atau WiFi terputus).
void httpPutus() {
  sambunganPutus(sambunganApi);
  sambunganPutus(sambunganFoto);
}

// Dipanggil terus. Mengurus sambung ulang, NTP, dan heartbeat setelah tersambung.
void wifiUrus() {
  static bool tadiTerhubung = false;
  bool sekarang = wifiTerhubung();

  if (sekarang && !tadiTerhubung) {
    Serial.printf("WiFi: tersambung, IP %s, sinyal %d dBm\n", WiFi.localIP().toString().c_str(), WiFi.RSSI());
    heartbeatSegera = true;                                 // spesifikasi: heartbeat segera setelah tersambung
    pengumumanSegera = true;                                // spesifikasi: pengumuman diambil setelah tersambung
    ntpMulai();
  }
  if (!sekarang && tadiTerhubung) {
    Serial.println("WiFi: terputus, menyambung ulang...");
    statusServer = SERVER_BELUM;
    httpPutus();
    wifiCobaTerakhir = millis();
  }
  // Cadangan kalau sambung ulang otomatis tidak berhasil: coba lagi tiap 30 detik
  if (!sekarang && !wifiJeda && millis() - wifiCobaTerakhir > 30000) wifiSambung();
  tadiTerhubung = sekarang;
}

// ---------- HTTP ----------

// Teks singkat untuk kode galat (negatif = gagal tersambung).
String teksGalat(int kode) {
  if (kode == KODE_OFFLINE) return "WiFi tidak tersambung";
  if (kode == KODE_URL_SALAH) return "Base URL tidak valid";
  if (kode == HTTPC_ERROR_CONNECTION_REFUSED) return "Server tidak bisa dihubungi";
  if (kode == HTTPC_ERROR_READ_TIMEOUT) return "Server terlalu lama menjawab";
  if (kode == HTTPC_ERROR_CONNECTION_LOST) return "Sambungan terputus";
  if (kode == 429) return "Server sibuk (HTTP 429)";
  if (kode >= 300 && kode < 400) return "Alamat dialihkan (HTTP " + String(kode) + ")";
  if (kode < 0) return "Galat jaringan (" + String(kode) + ")";
  return "HTTP " + String(kode);
}

// Gagal karena jaringan, server rusak, atau server sibuk (tidak ada jawaban, timeout, HTTP 5xx, HTTP 429)?
// Tap yang gagal seperti ini disimpan / tetap di antrean dan dicoba lagi nanti.
bool gagalJaringan(int kode) { return kode <= 0 || kode >= 500 || kode == 429; }
bool kode2xx(int kode) { return kode >= 200 && kode < 300; }

// Sisa waktu (ms) sampai `tenggat` (nilai millis() batas akhir). 0 kalau sudah lewat.
uint32_t sisaSampai(uint32_t tenggat) {
  int32_t sisa = (int32_t)(tenggat - millis());
  return sisa > 0 ? sisa : 0;
}

// Asal (skema + host + port) sebuah URL, contoh "https://app.contoh.com:8443".
String urlAsal(const String& url) {
  int awal = url.indexOf("://");
  if (awal < 0) return "";
  int akhir = url.indexOf('/', awal + 3);
  return akhir < 0 ? url : url.substring(0, akhir);
}

// Alamat IP server `nama`. Hasil DNS disimpan dan baru dicari ulang setelah DNS_ULANG_MS.
// bolehDns = false (tap langsung): hanya memakai alamat tersimpan, supaya tap tidak pernah menunggu DNS.
bool alamatServer(Sambungan& s, const String& nama, IPAddress& ip, bool bolehDns) {
  if (ip.fromString(nama)) return true;                    // nama sudah berupa alamat IP
  bool ada = nama == s.dnsNama;
  bool perluCari = !ada || millis() - s.dnsWaktu > DNS_ULANG_MS;
  if (perluCari && bolehDns) {
    s.dnsWaktu = millis();
    IPAddress baru;
    if (WiFi.hostByName(nama.c_str(), baru)) {
      s.dnsNama = nama;
      s.dnsIp = baru;
      ada = true;
    } else {
      Serial.printf("DNS: \"%s\" tidak ditemukan\n", nama.c_str());
    }
  }
  if (ada) ip = s.dnsIp;
  else if (!bolehDns) Serial.printf("DNS: alamat \"%s\" belum diketahui (tap tidak menunggu DNS)\n", nama.c_str());
  return ada;
}

// Buka sambungan baru ke server di `url` (TCP, ditambah TLS untuk https), paling lama sampai `tenggat`.
// Sambungan dibuka sendiri (bukan oleh HTTPClient) supaya waktu yang sudah terpakai bisa dihitung.
bool sambungKe(Sambungan& s, const String& url, uint32_t tenggat, bool bolehDns) {
  // Pisahkan nama server dan port, contoh "https://app.contoh.com:8443/api" -> "app.contoh.com", 8443
  String nama = urlAsal(url);
  bool aman = nama.startsWith("https://");
  nama.remove(0, nama.indexOf("://") + 3);
  int at = nama.lastIndexOf('@');                          // buang "user:password@" kalau ada
  if (at >= 0) nama.remove(0, at + 1);
  int port = aman ? 443 : 80;
  int titikDua = nama.indexOf(':');
  if (titikDua >= 0) {
    port = nama.substring(titikDua + 1).toInt();
    nama.remove(titikDua);
  }
  IPAddress ip;
  if (nama.length() == 0 || port <= 0 || port > 65535 || !alamatServer(s, nama, ip, bolehDns)) return false;

  uint32_t sisa = sisaSampai(tenggat);
  if (sisa == 0) return false;
  if (!aman) return s.biasa.connect(ip, port, sisa);
  s.aman.setConnectionTimeout(sisa);
  uint32_t detik = sisa / 1000;                            // batas TLS hanya bisa diatur per detik
  s.aman.setHandshakeTimeout(detik > 0 ? detik : 1);
  return s.aman.connect(ip, port, nama.c_str(), nullptr, nullptr, nullptr);   // nama dikirim untuk TLS (SNI)
}

// Kirim request ke `url` lewat sambungan `s`. Semua tahap memakai satu batas waktu `batasMs`.
// Header API (X-API-Key dst.) hanya dikirim ke server aplikasi sendiri (Base URL), tidak ke server lain.
// Mengembalikan kode HTTP (negatif = gagal tersambung). Kalau kode > 0, isi balasan dibaca dari `s.http`,
// lalu pemanggil WAJIB memanggil s.http.end() (atau sambunganPutus(s) kalau isinya tidak dibaca habis).
int httpKirim(Sambungan& s, bool post, const String& url, const String& isi, uint32_t batasMs, bool bolehDns) {
  uint32_t tenggat = millis() + batasMs;
  bool aman = url.startsWith("https://");
  NetworkClient& klien = aman ? (NetworkClient&)s.aman : s.biasa;
  String asal = urlAsal(url);
  if (asal != s.asal) {                                    // server lain: sambungan lama tidak bisa dipakai
    sambunganPutus(s);
    s.asal = asal;
  }
  bool kunci = asal == urlAsal(atur.url);

  int kode = 0;
  for (int coba = 1; coba <= 2; coba++) {
    // Sambungan lama (keep-alive) bisa sudah ditutup server. Kalau gagal, coba sekali lagi dengan sambungan baru.
    bool pakaiSambunganLama = klien.connected();
    if (!pakaiSambunganLama && !sambungKe(s, url, tenggat, bolehDns)) {
      klien.stop();
      kode = HTTPC_ERROR_CONNECTION_REFUSED;               // "Server tidak bisa dihubungi"
      break;
    }
    if (!s.http.begin(klien, url)) return KODE_URL_SALAH;
    s.http.setTimeout(sisaSampai(tenggat));                // sisa waktu untuk menunggu jawaban
    if (kunci) {
      s.http.addHeader("X-API-Key", atur.key);
      s.http.addHeader("X-Device-ID", idAlat);
      s.http.addHeader("X-Spec-Version", VERSI_SPEK);
      s.http.addHeader("Accept", "application/json");
    }
    if (post) {
      s.http.addHeader("Content-Type", "application/json");
      kode = s.http.POST(isi);
    } else {
      kode = s.http.GET();
    }
    if (kode > 0) return kode;                             // pemanggil membaca isi balasan

    s.http.end();
    klien.stop();
    bool gagalKirim = kode == HTTPC_ERROR_CONNECTION_REFUSED || kode == HTTPC_ERROR_SEND_HEADER_FAILED ||
                      kode == HTTPC_ERROR_SEND_PAYLOAD_FAILED || kode == HTTPC_ERROR_CONNECTION_LOST;
    if (!(pakaiSambunganLama && gagalKirim)) break;
    if (sisaSampai(tenggat) < HTTP_ULANG_MIN_MS) break;    // waktu tidak cukup untuk coba ulang
  }
  Serial.printf("HTTP gagal: %s\n", HTTPClient::errorToString(kode).c_str());
  return kode;
}

// Kirim request ke {Base URL}{jalur}. Mengembalikan kode HTTP (negatif = gagal tersambung).
// Isi balasan ditaruh di `balasan`. Program berhenti di sini paling lama sekitar `batasMs`.
int apiKirim(bool post, const char* jalur, const String& isi, String& balasan, uint32_t batasMs, bool bolehDns = true) {
  balasan = "";
  if (!wifiTerhubung()) return KODE_OFFLINE;
  if (!urlValid(atur.url)) return KODE_URL_SALAH;
  bipTunggu();                                            // buzzer jangan sampai menyala terus saat menunggu

  uint32_t mulai = millis();
  int kode = httpKirim(sambunganApi, post, atur.url + jalur, isi, batasMs, bolehDns);
  if (kode > 0) {
    balasan = sambunganApi.http.getString();
    sambunganApi.http.end();                              // sambungan tetap terbuka untuk request berikutnya
  }
  Serial.printf("HTTP %s %s -> %d (%lu ms)\n", post ? "POST" : "GET", jalur, kode, (unsigned long)(millis() - mulai));
  return kode;
}
