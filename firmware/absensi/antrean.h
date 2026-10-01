// Antrean tap saat server tidak bisa dihubungi. Disimpan di LittleFS supaya tetap ada walau alat mati.
// File teks, satu tap per baris:  tap_id|rfid|uid_hex|tapped_at   (tapped_at kosong = belum ada jam)
// Paling banyak ANTREAN_MAKS tap. Kalau penuh, tap paling lama dibuang.
#pragma once
#include <LittleFS.h>

const char FILE_ANTREAN[] = "/antrean.txt";
const char FILE_SEMENTARA[] = "/antrean.tmp";

struct TapAntrean { String tapId, rfid, uidHex, waktu; };

int jumlahAntrean = 0;
bool antreanSiap = false;     // LittleFS berhasil dipasang?

void antreanMulai() {
  // true = format otomatis kalau belum pernah dipakai (misalnya setelah ganti skema partisi)
  antreanSiap = LittleFS.begin(true);
  if (!antreanSiap) {
    Serial.println("Antrean: LittleFS gagal, tap offline tidak bisa disimpan!");
    return;
  }
  jumlahAntrean = 0;
  fs::File f = LittleFS.open(FILE_ANTREAN, "r");
  if (f) {
    while (f.available()) {
      if (f.readStringUntil('\n').length() > 0) jumlahAntrean++;
    }
    f.close();
  }
  Serial.printf("Antrean: %d tap menunggu dikirim\n", jumlahAntrean);
}

// Pecah satu baris menjadi 4 bagian.
bool antreanBaca(const String& baris, TapAntrean& t) {
  int a = baris.indexOf('|');
  int b = baris.indexOf('|', a + 1);
  int c = baris.indexOf('|', b + 1);
  if (a < 0 || b < 0 || c < 0) return false;
  t.tapId  = baris.substring(0, a);
  t.rfid   = baris.substring(a + 1, b);
  t.uidHex = baris.substring(b + 1, c);
  t.waktu  = baris.substring(c + 1);
  t.waktu.trim();
  return t.rfid.length() > 0;
}

// Ambil tap paling lama (belum dihapus). False kalau antrean kosong.
bool antreanPertama(TapAntrean& t) {
  if (!antreanSiap || jumlahAntrean == 0) return false;
  fs::File f = LittleFS.open(FILE_ANTREAN, "r");
  if (!f) return false;
  String baris = f.readStringUntil('\n');
  f.close();
  return antreanBaca(baris, t);
}

// Buang tap paling lama: salin semua baris kecuali yang pertama ke file baru.
void antreanBuangPertama() {
  if (!antreanSiap || jumlahAntrean == 0) return;
  fs::File lama = LittleFS.open(FILE_ANTREAN, "r");
  fs::File baru = LittleFS.open(FILE_SEMENTARA, "w");
  if (!lama || !baru) return;
  lama.readStringUntil('\n');                              // baris pertama dilewati
  int sisa = 0;
  while (lama.available()) {
    String baris = lama.readStringUntil('\n');
    if (baris.length() == 0) continue;
    baru.print(baris);
    baru.print('\n');
    sisa++;
  }
  lama.close();
  baru.close();
  LittleFS.remove(FILE_ANTREAN);
  LittleFS.rename(FILE_SEMENTARA, FILE_ANTREAN);
  jumlahAntrean = sisa;
}

// Simpan tap baru di akhir antrean.
void antreanTambah(const TapAntrean& t) {
  if (!antreanSiap) return;
  if (jumlahAntrean >= ANTREAN_MAKS) {
    Serial.println("Antrean penuh: tap paling lama dibuang");
    antreanBuangPertama();
  }
  fs::File f = LittleFS.open(FILE_ANTREAN, "a");
  if (!f) return;
  f.printf("%s|%s|%s|%s\n", t.tapId.c_str(), t.rfid.c_str(), t.uidHex.c_str(), t.waktu.c_str());
  f.close();
  jumlahAntrean++;
  Serial.printf("Antrean: tap %s disimpan (%d menunggu)\n", t.rfid.c_str(), jumlahAntrean);
}

void antreanHapusSemua() {
  if (antreanSiap) LittleFS.remove(FILE_ANTREAN);
  jumlahAntrean = 0;
}
