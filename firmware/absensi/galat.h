// Kode error singkat di layar, supaya pengguna cukup menyebut kodenya ke teknisi (daftar lengkap
// dan cara mengatasinya ada di README bagian "Kode error di layar"). Juga dikirim ke server lewat
// heartbeat (raw.error) kalau server masih bisa dihubungi.
//
//   E10 WiFi tidak ditemukan        E20 Server tidak bisa dihubungi   E30 Pembaca RFID tidak terdeteksi
//   E11 Password WiFi salah         E21 API key salah (401)           E31 Jam belum sinkron
//   E12 WiFi gagal tersambung       E22 Alat ditolak server (403)
//   E13 WiFi tidak memberi IP       E23 Alamat API salah (404 / Base URL)
//                                   E24 Server error (5xx / sibuk)
//                                   E25 Balasan server tidak valid
//                                   E26 Server terlalu lama menjawab
#pragma once

// Kode error untuk hasil request ke server (kode HTTP, atau negatif = gagal tersambung).
const char* kodeGalatServer(int kode) {
  if (kode == KODE_OFFLINE) return "";                     // WiFi putus: kodenya dari galatWifi()
  if (kode == KODE_URL_SALAH || kode == 404 || (kode >= 300 && kode < 400)) return "E23";
  if (kode == HTTPC_ERROR_READ_TIMEOUT) return "E26";
  if (kode <= 0) return "E20";
  if (kode == 401) return "E21";
  if (kode == 403) return "E22";
  if (kode >= 500 || kode == 429) return "E24";
  return "E25";                                            // 2xx tapi bukan JSON / ok=false, atau 4xx lain
}

// Kode error WiFi dari alasan putus terakhir. "" = tersambung, belum diatur, atau masih mencoba.
const char* kodeGalatWifi() {
  if (atur.ssid.length() == 0 || wifiTerhubung()) return "";
  if (wifiTanpaIpSejak && millis() - wifiTanpaIpSejak > WIFI_DHCP_MS) return "E13";
  switch (wifiAlasanPutus) {
    case 0:
      return "";
    case WIFI_REASON_NO_AP_FOUND:
    case WIFI_REASON_NO_AP_FOUND_W_COMPATIBLE_SECURITY:
    case WIFI_REASON_NO_AP_FOUND_IN_AUTHMODE_THRESHOLD:
    case WIFI_REASON_NO_AP_FOUND_IN_RSSI_THRESHOLD:
      return "E10";
    case WIFI_REASON_AUTH_FAIL:
    case WIFI_REASON_4WAY_HANDSHAKE_TIMEOUT:
    case WIFI_REASON_HANDSHAKE_TIMEOUT:
      return "E11";
    default:
      return "E12";
  }
}

// Keterangan singkat sebuah kode error.
const char* teksKodeGalat(const String& kode) {
  if (kode == "E10") return "WiFi tidak ditemukan";
  if (kode == "E11") return "Password WiFi salah";
  if (kode == "E12") return "WiFi gagal tersambung";
  if (kode == "E13") return "WiFi tidak memberi alamat IP";
  if (kode == "E20") return "Server tidak bisa dihubungi";
  if (kode == "E21") return "API key salah";
  if (kode == "E22") return "Alat ditolak server";
  if (kode == "E23") return "Alamat API salah, cek Base URL";
  if (kode == "E24") return "Server sedang error / sibuk";
  if (kode == "E25") return "Balasan server tidak valid";
  if (kode == "E26") return "Server terlalu lama menjawab";
  if (kode == "E30") return "Pembaca RFID tidak terdeteksi";
  if (kode == "E31") return "Jam belum sinkron";
  return "";
}

// "E11 Password WiFi salah"
String galatTeks(const String& kode) { return kode + " " + teksKodeGalat(kode); }

// Error yang sedang terjadi, yang paling penting dulu: RFID, WiFi, server, jam. "" = tidak ada.
String galatSekarang() {
  if (!rfidAda) return "E30";
  String wifi = kodeGalatWifi();
  if (wifi.length() > 0) return wifi;
  if (wifiTerhubung() && statusServer == SERVER_GAGAL) return kodeGalatServer(kodeServerTerakhir);
  if (!jamValid() && lamaNyalaDetik() > JAM_TUNGGU_S) return "E31";
  return "";
}
