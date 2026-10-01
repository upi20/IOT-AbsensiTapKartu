// Perawatan supaya alat tahan menyala berbulan-bulan tanpa disentuh:
//  - Watchdog: kalau program macet lebih dari WATCHDOG_MS (loop() dan layar menu berhenti "memberi makan"),
//    ESP32 restart sendiri. Diberi makan di loop() dan urusLatar() (semua layar menu & panduan memanggilnya).
//  - Restart harian: sekali sehari pada atur.restartAt (jam alat, "HH:MM"; "" = mati), hanya saat alat diam.
//    Jamnya diatur per alat dari server lewat config.restart_at (doc/spesifikasi-api.md bagian 6).
//  - Alasan restart terakhir dicatat di monitor serial, Info alat, dan heartbeat (raw.reset_reason).
#pragma once
#include <esp_task_wdt.h>

void watchdogMulai() {
  esp_task_wdt_config_t cfg = {};
  cfg.timeout_ms = WATCHDOG_MS;
  cfg.idle_core_mask = 1 << 0;      // tetap awasi idle task core 0, seperti bawaan Arduino
  cfg.trigger_panic = true;         // macet -> panic -> restart
  if (esp_task_wdt_reconfigure(&cfg) != ESP_OK) esp_task_wdt_init(&cfg);   // belum aktif: nyalakan
  esp_task_wdt_add(NULL);           // awasi task loop()
  Serial.printf("Watchdog: aktif (%lu detik)\n", (unsigned long)(WATCHDOG_MS / 1000));
}

inline void watchdogPakan() { esp_task_wdt_reset(); }

// Kode alasan restart terakhir (bahasa mesin, dikirim ke server) dan teksnya untuk layar.
const char* alasanResetKode() {
  switch (esp_reset_reason()) {
    case ESP_RST_POWERON:  return "poweron";
    case ESP_RST_EXT:      return "external";
    case ESP_RST_SW:       return "software";
    case ESP_RST_PANIC:    return "panic";
    case ESP_RST_INT_WDT:
    case ESP_RST_TASK_WDT:
    case ESP_RST_WDT:      return "watchdog";
    case ESP_RST_BROWNOUT: return "brownout";
    case ESP_RST_DEEPSLEEP: return "deepsleep";
    default:               return "other";
  }
}

const char* alasanResetTeks() {
  switch (esp_reset_reason()) {
    case ESP_RST_POWERON:  return "baru dinyalakan / tombol EN";
    case ESP_RST_EXT:      return "reset dari luar";
    case ESP_RST_SW:       return "restart oleh program";
    case ESP_RST_PANIC:    return "program error";
    case ESP_RST_INT_WDT:
    case ESP_RST_TASK_WDT:
    case ESP_RST_WDT:      return "watchdog (program macet)";
    case ESP_RST_BROWNOUT: return "listrik turun (brownout)";
    default:               return "lainnya";
  }
}

// Lama menyala dalam detik (64-bit, tidak berputar ulang seperti millis()).
uint32_t lamaNyalaDetik() { return (uint32_t)(esp_timer_get_time() / 1000000ULL); }

// Dipanggil terus dari loop() di layar utama / screensaver. `diam` = tidak ada kartu atau sentuhan
// selama RESTART_DIAM_MS. Restart hanya kalau: jadwal aktif, jam sudah sinkron, sekarang di dalam
// jendela RESTART_JENDELA_MENIT sejak jam restart, dan alat sudah menyala lebih dari RESTART_MIN_NYALA_S
// (supaya tidak restart berulang setelah menyala lagi di menit yang sama).
void urusRestartHarian(bool diam) {
  static uint32_t cekTerakhir = 0;
  if (millis() - cekTerakhir < 5000) return;              // cukup dicek tiap 5 detik
  cekTerakhir = millis();
  if (!diam || atur.restartAt.length() != 5 || !jamValid()) return;
  if (lamaNyalaDetik() < RESTART_MIN_NYALA_S) return;

  int jadwal = atur.restartAt.substring(0, 2).toInt() * 60 + atur.restartAt.substring(3, 5).toInt();
  time_t t = time(nullptr);
  struct tm lokal;
  localtime_r(&t, &lokal);                                 // jam alat (zona waktu yang tampil di layar)
  int sekarang = lokal.tm_hour * 60 + lokal.tm_min;
  if ((sekarang - jadwal + 1440) % 1440 >= RESTART_JENDELA_MENIT) return;

  Serial.printf("Restart harian (jadwal %s), sudah menyala %lu detik\n", atur.restartAt.c_str(),
                (unsigned long)lamaNyalaDetik());
  delay(200);                                              // beri waktu monitor serial menulis
  ESP.restart();
}
