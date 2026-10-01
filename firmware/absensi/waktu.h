// Jam alat. Sumber utama: "server_time" dari /ping dan /heartbeat.
// Cadangan: NTP (pool.ntp.org). Zona tampilan ikut offset server_time (disimpan di memori).
#pragma once
#include <time.h>
#include <sys/time.h>
#include <esp_sntp.h>

bool jamDariServer = false;           // true setelah jam diambil dari server_time
volatile bool jamDariNtp = false;     // true setelah jam disinkron lewat NTP

bool jamValid() { return time(nullptr) > 1700000000; }   // sudah lewat tahun 2023 = jam sudah diatur

// Terapkan zona waktu dari atur.offsetMenit. Format POSIX membalik tanda: +07:00 ditulis "UTC-7:00".
String zonaPosix() {
  int m = atur.offsetMenit;
  char tz[20];
  snprintf(tz, sizeof(tz), "UTC%c%d:%02d", m >= 0 ? '-' : '+', abs(m) / 60, abs(m) % 60);
  return tz;
}

void zonaTerapkan() {
  setenv("TZ", zonaPosix().c_str(), 1);
  tzset();
}

// Offset sebagai teks ISO, contoh "+07:00".
String offsetTeks() {
  int m = atur.offsetMenit;
  char s[16];
  snprintf(s, sizeof(s), "%c%02d:%02d", m >= 0 ? '+' : '-', abs(m) / 60, abs(m) % 60);
  return s;
}

// Jumlah hari sejak 1 Januari 1970 (rumus kalender biasa).
long hariSejak1970(int y, int m, int d) {
  y -= m <= 2;
  long era = y / 400;
  long thnEra = y - era * 400;
  long hariThn = (153L * (m + (m > 2 ? -3 : 9)) + 2) / 5 + d - 1;
  long hariEra = thnEra * 365 + thnEra / 4 - thnEra / 100 + hariThn;
  return era * 146097 + hariEra - 719468;
}

// Baca waktu ISO 8601, contoh "2026-09-30T07:45:12+07:00", "...12.345+0700", atau "...12Z".
// Hasil: `utc` (detik unix), `offset` (menit), `adaOffset` = false kalau diakhiri Z.
bool isoBaca(const char* s, time_t& utc, int& offset, bool& adaOffset) {
  int Y, M, D, h, m, d;
  if (!s || strlen(s) < 19) return false;
  if (sscanf(s, "%4d-%2d-%2d%*c%2d:%2d:%2d", &Y, &M, &D, &h, &m, &d) != 6) return false;
  if (Y < 2020 || M < 1 || M > 12 || D < 1 || D > 31 || h > 23 || m > 59 || d > 60) return false;

  const char* p = s + 19;
  if (*p == '.') {                                         // pecahan detik diabaikan
    p++;
    while (isdigit((unsigned char)*p)) p++;
  }
  if (*p == 'Z' || *p == 'z') {
    offset = 0;
    adaOffset = false;
  } else if (*p == '+' || *p == '-') {
    int tanda = *p == '-' ? -1 : 1;
    p++;
    if (!isdigit((unsigned char)p[0]) || !isdigit((unsigned char)p[1])) return false;
    int oj = (p[0] - '0') * 10 + (p[1] - '0');
    p += 2;
    if (*p == ':') p++;
    int om = 0;
    if (isdigit((unsigned char)p[0]) && isdigit((unsigned char)p[1])) om = (p[0] - '0') * 10 + (p[1] - '0');
    offset = tanda * (oj * 60 + om);
    if (offset < -12 * 60 || offset > 14 * 60) return false;
    adaOffset = true;
  } else {
    return false;                                          // tanpa zona waktu: tidak dipakai
  }
  utc = (time_t)hariSejak1970(Y, M, D) * 86400 + h * 3600 + m * 60 + d - (time_t)offset * 60;
  return true;
}

// Setel jam dari server_time. Offset-nya dipakai sebagai zona tampilan (kecuali "Z").
void jamDariTeksServer(const char* s) {
  time_t utc;
  int offset;
  bool adaOffset;
  if (!isoBaca(s, utc, offset, adaOffset)) {
    Serial.printf("Jam: server_time tidak dikenali: \"%s\"\n", s ? s : "");
    return;
  }
  timeval tv = {utc, 0};
  settimeofday(&tv, nullptr);
  if (!jamDariServer) Serial.printf("Jam: disetel dari server (%s)\n", s);
  jamDariServer = true;
  if (adaOffset && offset != atur.offsetMenit) {
    aturSimpanOffset(offset);
    zonaTerapkan();
  }
}

void saatNtpSinkron(struct timeval*) { jamDariNtp = true; }

// Mulai NTP (sekali, setelah WiFi tersambung). Dipakai kalau server tidak mengirim server_time.
void ntpMulai() {
  static bool sudah = false;
  if (sudah) return;
  sudah = true;
  sntp_set_time_sync_notification_cb(saatNtpSinkron);
  configTzTime(zonaPosix().c_str(), NTP_SERVER);
}

// Waktu sekarang sebagai ISO 8601 dengan offset. Kosong kalau jam belum pernah disetel.
String waktuIso() {
  if (!jamValid()) return "";
  time_t t = time(nullptr);
  struct tm w;
  localtime_r(&t, &w);
  char s[32];
  strftime(s, sizeof(s), "%Y-%m-%dT%H:%M:%S", &w);
  return String(s) + offsetTeks();
}

// Jam sekarang "HH:MM" (kosong kalau jam belum disetel).
String jamMenit() {
  if (!jamValid()) return "";
  time_t t = time(nullptr);
  struct tm w;
  localtime_r(&t, &w);
  char s[8];
  snprintf(s, sizeof(s), "%02d:%02d", w.tm_hour, w.tm_min);
  return s;
}
