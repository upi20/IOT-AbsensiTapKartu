// RTC DS3231 (modul ZS-042) sebagai cadangan jam: jam tetap tersimpan selama listrik mati (baterai koin).
// Dipasang di kabel header LED (JLED): R = SDA (D25), G = SCL (D26), B = VCC 3,3 V, K = GND.
//
// Jam dari internet (server_time / NTP) selalu diutamakan. RTC hanya dipakai sejak alat menyala sampai
// jam internet didapat, dan disetel ulang dari jam internet (pertama kali didapat, lalu tiap 6 jam).
// Contoh: listrik & internet mati 09:00, listrik menyala 12:00, internet 17:00 -> tap 12:00-17:00 tetap
// tercatat dengan jam aslinya.
//
// Terdeteksi otomatis saat menyala. Kalau header berisi LED RGB (tidak ada RTC), alat bekerja seperti biasa.
// DS3231 menyimpan jam dalam UTC; zona tampilan tetap dari server (atur.offsetMenit).
#pragma once
#include <Wire.h>

const uint8_t RTC_ALAMAT = 0x68;
const uint32_t RTC_TULIS_ULANG_MS = 6UL * 3600 * 1000;

bool rtcAda = false;             // modul RTC menjawab di I2C
bool rtcValidSaatMulai = false;  // false = jam RTC hilang (baterai habis / belum pernah disetel)
bool jamDariRtc = false;         // jam alat sekarang berasal dari RTC (belum ada jam internet)

uint8_t bcdKe(uint8_t b) { return (b >> 4) * 10 + (b & 0x0F); }
uint8_t keBcd(int d) { return ((d / 10) << 4) | (d % 10); }

bool rtcBacaReg(uint8_t reg, uint8_t* isi, uint8_t n) {
  Wire.beginTransmission(RTC_ALAMAT);
  Wire.write(reg);
  if (Wire.endTransmission(false) != 0) return false;
  if (Wire.requestFrom(RTC_ALAMAT, n) != n) return false;
  for (uint8_t i = 0; i < n; i++) isi[i] = Wire.read();
  return true;
}

bool rtcTulisReg(uint8_t reg, const uint8_t* isi, uint8_t n) {
  Wire.beginTransmission(RTC_ALAMAT);
  Wire.write(reg);
  Wire.write(isi, n);
  return Wire.endTransmission() == 0;
}

// Jam RTC (UTC). False kalau tidak terbaca atau isinya tidak bisa dipercaya.
bool rtcBaca(time_t& utc) {
  uint8_t r[7], status;
  if (!rtcBacaReg(0x00, r, 7) || !rtcBacaReg(0x0F, &status, 1)) return false;
  if (status & 0x80) return false;                        // OSF: osilator pernah berhenti -> jam hilang
  if (r[2] & 0x40) return false;                          // mode 12 jam: tidak pernah ditulis alat ini
  int detik = bcdKe(r[0] & 0x7F), menit = bcdKe(r[1] & 0x7F), jam = bcdKe(r[2] & 0x3F);
  int tgl = bcdKe(r[4] & 0x3F), bln = bcdKe(r[5] & 0x1F), thn = 2000 + bcdKe(r[6]);
  if (thn < 2024 || bln < 1 || bln > 12 || tgl < 1 || tgl > 31 || jam > 23 || menit > 59 || detik > 59) return false;
  utc = (time_t)hariSejak1970(thn, bln, tgl) * 86400 + jam * 3600 + menit * 60 + detik;
  return true;
}

bool rtcTulis(time_t utc) {
  struct tm w;
  gmtime_r(&utc, &w);
  uint8_t r[7] = {keBcd(w.tm_sec), keBcd(w.tm_min), keBcd(w.tm_hour), (uint8_t)(w.tm_wday + 1),
                  keBcd(w.tm_mday), keBcd(w.tm_mon + 1), keBcd(w.tm_year - 100)};
  uint8_t status;
  if (!rtcTulisReg(0x00, r, 7) || !rtcBacaReg(0x0F, &status, 1)) return false;
  status &= ~0x80;                                        // hapus OSF: jam RTC sekarang benar
  return rtcTulisReg(0x0F, &status, 1);
}

// Dipanggil sekali di setup(), sebelum feedbackMulai() (pin header dipakai RTC atau LED).
void rtcMulai() {
  Wire.begin(RTC_SDA, RTC_SCL, 100000);
  Wire.beginTransmission(RTC_ALAMAT);
  rtcAda = Wire.endTransmission() == 0;
  if (!rtcAda) {
    Wire.end();                                           // pin dikembalikan untuk LED RGB
    Serial.println("RTC: tidak terpasang");
    return;
  }
  ledAda = false;                                         // header dipakai RTC, LED RGB tidak ada
  time_t utc;
  rtcValidSaatMulai = rtcBaca(utc);
  if (!rtcValidSaatMulai) {
    Serial.println("RTC: terpasang, tapi jamnya hilang (baterai habis / baru). Menunggu jam dari internet");
    return;
  }
  timeval tv = {utc, 0};
  settimeofday(&tv, nullptr);
  jamDariRtc = true;
  Serial.printf("RTC: jam diambil dari RTC (%s), sementara menunggu jam dari internet\n", waktuIso().c_str());
}

// Dipanggil terus dari loop(): setel RTC dari jam internet.
void rtcUrus() {
  if (!rtcAda || !(jamDariServer || jamDariNtp)) return;
  static bool sudah = false;
  static uint32_t terakhir = 0;
  if (sudah && millis() - terakhir < RTC_TULIS_ULANG_MS) return;
  sudah = true;
  terakhir = millis();
  jamDariRtc = false;
  if (rtcTulis(time(nullptr))) Serial.printf("RTC: disetel dari jam %s (%s)\n", jamDariServer ? "server" : "NTP", waktuIso().c_str());
  else Serial.println("RTC: gagal menulis jam");
}

// Asal jam alat untuk ditampilkan: server, NTP, RTC, atau belum sinkron.
String jamSumber() {
  if (jamDariServer) return "server";
  if (jamDariNtp) return "NTP";
  if (jamDariRtc) return "RTC";
  return "belum sinkron";
}

// Untuk heartbeat (raw.rtc): none = tidak terpasang, ok, lost = jam RTC hilang saat menyala (cek baterai).
const char* rtcStatusKode() { return !rtcAda ? "none" : rtcValidSaatMulai ? "ok" : "lost"; }
