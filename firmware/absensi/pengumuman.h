// Daftar pengumuman untuk screensaver: GET {base}/announcements (doc/spesifikasi-api.md bagian 8).
// Diambil setelah WiFi tersambung, saat config.announcements_rev berubah, dan paling lambat tiap 10 menit.
// Saat server gagal, pengambilan menunggu heartbeat berhasil dulu, dan setelah galat jaringan jedanya makin lama.
// Daftar terakhir disimpan di LittleFS (/pengumuman.json) supaya tetap tampil saat server mati / setelah menyala ulang.
// Kalau gagal (HTTP 404, bukan JSON, galat jaringan), daftar lama tetap dipakai tanpa pesan di layar
// (hasilnya hanya ditulis ke Serial dan ke layar Info alat).
#pragma once
#include <ArduinoJson.h>

const char FILE_PENGUMUMAN[] = "/pengumuman.json";

struct Pengumuman {
  String judul;        // maks. 40 huruf
  String isi;          // deskripsi, maks. 160 huruf (boleh kosong)
  int ikon;            // JenisIkon (ikon.h)
};
Pengumuman daftarPengumuman[PENGUMUMAN_MAKS];
int jumlahPengumuman = 0;
int intervalPengumuman = INTERVAL_BAWAAN;    // detik tiap pengumuman tampil
int diamPengumuman = DIAM_BAWAAN;            // detik diam sebelum screensaver muncul

String pengumumanHasil = "Belum diambil";    // hasil pengambilan terakhir (untuk layar Info alat)
bool pengumumanBerubah = false;              // true = daftar baru saja berubah (screensaver menggambar ulang)
String pengumumanTersimpan = "";             // isi file terakhir (supaya tidak menulis ulang isi yang sama)
uint32_t pengumumanBerikut = 0;              // pengambilan cadangan berikutnya
uint32_t pengumumanJedaGagal = PENGUMUMAN_ULANG_MS;   // jeda setelah galat jaringan (dilipatgandakan tiap gagal lagi)

// Potong teks menjadi paling banyak `maks` huruf (huruf beraksen dihitung satu, lihat jumlahHuruf()).
String potongHuruf(const String& s, int maks) {
  int n = 0;
  for (unsigned int i = 0; i < s.length(); i++) {
    if ((s[i] & 0xC0) != 0x80 && ++n > maks) return s.substring(0, i);
  }
  return s;
}

// Angka dari JSON dalam batas [min, maks]. Tidak ada, bukan angka bulat, atau di luar batas = nilai bawaan.
int angkaDalam(JsonVariant v, int minimal, int maksimal, int bawaan) {
  if (!v.is<int>()) return bawaan;
  int n = v.as<int>();
  return n >= minimal && n <= maksimal ? n : bawaan;
}

// Ambil isi dari JSON (balasan server atau file tersimpan). False kalau "ok" bukan true atau "items" tidak ada.
bool pengumumanBaca(JsonDocument& doc) {
  if (!(doc["ok"] | false)) return false;
  JsonArray items = doc["items"];
  if (items.isNull()) return false;

  int n = 0, dilihat = 0;
  for (JsonVariant it : items) {
    if (dilihat++ >= PENGUMUMAN_MAKS) break;               // lebih dari 10: sisanya diabaikan
    String judul = it["title"] | "";
    judul.trim();
    if (judul.length() == 0) continue;                     // judul wajib
    String isi = it["description"] | "";
    isi.replace("\r", "");
    isi.replace("\t", " ");
    isi.trim();
    daftarPengumuman[n].judul = potongHuruf(judul, JUDUL_MAKS_HURUF);
    daftarPengumuman[n].isi = potongHuruf(isi, ISI_MAKS_HURUF);
    daftarPengumuman[n].ikon = ikonDariKode(it["icon"] | "");
    n++;
  }
  jumlahPengumuman = n;
  intervalPengumuman = angkaDalam(doc["interval"], 2, 60, INTERVAL_BAWAAN);
  diamPengumuman = angkaDalam(doc["idle"], 5, 600, DIAM_BAWAAN);
  return true;
}

// Daftar sekarang sebagai JSON yang rapi (format sama dengan balasan server).
String pengumumanJson() {
  JsonDocument doc;
  doc["ok"] = true;
  doc["interval"] = intervalPengumuman;
  doc["idle"] = diamPengumuman;
  JsonArray items = doc["items"].to<JsonArray>();
  for (int i = 0; i < jumlahPengumuman; i++) {
    JsonObject p = items.add<JsonObject>();
    p["title"] = daftarPengumuman[i].judul;
    p["description"] = daftarPengumuman[i].isi;
    p["icon"] = KODE_IKON[daftarPengumuman[i].ikon];
  }
  String teks;
  serializeJson(doc, teks);
  return teks;
}

// Simpan ke LittleFS, hanya kalau isinya berubah (supaya flash tidak cepat aus).
void pengumumanSimpan() {
  String teks = pengumumanJson();
  if (teks == pengumumanTersimpan) return;
  pengumumanTersimpan = teks;
  pengumumanBerubah = true;
  if (!antreanSiap) return;                                // LittleFS rusak: hanya di RAM
  fs::File f = LittleFS.open(FILE_PENGUMUMAN, "w");
  if (!f) return;
  f.print(teks);
  f.close();
  Serial.printf("Pengumuman: %d disimpan (ganti tiap %d detik, muncul setelah diam %d detik)\n",
                jumlahPengumuman, intervalPengumuman, diamPengumuman);
}

// Dipanggil sekali di setup(), setelah antreanMulai() (LittleFS sudah dipasang).
void pengumumanMuat() {
  if (!antreanSiap) return;
  fs::File f = LittleFS.open(FILE_PENGUMUMAN, "r");
  if (!f) {
    Serial.println("Pengumuman: belum ada daftar tersimpan");
    return;
  }
  JsonDocument doc;
  bool ok = !deserializeJson(doc, f) && pengumumanBaca(doc);
  f.close();
  if (!ok) {
    Serial.println("Pengumuman: file tersimpan rusak, diabaikan");
    jumlahPengumuman = 0;
    return;
  }
  pengumumanTersimpan = pengumumanJson();
  Serial.printf("Pengumuman: %d dari memori\n", jumlahPengumuman);
}

// Dipanggil saat reset pabrik.
void pengumumanHapus() {
  if (antreanSiap) LittleFS.remove(FILE_PENGUMUMAN);
  jumlahPengumuman = 0;
}

// ---------- GET /announcements ----------

void apiPengumuman() {
  String balasan;
  int kode = apiKirim(false, "/announcements", "", balasan, LATAR_TIMEOUT_MS);
  // Gagal karena jaringan / server sibuk: coba lagi 1 menit lagi, lalu 2, 4, 8, ... menit (maks. 10 menit).
  // Selain itu tunggu jadwal biasa (10 menit).
  if (gagalJaringan(kode)) {
    pengumumanBerikut = millis() + pengumumanJedaGagal;
    pengumumanJedaGagal = min(pengumumanJedaGagal * 2, PENGUMUMAN_CEK_MS);
  } else {
    pengumumanBerikut = millis() + PENGUMUMAN_CEK_MS;
    pengumumanJedaGagal = PENGUMUMAN_ULANG_MS;
  }

  JsonDocument doc;
  if (kode == 404)                        pengumumanHasil = "Tidak disediakan server (404)";
  else if (!kode2xx(kode))                pengumumanHasil = "Gagal: " + teksGalat(kode);
  else if (deserializeJson(doc, balasan)) pengumumanHasil = "Gagal: balasan bukan JSON";
  else if (!pengumumanBaca(doc))          pengumumanHasil = "Gagal: isi tidak valid";
  else {
    pengumumanHasil = "OK";
    pengumumanSimpan();
  }
  String jam = jamMenit();
  if (jam.length() > 0) pengumumanHasil += " (" + jam + ")";
  Serial.printf("Pengumuman: %s, %d tampil\n", pengumumanHasil.c_str(), jumlahPengumuman);
}

// Dipanggil dari loop() (layar utama & screensaver, saat alat diam). True kalau request dikirim.
bool urusPengumuman() {
  if (!wifiTerhubung()) return false;
  if (statusServer == SERVER_GAGAL) return false;          // server sedang gagal: tunggu heartbeat berhasil dulu
  if (!pengumumanSegera && (int32_t)(millis() - pengumumanBerikut) < 0) return false;
  pengumumanSegera = false;
  apiPengumuman();
  return true;
}
