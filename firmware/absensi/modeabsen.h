// Mode absen (doc/spesifikasi-api.md, "Mode pilih"):
//  - "auto"   : semua tap dikirim ke POST /tap, server yang menentukan datang atau pulang (bawaan).
//  - "select" : layar utama punya tombol DATANG dan PULANG. Petugas memilih sekali, lalu semua tap dikirim ke
//               POST /check-in atau POST /check-out sampai pilihannya diganti.
// Pilihan disimpan (tahan restart / listrik padam) tapi dikosongkan saat tanggal berganti, supaya pilihan
// "PULANG" kemarin sore tidak terbawa ke pagi hari. Mode bisa diubah dari menu alat atau config.tap_mode server.
// Jadwal (config.tap_schedule, opsional): sejak jam masuk DATANG, sejak jam pulang PULANG. Jadwal selalu menang:
// pilihan manual yang berbeda dari jadwal hanya bertahan JADWAL_KEMBALI_MS (3 menit), lalu kembali ke jadwal.
#pragma once

String pilihanMode;     // "check_in", "check_out", atau "" (belum dipilih)
String pilihanTanggal;  // tanggal saat memilih "YYYY-MM-DD" ("" = dipilih saat jam belum sinkron)
uint32_t pilihanManualMs = 0;  // kapan petugas terakhir menekan DATANG/PULANG (0 = belum pernah sejak menyala)

String tanggalHariIni() { return jamValid() ? waktuIso().substring(0, 10) : ""; }

void pilihanMuat() {
  pilihanMode = prefs.getString("pilihMode", "");
  pilihanTanggal = prefs.getString("pilihTgl", "");
  if (pilihanMode != "check_in" && pilihanMode != "check_out") pilihanMode = "";
}

void pilihanSimpan(const String& mode) {
  pilihanMode = mode;
  pilihanTanggal = tanggalHariIni();
  prefs.putString("pilihMode", mode);
  prefs.putString("pilihTgl", pilihanTanggal);
  Serial.printf("Mode absen dipilih: %s\n", mode == "check_in" ? "DATANG" : mode == "check_out" ? "PULANG" : "(kosong)");
}

// Dipanggil berkala. True kalau pilihan baru saja dikosongkan karena tanggal berganti (layar perlu digambar ulang).
bool pilihanCekTanggal() {
  static uint32_t cekTerakhir = 0;
  if (millis() - cekTerakhir < 5000) return false;          // cukup dicek tiap 5 detik
  cekTerakhir = millis();
  if (pilihanMode.length() == 0 || !jamValid()) return false;
  String hariIni = tanggalHariIni();
  if (pilihanTanggal.length() == 0) {             // dipilih sebelum jam sinkron: anggap dipilih hari ini
    pilihanTanggal = hariIni;
    prefs.putString("pilihTgl", hariIni);
    return false;
  }
  if (pilihanTanggal == hariIni) return false;
  Serial.println("Mode absen: tanggal berganti, pilihan DATANG/PULANG dikosongkan");
  pilihanSimpan("");
  return true;
}

bool modePilih() { return atur.modeAbsen == "select"; }

bool jadwalAktif() { return atur.jadwalMasuk.length() == 5 && atur.jadwalPulang.length() == 5; }

// Periode jadwal sekarang: "check_in" (sejak jam masuk), "check_out" (sejak jam pulang sampai tengah malam),
// atau "" (sebelum jam masuk).
String jadwalPeriode() {
  time_t t = time(nullptr);
  struct tm w;
  localtime_r(&t, &w);
  int sekarang = w.tm_hour * 60 + w.tm_min;
  if (sekarang >= menitDariJam(atur.jadwalPulang)) return "check_out";
  if (sekarang >= menitDariJam(atur.jadwalMasuk)) return "check_in";
  return "";
}

// Dipanggil saat petugas menekan DATANG / PULANG.
void pilihanManual(const String& mode) {
  pilihanManualMs = millis();
  if (mode != pilihanMode) pilihanSimpan(mode);
}

// Dipanggil berkala. True kalau jadwal baru saja mengganti pilihan (layar utama perlu digambar ulang).
// Pilihan disamakan dengan jadwal (juga saat alat menyala). Kalau petugas memilih yang berbeda, pilihan itu
// dibiarkan JADWAL_KEMBALI_MS sejak tombol terakhir ditekan, lalu dikembalikan ke jadwal.
bool jadwalUrus() {
  static uint32_t cekTerakhir = 0;
  if (millis() - cekTerakhir < 5000) return false;          // cukup dicek tiap 5 detik
  cekTerakhir = millis();
  if (!modePilih() || !jadwalAktif() || !jamValid()) return false;
  String periode = jadwalPeriode();
  if (periode.length() == 0 || pilihanMode == periode) return false;   // sebelum jam masuk: pilihan bebas
  if (pilihanManualMs != 0 && millis() - pilihanManualMs < JADWAL_KEMBALI_MS) return false;
  Serial.printf("Jadwal: %s dipilih otomatis (%s)\n", periode == "check_in" ? "DATANG" : "PULANG",
                pilihanMode.length() ? "pilihan manual dikembalikan" : "sesuai jam");
  pilihanSimpan(periode);
  return true;
}

// Mode yang dipakai untuk tap berikutnya: "" = /tap (otomatis), "check_in", atau "check_out".
// Di mode pilih yang belum memilih juga "" (pemanggil harus menolak tap, lihat prosesKartu).
String modeTap() { return modePilih() ? pilihanMode : String(""); }
