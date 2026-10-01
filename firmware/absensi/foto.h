// Foto pemilik kartu dari "photo_url": diunduh ke RAM, diperiksa, lalu digambar dengan TJpg_Decoder.
// Syarat (spesifikasi): JPEG baseline (bukan progressive), maks. 160x160 piksel, maks. 30 KB, unduh maks. 3 detik
// (FOTO_MAKS_PX, FOTO_MAKS_BYTE, FOTO_TIMEOUT_MS di config.h).
// Kalau tidak memenuhi syarat, foto dilewati diam-diam (alasannya ditulis ke Serial).
#pragma once
#include <TJpg_Decoder.h>

// Dipanggil TJpg_Decoder untuk setiap potongan gambar yang sudah didekode.
bool fotoKeLayar(int16_t x, int16_t y, uint16_t w, uint16_t h, uint16_t* bitmap) {
  if (y >= tft.height()) return false;
  tft.pushImage(x, y, w, h, bitmap);
  return true;
}

// Periksa kepala file JPEG: cari penanda SOF untuk jenis dan ukuran gambar.
// SOF0/SOF1 = baseline (boleh), SOF2 = progressive (ditolak), SOF lain juga ditolak.
bool fotoCekJpeg(const uint8_t* d, size_t n, int& lebar, int& tinggi, String& alasan) {
  if (n < 4 || d[0] != 0xFF || d[1] != 0xD8) { alasan = "bukan file JPEG"; return false; }
  size_t i = 2;
  while (i + 4 <= n) {
    if (d[i] != 0xFF) { alasan = "struktur JPEG rusak"; return false; }
    uint8_t penanda = d[i + 1];
    if (penanda == 0xFF) { i++; continue; }                   // pengisi
    size_t panjang = (d[i + 2] << 8) | d[i + 3];
    if (penanda == 0xC2) { alasan = "JPEG progressive"; return false; }
    if (penanda == 0xC0 || penanda == 0xC1) {
      if (i + 9 > n) break;
      tinggi = (d[i + 5] << 8) | d[i + 6];
      lebar  = (d[i + 7] << 8) | d[i + 8];
      return true;
    }
    bool sofLain = penanda >= 0xC3 && penanda <= 0xCF && penanda != 0xC4 && penanda != 0xC8 && penanda != 0xCC;
    if (sofLain) { alasan = "jenis JPEG tidak didukung"; return false; }
    if (penanda == 0xDA || penanda == 0xD9) break;            // data gambar mulai, SOF tidak ada
    i += 2 + panjang;
  }
  alasan = "ukuran gambar tidak ditemukan";
  return false;
}

// Unduh foto ke `data` (malloc, harus di-free). False kalau gagal atau melanggar batas.
// Seluruh unduhan (sambung, TLS, tunggu jawaban, baca isi) paling lama FOTO_TIMEOUT_MS.
// Sambungan foto dibiarkan terbuka kalau server mengizinkan, jadi foto berikutnya lebih cepat.
bool fotoUnduh(const String& url, uint8_t*& data, size_t& n, String& alasan) {
  data = nullptr;
  n = 0;
  if (!url.startsWith("https://") && !url.startsWith("http://")) { alasan = "URL bukan http/https"; return false; }
  if (!wifiTerhubung()) { alasan = "WiFi tidak tersambung"; return false; }

  uint32_t mulai = millis();
  HTTPClient& h = sambunganFoto.http;
  int kode = httpKirim(sambunganFoto, false, url, "", FOTO_TIMEOUT_MS, true);
  if (kode <= 0) { alasan = teksGalat(kode); return false; }
  int ukuran = h.getSize();                   // -1 kalau server tidak memberi tahu
  NetworkClient* aliran = h.getStreamPtr();
  if (kode == 200 && ukuran <= (int)FOTO_MAKS_BYTE && aliran) data = (uint8_t*)malloc(FOTO_MAKS_BYTE);
  if (kode != 200) alasan = "HTTP " + String(kode);
  else if (ukuran > (int)FOTO_MAKS_BYTE) alasan = "terlalu besar (" + String(ukuran / 1024) + " KB)";
  else if (!aliran) alasan = "sambungan terputus";
  else if (!data) alasan = "RAM tidak cukup";
  if (alasan.length() > 0) {
    sambunganPutus(sambunganFoto);            // isi balasan tidak dibaca: sambungan ditutup
    return false;
  }

  bool terlaluBesar = false;
  while (millis() - mulai < FOTO_TIMEOUT_MS) {
    int ada = aliran->available();
    if (ada > 0) {
      if (n + ada > FOTO_MAKS_BYTE) { terlaluBesar = true; break; }
      n += aliran->read(data + n, ada);
      if (ukuran > 0 && (int)n >= ukuran) break;
    } else if (!aliran->connected()) {
      break;                                  // server selesai mengirim
    } else {
      delay(2);
    }
  }
  bool belumHabis = ukuran > 0 ? (int)n < ukuran : aliran->connected();   // masih ada isi yang belum terbaca?

  if (terlaluBesar) alasan = "lebih dari " + String(FOTO_MAKS_BYTE / 1024) + " KB";
  else if (belumHabis) alasan = "unduhan tidak selesai dalam " + String(FOTO_TIMEOUT_MS / 1000) + " detik";
  else if (n == 0) alasan = "file kosong";

  if (alasan.length() == 0 && ukuran > 0) h.end();   // isi terbaca habis: sambungan boleh dipakai lagi
  else sambunganPutus(sambunganFoto);
  if (alasan.length() == 0) return true;
  free(data);
  data = nullptr;
  return false;
}

// Unduh, periksa, dan gambar foto di tengah kotak 160x160 (pojok kiri atas kx, ky).
// Kotak dikosongkan dengan warna `latar` dulu. True kalau foto berhasil digambar
// (kalau false, isi kotak mungkin sudah berubah: pemanggil sebaiknya menggambar ulang kotaknya).
bool fotoTampilkan(const String& url, int kx, int ky, uint16_t latar) {
  uint8_t* data;
  size_t n;
  String alasan;
  uint32_t mulai = millis();
  if (!fotoUnduh(url, data, n, alasan)) {
    Serial.printf("Foto dilewati: %s (%s)\n", alasan.c_str(), url.c_str());
    return false;
  }
  int lebar = 0, tinggi = 0;
  bool ok = fotoCekJpeg(data, n, lebar, tinggi, alasan);
  if (ok && (lebar <= 0 || tinggi <= 0 || lebar > FOTO_MAKS_PX || tinggi > FOTO_MAKS_PX)) {
    alasan = "ukuran " + String(lebar) + "x" + String(tinggi) + " lebih dari " + String(FOTO_MAKS_PX) + "x" + String(FOTO_MAKS_PX);
    ok = false;
  }
  if (ok) {
    TJpgDec.setJpgScale(1);
    TJpgDec.setSwapBytes(true);
    TJpgDec.setCallback(fotoKeLayar);
    int x = kx + (FOTO_MAKS_PX - lebar) / 2;
    int y = ky + (FOTO_MAKS_PX - tinggi) / 2;
    tft.fillRect(kx, ky, FOTO_MAKS_PX, FOTO_MAKS_PX, latar);
    JRESULT hasil = TJpgDec.drawJpg(x, y, data, n);
    if (hasil != JDR_OK) { alasan = "gagal didekode (" + String(hasil) + ")"; ok = false; }
  }
  free(data);
  if (ok) Serial.printf("Foto: %dx%d, %u byte, %lu ms\n", lebar, tinggi, (unsigned)n, (unsigned long)(millis() - mulai));
  else    Serial.printf("Foto dilewati: %s (%s)\n", alasan.c_str(), url.c_str());
  return ok;
}
