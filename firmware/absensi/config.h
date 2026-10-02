// Pengaturan tetap alat absensi: pin, waktu, versi, dan nilai bawaan pabrik.
// Yang diisi di sini hanya ID alat. WiFi, Base URL, API key, PIN, dan judul diisi lewat layar sentuh
// (disimpan di memori alat, lihat pengaturan.h).
#pragma once

// ---------- ID alat (ubah untuk SETIAP alat sebelum upload) ----------
// Tampil di layar dan dikirim ke server sebagai X-Device-ID. Harus berbeda di tiap alat.
// Kosongkan ("") kalau ingin dibuat otomatis dari MAC, contoh "ABS-79438C".
const char ID_ALAT[] = "ABS-001";

// ---------- Build untuk update jarak jauh / OTA (README 2.7) ----------
// Hanya untuk pengguna Arduino IDE yang membuat file .bin lewat "Export Compiled Binary":
// hapus "//" di depan #define di bawah SEBELUM export, lalu pasang lagi "//" SESUDAHNYA.
// Selama aktif, ID_ALAT di atas tidak dipakai: tiap alat tetap memakai ID yang sudah tersimpan di alat.
// Pengguna ./upload.sh -b tidak perlu mengubah baris ini (skrip mengaktifkannya sendiri).
// #define OTA_BUILD

// ---------- Versi & nilai bawaan pabrik ----------
const char VERSI_FIRMWARE[] = "1.9.1";          // naikkan setiap membuat firmware baru (wajib untuk OTA)
const char VERSI_SPEK[]     = "1";              // versi spesifikasi API (header X-Spec-Version)
const char PIN_BAWAAN[]     = "2026";           // PIN menu Pengaturan sebelum diganti
const char JUDUL_BAWAAN[]   = "Absensi RFID";   // judul layar utama sebelum diganti
const int  OFFSET_BAWAAN    = 7 * 60;           // zona waktu bawaan dalam menit (+07:00 = WIB)
const char NTP_SERVER[]     = "pool.ntp.org";   // cadangan jam kalau server tidak mengirim server_time

// ---------- Waktu (milidetik) ----------
// Batas waktu di bawah ini berlaku untuk SELURUH request: sambung + TLS + tunggu jawaban (+ coba ulang).
const uint32_t HTTP_TIMEOUT_MS       = 8000;    // GET /ping (saat menyala dan "Tes koneksi")
const uint32_t TAP_TIMEOUT_MS        = 3000;    // POST /tap langsung: lewat dari ini tap disimpan di antrean
const uint32_t LATAR_TIMEOUT_MS      = 3000;    // request latar: heartbeat, pengumuman, kirim antrean
const uint32_t HTTP_ULANG_MIN_MS     = 1000;    // sambungan lama gagal: coba ulang hanya kalau sisa waktu masih segini
const uint32_t FOTO_TIMEOUT_MS       = 3000;    // batas unduh foto
const uint32_t DIAM_LATAR_MS         = 5000;    // request latar hanya dikirim kalau tidak ada kartu / sentuhan selama ini
const uint32_t DNS_ULANG_MS          = 600000;  // alamat IP server dicari ulang (DNS) paling cepat tiap 10 menit
const uint32_t HEARTBEAT_INTERVAL_MS = 60000;   // heartbeat tiap 1 menit
const uint32_t ANTREAN_JEDA_MS       = 1000;    // jeda antar kiriman antrean
const uint32_t ANTREAN_ULANG_MS      = 30000;   // antrean gagal terkirim: coba lagi setelah ini
const uint32_t HASIL_TAMPIL_MS       = 4000;    // lama layar hasil tap
const uint32_t MENU_TIMEOUT_MS       = 60000;   // menu Pengaturan ditutup kalau tidak disentuh
const uint32_t KARTU_JEDA_MS         = 3000;    // kartu yang sama diabaikan selama ini
const uint32_t WIFI_BOOT_TIMEOUT_MS  = 15000;   // batas tunggu WiFi saat menyala
const uint32_t WIFI_TES_TIMEOUT_MS   = 15000;   // batas tunggu WiFi saat "Tes koneksi"
const uint32_t PIN_KUNCI_MS          = 60000;   // keypad dikunci setelah PIN salah berkali-kali
const int      PIN_SALAH_MAKS        = 5;       // berapa kali PIN boleh salah
const uint32_t RESET_TANYA_MS        = 15000;   // layar "Reset pabrik?" batal sendiri
const uint32_t PENGUMUMAN_CEK_MS     = 600000;  // pengumuman diambil ulang paling lambat tiap 10 menit
const uint32_t PENGUMUMAN_ULANG_MS   = 60000;   // gagal karena jaringan: coba lagi setelah ini (jeda dilipatgandakan
                                                // tiap gagal lagi, paling lama PENGUMUMAN_CEK_MS)
const uint32_t BOOT_AMAN_MS          = 5000;    // alat menyala lebih lama dari ini = bukan tekan EN beruntun
const int      BOOT_RESET_KALI       = 3;       // tekan EN sebanyak ini dalam 5 detik = tawaran reset pabrik

// ---------- Batas data ----------
const int    PIN_MIN_ANGKA  = 4;                // PIN menu Pengaturan: 4 sampai 8 angka
const int    PIN_MAKS_ANGKA = 8;
const int    JUDUL_UTAMA_MAKS = 30;             // judul layar utama (config.title) paling banyak 30 huruf
const int    ANTREAN_MAKS   = 200;              // tap yang disimpan saat server tidak bisa dihubungi
const size_t FOTO_MAKS_BYTE = 30 * 1024;        // foto paling besar 30 KB
const int    FOTO_MAKS_PX   = 160;              // foto paling besar 160 x 160 piksel

// ---------- Pengumuman / screensaver (doc/spesifikasi-api.md bagian 8) ----------
const int PENGUMUMAN_MAKS   = 10;               // pengumuman paling banyak, sisanya diabaikan
const int JUDUL_MAKS_HURUF  = 40;               // judul lebih panjang dipotong
const int ISI_MAKS_HURUF    = 160;              // deskripsi lebih panjang dipotong
const int INTERVAL_BAWAAN   = 3;                // detik tiap pengumuman tampil (boleh 2-60)
const int DIAM_BAWAAN       = 30;               // screensaver muncul setelah diam sekian detik (boleh 5-600)

// ---------- Lampu layar (doc/spesifikasi-api.md bagian 6, config.dim_after / dim_level) ----------
const int REDUP_DETIK_BAWAAN  = 60;             // layar meredup setelah diam sekian detik (0 = tidak pernah, boleh 10-3600)
const int REDUP_PERSEN_BAWAAN = 20;             // kecerahan saat redup dalam persen (0 = mati, boleh 0-100)

// ---------- Perawatan (perawatan.h) ----------
const uint32_t WATCHDOG_MS          = 60000;    // program macet selama ini -> restart otomatis
const char     RESTART_BAWAAN[]     = "03:00";  // restart harian (jam alat); "" = tidak restart otomatis.
                                                // Diganti per alat lewat config.restart_at dari server
const int      RESTART_JENDELA_MENIT = 30;      // restart boleh terjadi dalam 30 menit sejak jadwal (menunggu alat diam)
const uint32_t RESTART_DIAM_MS      = 120000;   // restart hanya kalau tidak ada kartu / sentuhan selama 2 menit
const uint32_t RESTART_MIN_NYALA_S  = 3600;     // dan alat sudah menyala minimal 1 jam

// ---------- Update firmware jarak jauh / OTA (ota.h) ----------
const uint32_t OTA_DIAM_MS          = 60000;    // update hanya kalau tidak ada kartu / sentuhan selama 1 menit
const uint32_t OTA_ULANG_MS         = 1800000;  // unduh gagal: coba lagi setelah 30 menit
const uint32_t OTA_DATA_TIMEOUT_MS  = 20000;    // unduhan dianggap putus kalau tidak ada data selama ini
const int      OTA_ULANG_MAKS       = 3;        // firmware baru terputus listrik / EN sebelum sehat: dicoba ulang
                                                // sampai 3 kali, baru dianggap gagal
const uint32_t OTA_SEHAT_S          = 300;      // firmware baru dianggap sehat setelah menyala 5 menit tanpa
                                                // crash (atau lebih cepat: 1 menit + server tersambung).
                                                // Kalau crash sebelum itu, alat kembali ke firmware lama.

// ---------- Kode error (galat.h) ----------
const uint32_t WIFI_DHCP_MS         = 10000;    // WiFi tersambung tapi belum dapat alamat IP selama ini -> E13
const uint32_t JAM_TUNGGU_S         = 120;      // jam belum sinkron setelah menyala selama ini -> E31

// ---------- Lain-lain ----------
const bool BUZZER_AKTIF = true;                 // false = alat tidak berbunyi

// ---------- Pin (sesuai kabel; layar ada di tft_setup.h) ----------
const int PIN_TOMBOL_BOOT = 0;

// Touch XPT2046, jalur sendiri (bit-bang)
const int T_CLK = 22, T_DIN = 21, T_OUT = 19, T_CS = 15;

// RFID RC522, SPI kedua (HSPI)
const int RFID_SCK = 14, RFID_MOSI = 13, RFID_MISO = 35, RFID_SS = 33, RFID_RST = 32;

// Buzzer aktif (HIGH = bunyi) dan LED RGB katoda bersama
const int PIN_BUZZER = 17;
const int PIN_LED_R = 25, PIN_LED_G = 26, PIN_LED_B = 27;

// Lampu latar LCD (pin LED di LCD), PWM untuk meredup. Pin lain layar ada di tft_setup.h.
const int PIN_LAMPU_LCD = 2;
