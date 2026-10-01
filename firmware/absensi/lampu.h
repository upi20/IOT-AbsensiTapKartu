// Lampu latar layar (pin LED di LCD ke D2): terang saat dipakai, meredup kalau alat diam.
// Lama diam dan kecerahan redup diatur server lewat config.dim_after dan config.dim_level
// (doc/spesifikasi-api.md bagian 6), disimpan di memori alat.
// Saat redup, kartu tetap diproses dan lampu langsung terang. Sentuhan pertama hanya menyalakan lampu
// (lihat sentuhBaru di touch.h), supaya tombol tidak tertekan tanpa sengaja di layar yang gelap.
#pragma once

bool lampuRedup = false;
uint32_t lampuAktifTerakhir = 0;    // kapan terakhir ada kartu atau sentuhan

void lampuTulis(int persen) {
  ledcWrite(PIN_LAMPU_LCD, persen * 255 / 100);
}

// Dipanggil sebelum layar mulai menggambar. D2 LOW saat menyala (syarat boot ESP32), lalu lampu terang.
void lampuMulai() {
  ledcAttach(PIN_LAMPU_LCD, 5000, 8);                     // PWM 5 kHz, 0..255
  lampuTulis(100);
  lampuAktifTerakhir = millis();
}

// Ada kartu atau sentuhan. False kalau lampu tadinya redup (baru dinyalakan sekarang).
bool lampuBangun() {
  lampuAktifTerakhir = millis();
  if (!lampuRedup) return true;
  lampuRedup = false;
  lampuTulis(100);
  Serial.println("Layar: terang");
  return false;
}

// Dipanggil terus (dari urusLatar). Meredup setelah atur.redupDetik tanpa kartu / sentuhan.
void lampuUrus() {
  if (lampuRedup || atur.redupDetik == 0) return;
  if (millis() - lampuAktifTerakhir < atur.redupDetik * 1000UL) return;
  lampuRedup = true;
  lampuTulis(atur.redupPersen);
  Serial.printf("Layar: redup (%d%%) setelah diam %d detik\n", atur.redupPersen, atur.redupDetik);
}

// Pengaturan redup berubah dari server: kalau sedang redup, kecerahan baru langsung dipakai.
// Kalau dim_after jadi 0 (tidak pernah redup), lampu langsung terang.
void lampuTerapkan() {
  if (!lampuRedup) return;
  if (atur.redupDetik == 0) lampuBangun();
  else lampuTulis(atur.redupPersen);
}
