// Penyetelan koneksi: pilih WiFi (hasil pindai), password, Base URL, API key, lalu tes koneksi.
// Dipakai oleh panduan pertama kali (alat baru / setelah reset pabrik) dan oleh menu Pengaturan.
#pragma once

// ---------- Daftar WiFi hasil pindai ----------

struct JaringanWifi { String ssid; int rssi; bool kunci; };
const int WIFI_MAKS = 20;
JaringanWifi daftarWifi[WIFI_MAKS];
int jumlahWifi = 0;

const int WIFI_BARIS = 4;                          // baris yang terlihat per halaman
const Tombol WL_BATAL  = {388, 2, 88, 40, "Batal"};
const Tombol WL_NAIK   = {8, 270, 64, 46, ""};
const Tombol WL_TURUN  = {80, 270, 64, 46, ""};
const Tombol WL_PINDAI = {152, 270, 156, 46, "Pindai ulang"};
const Tombol WL_MANUAL = {316, 270, 156, 46, "Ketik manual"};

Tombol wlBaris(int i) { return {8, 50 + i * 54, 464, 50, ""}; }

// Ambil hasil pindai: nama kembar digabung (sinyal terkuat), diurutkan dari sinyal terkuat.
void wifiAmbilHasil(int n) {
  jumlahWifi = 0;
  for (int i = 0; i < n; i++) {
    String ssid = WiFi.SSID(i);
    if (ssid.length() == 0) continue;                      // WiFi tersembunyi: pakai "Ketik manual"
    int rssi = WiFi.RSSI(i);
    int ada = -1;
    for (int j = 0; j < jumlahWifi; j++) if (daftarWifi[j].ssid == ssid) ada = j;
    if (ada >= 0) {
      if (rssi > daftarWifi[ada].rssi) daftarWifi[ada].rssi = rssi;
      continue;
    }
    if (jumlahWifi >= WIFI_MAKS) continue;
    daftarWifi[jumlahWifi++] = {ssid, rssi, WiFi.encryptionType(i) != WIFI_AUTH_OPEN};
  }
  WiFi.scanDelete();
  for (int i = 1; i < jumlahWifi; i++) {                   // urutkan: sinyal terkuat di atas
    for (int j = i; j > 0 && daftarWifi[j].rssi > daftarWifi[j - 1].rssi; j--) {
      JaringanWifi t = daftarWifi[j];
      daftarWifi[j] = daftarWifi[j - 1];
      daftarWifi[j - 1] = t;
    }
  }
  Serial.printf("Pindai WiFi: %d jaringan\n", jumlahWifi);
}

void wlGambarPanah(const Tombol& t, bool naik, bool aktif) {
  gambarTombol(t, W_TOMBOL, aktif);
  int cx = t.x + t.w / 2, cy = t.y + t.h / 2;
  uint16_t w = aktif ? W_TEKS : W_GARIS;
  if (naik) tft.fillTriangle(cx - 12, cy + 7, cx + 12, cy + 7, cx, cy - 9, w);
  else      tft.fillTriangle(cx - 12, cy - 7, cx + 12, cy - 7, cx, cy + 9, w);
}

// Gambar daftar (atau pesan "Memindai...") dan tombol naik/turun.
void wlGambarDaftar(int halaman, bool memindai) {
  tft.fillRect(0, 46, 480, 222, W_LATAR);
  int jumlahHalaman = max(1, (jumlahWifi + WIFI_BARIS - 1) / WIFI_BARIS);
  if (memindai) {
    tulis("Memindai WiFi...", 240, 150, F_JUDUL, W_REDUP, MC_DATUM);
  } else if (jumlahWifi == 0) {
    tulis("Tidak ada WiFi ditemukan", 240, 130, F_TEBAL, W_REDUP, MC_DATUM);
    tulis("Pindai ulang, atau ketik nama WiFi", 240, 170, F_KECIL, W_REDUP, MC_DATUM);
  } else {
    for (int i = 0; i < WIFI_BARIS; i++) {
      int n = halaman * WIFI_BARIS + i;
      if (n >= jumlahWifi) break;
      Tombol t = wlBaris(i);
      tft.fillSmoothRoundRect(t.x, t.y, t.w, t.h, 8, W_PANEL, W_LATAR);
      gambarSinyal(t.x + 12, t.y + 38, wifiBarDari(daftarWifi[n].rssi), W_GARIS);
      if (daftarWifi[n].kunci) gambarGembok(t.x + 48, t.y + 16, W_REDUP, W_PANEL);
      tft.setFreeFont(F_BIASA);
      tulis(potong(daftarWifi[n].ssid, 330), t.x + 76, t.y + t.h / 2, F_BIASA, W_TEKS, ML_DATUM);
      tulisTimpa(String(daftarWifi[n].rssi) + " dBm", t.x + t.w - 10, t.y + t.h / 2, 2, W_REDUP, W_PANEL, MR_DATUM, 0);
    }
  }
  wlGambarPanah(WL_NAIK, true, !memindai && halaman > 0);
  wlGambarPanah(WL_TURUN, false, !memindai && halaman < jumlahHalaman - 1);
}

enum PilihanWifi { WIFI_DIPILIH, WIFI_MANUAL, WIFI_BATAL };

// Layar daftar WiFi. Kalau WIFI_DIPILIH, `ssid` dan `kunci` terisi.
PilihanWifi pilihWifi(const String& judul, bool bolehBatal, String& ssid, bool& kunci) {
  uiJudul(judul);
  if (bolehBatal) gambarTombol(WL_BATAL, W_TOMBOL, true, W_PANEL);
  else tulisTimpa("ID " + idAlat, 466, 22, 2, W_REDUP, W_PANEL, MR_DATUM, 0);   // panduan: tampilkan ID alat
  gambarTombol(WL_PINDAI, W_TOMBOL);
  gambarTombol(WL_MANUAL, W_TOMBOL);

  wifiJeda = true;                                         // jangan menyambung ulang selama memindai
  if (!wifiTerhubung()) WiFi.disconnect();                 // percobaan menyambung mengganggu pindai
  int halaman = 0;
  bool memindai = WiFi.scanNetworks(true) == WIFI_SCAN_RUNNING;   // pindai di latar (tidak menunggu)
  if (!memindai) jumlahWifi = 0;
  wlGambarDaftar(halaman, memindai);
  sentuhTerakhir = millis();

  PilihanWifi hasil = WIFI_BATAL;
  while (true) {
    if (terlaluLamaDiam()) break;
    if (memindai) {
      int n = WiFi.scanComplete();
      if (n != WIFI_SCAN_RUNNING) {
        memindai = false;
        if (n >= 0) wifiAmbilHasil(n);
        else jumlahWifi = 0;
        halaman = 0;
        wlGambarDaftar(halaman, false);
      }
    }
    int x, y;
    if (!sentuh(x, y)) continue;
    int jumlahHalaman = max(1, (jumlahWifi + WIFI_BARIS - 1) / WIFI_BARIS);

    if (bolehBatal && kena(WL_BATAL, x, y)) { klik(); hasil = WIFI_BATAL; break; }
    if (kena(WL_MANUAL, x, y)) { klik(); hasil = WIFI_MANUAL; break; }
    if (memindai) continue;
    if (kena(WL_PINDAI, x, y)) {
      klik();
      memindai = WiFi.scanNetworks(true) == WIFI_SCAN_RUNNING;
      wlGambarDaftar(halaman, memindai);
    } else if (kena(WL_NAIK, x, y) && halaman > 0) {
      klik();
      wlGambarDaftar(--halaman, false);
    } else if (kena(WL_TURUN, x, y) && halaman < jumlahHalaman - 1) {
      klik();
      wlGambarDaftar(++halaman, false);
    } else {
      for (int i = 0; i < WIFI_BARIS; i++) {
        int n = halaman * WIFI_BARIS + i;
        if (n < jumlahWifi && y > 46 && y < 266 && kena(wlBaris(i), x, y)) {
          klik();
          ssid = daftarWifi[n].ssid;
          kunci = daftarWifi[n].kunci;
          hasil = WIFI_DIPILIH;
          break;
        }
      }
      if (hasil == WIFI_DIPILIH) break;
    }
  }
  WiFi.scanDelete();
  wifiJeda = false;
  if (!wifiTerhubung()) wifiSambung();                     // sambungkan lagi ke WiFi tersimpan
  return hasil;
}

// ---------- Editor WiFi dan server ----------

// Pilih WiFi lalu isi password. Hasil ditulis ke `c` (belum disimpan). False kalau dibatalkan.
bool aturWifi(Pengaturan& c, const String& judul, bool bolehBatal) {
  while (true) {
    String ssid;
    bool kunci = true;
    PilihanWifi p = pilihWifi(judul, bolehBatal, ssid, kunci);
    if (p == WIFI_BATAL) return false;
    if (p == WIFI_MANUAL) {
      ssid = c.ssid;
      if (!ketik("Nama WiFi (SSID)", ssid, 32)) continue;
      ssid.trim();
      if (ssid.length() == 0) continue;
    }
    String pass = ssid == c.ssid ? c.pass : "";
    if (kunci && !ketik("Password WiFi: " + ssid, pass, 64)) continue;
    if (!kunci) pass = "";
    c.ssid = ssid;
    c.pass = pass;
    wifiJeda = true;                                       // jangan kembali ke WiFi lama selama menyetel
    wifiSambungLatar(ssid, pass);                          // menyambung di latar sambil mengisi langkah berikutnya
    return true;
  }
}

// Isi Base URL. False kalau dibatalkan.
bool aturUrl(Pengaturan& c, const String& judul) {
  String url = c.url.length() > 0 ? c.url : "https://";
  while (true) {
    if (!ketik(judul, url, 120)) return false;
    String rapi = urlRapikan(url);
    if (urlValid(rapi)) {
      c.url = rapi;
      return true;
    }
    layarPesan("Base URL", "URL tidak valid", "Harus diawali http:// atau https://", W_MERAH);
    if (terlaluLamaDiam()) return false;
  }
}

// Isi API key (teks bebas, tidak boleh kosong). False kalau dibatalkan.
bool aturKey(Pengaturan& c, const String& judul) {
  String key = c.key;
  while (true) {
    if (!ketik(judul, key, 64)) return false;
    key.trim();
    if (key.length() > 0) {
      c.key = key;
      return true;
    }
    layarPesan("API key", "API key kosong", "Isi sesuai API key dari aplikasi", W_MERAH);
    if (terlaluLamaDiam()) return false;
  }
}

// ---------- Tes koneksi ----------

enum ModeTes { TES_SAJA, TES_MENU, TES_PANDUAN };
enum HasilTes { TES_KEMBALI, TES_SIMPAN, TES_UBAH };

// Sambungkan ke WiFi di `atur` dan tunggu (maks. WIFI_TES_TIMEOUT_MS). True kalau tersambung.
bool tesSambungWifi() {
  // WiFi yang sama sudah tersambung, atau masih menyambung di latar: cukup tunggu hasilnya.
  // Mulai ulang hanya kalau WiFi-nya beda, percobaan sebelumnya jelas gagal, atau sudah terlalu lama.
  bool sama = wifiSsidDipakai == atur.ssid && wifiPassDipakai == atur.pass;
  if (sama && wifiTerhubung()) return true;
  int s = WiFi.status();
  bool gagal = s == WL_CONNECT_FAILED || s == WL_NO_SSID_AVAIL;
  uint32_t mulai = millis();
  if (!sama || gagal || millis() - wifiCobaTerakhir > WIFI_TES_TIMEOUT_MS) wifiSambung();
  else mulai = wifiCobaTerakhir;                          // lanjutkan waktu tunggu percobaan di latar
  int titik = 0;
  uint32_t animasi = 0;
  while (millis() - mulai < WIFI_TES_TIMEOUT_MS) {
    urusLatar();
    if (wifiTerhubung()) return true;
    if (millis() - animasi > 400) {
      animasi = millis();
      titik = (titik + 1) % 4;
      tulisTimpa("Menyambung ke " + atur.ssid + String("...").substring(0, titik), 20, 80, 2, W_AMBER, W_LATAR, ML_DATUM, 440);
    }
    delay(5);
  }
  return false;
}

// Uji WiFi + GET /ping dengan pengaturan `c` (dipakai sementara), lalu tampilkan hasilnya.
// Kalau "Simpan" ditekan, `c` disimpan ke memori. Kalau tidak, pengaturan lama dikembalikan.
// config dari server yang dites (PIN, judul, zona waktu) hanya dipakai kalau pengaturannya disimpan,
// atau kalau yang dites memang pengaturan tersimpan ("Tes koneksi" dari menu).
HasilTes layarTes(Pengaturan& c, ModeTes mode) {
  Pengaturan lama = atur;
  bool heartbeatTadi = heartbeatDiMenu;
  heartbeatDiMenu = false;                                 // selama tes, `atur` berisi pengaturan sementara
  while (true) {
    atur.ssid = c.ssid; atur.pass = c.pass; atur.url = c.url; atur.key = c.key;
    bool serverOk = false;
    httpPutus();

    uiJudul("Tes koneksi");
    tulisTimpa("ID alat: " + idAlat, 466, 22, 2, W_REDUP, W_PANEL, MR_DATUM, 0);
    tulis("WiFi", 20, 62, F_TEBAL9, W_REDUP, ML_DATUM);
    bool wifiOk = tesSambungWifi();
    String baris1, baris2, baris3;
    uint16_t warna;
    if (!wifiOk) {
      int s = WiFi.status();
      baris1 = "WiFi gagal tersambung";
      baris2 = s == WL_NO_SSID_AVAIL ? "WiFi \"" + c.ssid + "\" tidak ditemukan" :
               s == WL_CONNECT_FAILED ? "Password salah?" : "Cek nama dan password WiFi";
      warna = W_MERAH;
    } else {
      tulisTimpa("Tersambung: " + WiFi.localIP().toString() + "  (" + String(WiFi.RSSI()) + " dBm)",
                 20, 80, 2, W_HIJAU, W_LATAR, ML_DATUM, 440);
      tulis("Server", 20, 112, F_TEBAL9, W_REDUP, ML_DATUM);
      tulisTimpa("Memeriksa " + c.url + "/ping ...", 20, 130, 2, W_AMBER, W_LATAR, ML_DATUM, 440);
      HasilPing p = apiPing(mode == TES_SAJA);
      serverOk = p.ok;
      baris1 = p.ok ? "Berhasil terhubung" : "Server gagal";
      baris2 = p.pesan;
      if (p.ok && jamValid()) baris3 = "Jam alat: " + jamMenit();
      warna = p.ok ? W_HIJAU : W_MERAH;
    }

    tft.fillRect(0, 50, 480, 216, W_LATAR);                // ganti keterangan proses dengan hasil
    tulisTengahMuat(baris1, 70, F_JUDUL, F_TEBAL, F_TEBAL9, warna);
    tulisTengahMuat(baris2, 124, F_BIASA, F_KECIL, F_KECIL, W_TEKS);
    tulisTengahMuat(baris3, 164, F_KECIL, F_KECIL, F_KECIL, W_REDUP);
    tft.setTextFont(2);
    tulisTimpa(potong(c.url, 440, 2), 240, 204, 2, W_REDUP, W_LATAR, TC_DATUM, 0);

    // Tombol sesuai dari mana tes dibuka
    const Tombol KIRI  = {10, 250, 148, 62, mode == TES_PANDUAN ? "Ubah" : mode == TES_MENU ? "Batal" : "Kembali"};
    const Tombol ULANG = {166, 250, 148, 62, "Tes ulang"};
    const Tombol SIMPAN = {322, 250, 148, 62, "Simpan"};
    gambarTombol(KIRI, W_TOMBOL);
    gambarTombol(ULANG, W_TOMBOL);
    if (mode != TES_SAJA) gambarTombol(SIMPAN, W_HIJAU);
    bip(wifiOk && warna == W_HIJAU ? 1 : 2, 80);

    HasilTes hasil = TES_KEMBALI;
    bool ulang = false;
    sentuhTerakhir = millis();
    while (!terlaluLamaDiam()) {
      int x, y;
      if (!sentuh(x, y)) continue;
      if (kena(KIRI, x, y)) { klik(); hasil = mode == TES_PANDUAN ? TES_UBAH : TES_KEMBALI; break; }
      if (kena(ULANG, x, y)) { klik(); ulang = true; break; }
      if (mode != TES_SAJA && kena(SIMPAN, x, y)) { klik(); hasil = TES_SIMPAN; break; }
    }
    if (ulang) continue;

    wifiJeda = false;                                      // selesai menyetel: sambung ulang otomatis aktif lagi
    if (hasil == TES_SIMPAN) {
      aturSimpanKoneksi();
      if (serverOk) apiPing();                             // sekarang baru jam dan config server ini dipakai
      heartbeatSegera = true;
      pengumumanSegera = true;                             // server mungkin baru: ambil pengumumannya
    } else {                                               // tidak disimpan: kembali ke pengaturan koneksi lama
      bool wifiBeda = lama.ssid != atur.ssid || lama.pass != atur.pass;
      atur.ssid = lama.ssid; atur.pass = lama.pass; atur.url = lama.url; atur.key = lama.key;
      httpPutus();
      if (wifiBeda) wifiSambung();
      heartbeatSegera = true;                              // perbarui status server dengan pengaturan lama
    }
    heartbeatDiMenu = heartbeatTadi;
    return hasil;
  }
}

// ---------- Panduan pertama kali ----------

// Dibuka saat WiFi / URL / API key belum diisi. Selesai setelah "Simpan" ditekan.
void panduanPenyetelan() {
  batasDiamAktif = false;                                  // panduan tidak ditutup sendiri
  Pengaturan c = atur;
  int langkah = 0;
  while (true) {
    if (langkah == 0) {
      if (aturWifi(c, "Langkah 1/4: Pilih WiFi", false)) langkah = 1;
    } else if (langkah == 1) {
      langkah = aturUrl(c, "Langkah 2/4: Base URL") ? 2 : 0;
    } else if (langkah == 2) {
      langkah = aturKey(c, "Langkah 3/4: API key") ? 3 : 1;
    } else {
      HasilTes h = layarTes(c, TES_PANDUAN);
      if (h == TES_SIMPAN) {
        layarPesan("Selesai", "Pengaturan disimpan", "Alat siap dipakai", W_HIJAU);
        return;
      }
      langkah = 0;                                         // "Ubah": mulai lagi dari WiFi (isian lama tetap ada)
    }
  }
}
