// Mode absen (doc/spesifikasi-api.md, "Mode pilih"):
//  - "auto"   : semua tap dikirim ke POST /tap, server yang menentukan datang atau pulang (bawaan).
//  - "select" : layar utama punya tombol DATANG dan PULANG. Petugas memilih sekali, lalu semua tap dikirim ke
//               POST /check-in atau POST /check-out sampai pilihannya diganti.
// Pilihan disimpan (tahan restart / listrik padam) tapi dikosongkan saat tanggal berganti, supaya pilihan
// "PULANG" kemarin sore tidak terbawa ke pagi hari. Mode bisa diubah dari menu alat atau config.tap_mode server.
#pragma once

String pilihanMode;     // "check_in", "check_out", atau "" (belum dipilih)
String pilihanTanggal;  // tanggal saat memilih "YYYY-MM-DD" ("" = dipilih saat jam belum sinkron)

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

// Mode yang dipakai untuk tap berikutnya: "" = /tap (otomatis), "check_in", atau "check_out".
// Di mode pilih yang belum memilih juga "" (pemanggil harus menolak tap, lihat prosesKartu).
String modeTap() { return modePilih() ? pilihanMode : String(""); }
