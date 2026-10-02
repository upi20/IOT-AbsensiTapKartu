// =============================================================================
// Contoh server Absensi RFID Terintegrasi — Node.js 18+, hanya modul bawaan.
// Tidak perlu `npm install`.
//
// Jalankan:   node server.js            (port lain: PORT=9000 node server.js)
// Di alat:    Pengaturan → Server → Base URL = http://<ip-laptop>:8080
//                                   API key  = "api_key" di pengaturan (awal: ganti-dengan-kunci-anda)
// Spesifikasi API alat: ../../doc/spesifikasi-api.md
//
// Server ini punya dua bagian:
//   (A) API alat  — dipanggil alat, header X-API-Key + X-Device-ID:
//         GET /ping, POST /tap, POST /heartbeat, GET /announcements, GET /firmware/{versi},
//         POST /check-in & POST /check-out (mode absen "Pilih Datang/Pulang" di alat)
//   (B) API admin — dipanggil aplikasi/admin Anda, header X-Admin-Key, semua JSON:
//         /admin/settings        pengaturan (judul, layar redup, api_key, jeda dobel, ...)
//         /admin/members         anggota (pemilik kartu)
//         /admin/unknown-cards   kartu yang di-tap tetapi belum terdaftar
//         /admin/report          rekap masuk/pulang per hari (JSON atau CSV)
//         /admin/attendances     semua tap pada satu tanggal
//         /admin/devices         daftar alat + kesehatan + PIN/restart/target firmware per alat
//         /admin/firmware        unggah & jadwalkan update firmware jarak jauh
//         /admin/announcements   pengumuman screensaver alat
//       Contoh: curl -H "X-Admin-Key: ganti-kunci-admin" http://localhost:8080/admin/devices
// Semua path boleh diberi awalan, mis. /api/absensi/tap atau /api/absensi/admin/settings.
//
// Data disimpan di data.json (dibuat otomatis). File ini dibaca ulang setiap permintaan,
// jadi Anda bisa mengeditnya tanpa restart server. data.json versi lama otomatis dilengkapi
// field barunya. File firmware hasil unggah disimpan di folder firmware/ di samping file ini.
// =============================================================================

const http = require('http');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ADMIN_KEY = 'ganti-kunci-admin'; // kunci API admin (header X-Admin-Key). GANTI sebelum dipakai sungguhan!
const ZONA_JAM = 7;                    // WIB = 7, WITA = 8, WIT = 9
const PORT = Number(process.env.PORT) || 8080;
const FILE_DATA = path.join(__dirname, 'data.json');
const DIR_FIRMWARE = path.join(__dirname, 'firmware'); // firmware/<versi>.bin
const UKURAN_FIRMWARE_MAKS = 1966080;  // byte, slot OTA partisi min_spiffs
const BATAS_BODY_JSON = 1024 * 1024;   // body JSON lebih besar dari ini ditolak (413)

// Pengaturan awal. Setelah data.json dibuat, ubah lewat PUT /admin/settings (atau edit data.json).
const PENGATURAN_AWAL = {
  api_key: 'ganti-dengan-kunci-anda', // API key yang diisi di alat (X-API-Key)
  title: 'Aplikasi Contoh',          // judul di layar utama alat (config.title, maks. 30 karakter)
  dim_after: 60,                     // layar meredup setelah diam sekian detik (0 = tidak pernah, atau 10–3600)
  dim_level: 20,                     // kecerahan layar saat redup, persen (0–100)
  screensaver_interval: 3,           // detik per pengumuman di screensaver (2–60)
  screensaver_idle: 30,              // screensaver muncul setelah alat diam sekian detik (5–600)
  duplicate_window: 60,              // detik: tap ulang dalam jeda ini dianggap "duplicate" (0–3600)
  default_restart_at: '03:00',       // jam restart harian alat ("HH:MM"), "" = tidak restart otomatis
};

// Kode ikon pengumuman yang dikenal alat.
const IKON = ['info', 'pengumuman', 'kalender', 'jam', 'peringatan', 'rapat', 'libur', 'selamat', 'kesehatan', 'buku'];

// Arti kode error di layar alat (raw.error di heartbeat).
const ARTI_ERROR = {
  E10: 'WiFi tidak ditemukan', E11: 'Password WiFi salah', E12: 'WiFi gagal tersambung',
  E13: 'WiFi tidak memberi alamat IP', E20: 'Server tidak bisa dihubungi', E21: 'API key salah',
  E22: 'Alat ditolak server', E23: 'Alamat API salah', E24: 'Server error / sibuk',
  E25: 'Balasan server tidak valid', E26: 'Server terlalu lama menjawab',
  E30: 'Pembaca RFID tidak terdeteksi', E31: 'Jam belum sinkron',
};

// Arti raw.reset_reason (alasan alat menyala ulang).
const ALASAN_RESET = {
  poweron: 'baru dinyalakan / tombol EN', external: 'reset dari luar', software: 'restart oleh program',
  panic: 'program error', watchdog: 'watchdog (program macet)', brownout: 'listrik turun (brownout)',
  deepsleep: 'bangun dari tidur', other: 'lainnya',
};

// =============================================================================
// ATURAN BISNIS — bagian inilah yang biasanya Anda ubah sesuai kebutuhan.
// Hasilnya langsung menjadi respons /tap, /check-in, dan /check-out (lihat bagian 4 spesifikasi).
//   data  = isi data.json, rfid = nomor kartu, waktu = jam tap (ISO 8601, mis. "2026-09-30T07:45:12+07:00")
//   mode  = null        → POST /tap       (mode absen "Otomatis": server yang menentukan masuk/pulang)
//           'check_in'  → POST /check-in  (mode "Pilih Datang/Pulang", petugas memilih DATANG di alat)
//           'check_out' → POST /check-out (mode "Pilih Datang/Pulang", petugas memilih PULANG di alat)
// =============================================================================
function tentukanStatus(data, rfid, waktu, mode = null) {
  const k = data.karyawan.find((x) => x.rfid === rfid);
  if (!k) {
    return { ok: false, status: 'unknown', message: 'Kartu tidak terdaftar', time: jamMenit(waktu) };
  }

  const dasar = { name: k.nama, time: jamMenit(waktu), photo_url: k.foto_url || null };

  if (!k.aktif) {
    return { ok: false, status: 'rejected', message: 'Kartu nonaktif', ...dasar };
  }

  // Tap yang diterima (masuk/pulang) pada hari yang sama, diurutkan dari yang paling awal.
  // Tap dari /tap, /check-in, dan /check-out tersimpan di daftar yang sama (rekap tetap satu).
  const hariIni = data.absensi
    .filter((a) => a.rfid === rfid && ['check_in', 'check_out'].includes(a.jenis))
    .filter((a) => tanggal(a.waktu) === tanggal(waktu))
    .sort((x, y) => x.waktu.localeCompare(y.waktu));
  const masuk = hariIni.find((a) => a.jenis === 'check_in'); // check_in pertama hari itu
  const dalamJeda = (a) => Math.abs(Date.parse(waktu) - Date.parse(a.waktu)) < data.pengaturan.duplicate_window * 1000;

  // a) Mode DATANG (/check-in): satu kali per hari. Sudah datang → "duplicate" dengan jam datang pertama.
  if (mode === 'check_in') {
    if (masuk) return { ok: true, status: 'duplicate', message: 'Sudah absen datang', ...dasar, time: jamMenit(masuk.waktu) };
    return { ok: true, status: 'check_in', message: 'Selamat datang', ...dasar };
  }

  // b) Mode PULANG (/check-out): boleh berkali-kali (yang terakhir dipakai rekap), kecuali dalam jeda tap ganda.
  if (mode === 'check_out') {
    const ganda = [...hariIni].reverse().find((a) => a.jenis === 'check_out' && dalamJeda(a));
    if (ganda) return { ok: true, status: 'duplicate', message: 'Sudah tercatat', ...dasar, time: jamMenit(ganda.waktu) };
    return { ok: true, status: 'check_out', message: 'Hati-hati di jalan', ...dasar,
             info: [masuk ? 'Masuk tadi ' + jamMenit(masuk.waktu) : 'Belum absen datang hari ini'] };
  }

  // c) Otomatis (/tap): tap pertama hari itu = masuk, tap berikutnya = pulang.
  if (hariIni.length === 0) {
    return { ok: true, status: 'check_in', message: 'Selamat datang', ...dasar };
  }
  const terakhir = hariIni[hariIni.length - 1];
  if (dalamJeda(terakhir)) {
    // "time" = jam tap yang sudah tercatat sebelumnya
    return { ok: true, status: 'duplicate', message: 'Sudah tercatat', ...dasar, time: jamMenit(terakhir.waktu) };
  }
  // Contoh "info": maks. 2 baris, masing-masing ±40 karakter.
  return { ok: true, status: 'check_out', message: 'Hati-hati di jalan', ...dasar,
           info: ['Masuk tadi ' + jamMenit(hariIni[0].waktu)] };
}

// ---------- Penyimpanan (file JSON) -------------------------------------------
// Isi data.json:
//   pengaturan     { api_key, title, dim_after, ... }           (lihat PENGATURAN_AWAL)
//   karyawan       [{ rfid, nama, foto_url, aktif }]             anggota pemilik kartu
//   absensi        [{ id, tap_id, rfid, device_id, jenis, waktu, queued, raw_json, hasil }]
//   alat           { device_id: { name, last_seen_at, firmware, ..., pin, restart_at, firmware_target,
//                                 tap_mode, reported_tap_mode, tap_select, events } }
//   firmware       [{ version, size, md5, notes, uploaded_at }]  file di firmware/<version>.bin
//   pengumuman     [{ id, title, description, icon, active, order }]
//   pengumuman_rev "2-1759212345"  (berubah tiap pengumuman diubah → config.announcements_rev)
//   id_terakhir    { absensi, pengumuman }  penghitung id

function muat() {
  let data;
  if (fs.existsSync(FILE_DATA)) {
    data = JSON.parse(fs.readFileSync(FILE_DATA, 'utf8'));
  } else {
    // Contoh data awal. Nomor kartu (rfid) berupa teks 10 digit.
    data = {
      karyawan: [
        { rfid: '0218893066', nama: 'Budi Santoso', foto_url: null, aktif: true },
        { rfid: '0012345678', nama: 'Siti Aminah', foto_url: null, aktif: true },
        { rfid: '0055555555', nama: 'Rina Kurnia', foto_url: null, aktif: false }, // contoh kartu nonaktif
      ],
    };
  }
  if (migrasi(data)) simpan(data);
  return data;
}

function simpan(data) {
  fs.writeFileSync(FILE_DATA, JSON.stringify(data, null, 2));
}

// Isi awal satu alat. Field yang belum diketahui = null.
function alatBaru() {
  return {
    name: null,            // nama bebas dari admin, mis. "Lobi depan"
    last_seen_at: null,    // terakhir ada request dari alat ini
    heartbeat_at: null,    // waktu heartbeat terakhir (untuk mendeteksi restart)
    firmware: null, ip: null, rssi: null, wifi_ssid: null,
    uptime_s: null, reset_reason: null, rfid_ok: null, queue: null,
    free_heap: null, min_free_heap: null, error: null, ota_failed: null,
    pin: null,             // PIN menu Pengaturan khusus alat ini (null = tidak dikirim)
    restart_at: null,      // null = pakai default_restart_at, "" = mati, "HH:MM"
    firmware_target: null, // versi firmware yang harus dipasang (null = tidak update)
    tap_mode: null,          // mode absen dari server: null = ikuti pengaturan di alat, "auto", "select"
    reported_tap_mode: null, // mode absen yang sedang dipakai alat (raw.tap_mode di heartbeat)
    tap_select: null,        // pilihan DATANG/PULANG di alat: "check_in" / "check_out" / null
    events: [],            // riwayat: boot, crash, firmware, ota_failed (maks. 200)
  };
}

// Migrasi ringan: melengkapi data.json lama dengan field baru. true = ada yang berubah.
function migrasi(data) {
  const sebelum = JSON.stringify(data);
  data.pengaturan = { ...PENGATURAN_AWAL, ...(data.pengaturan || {}) };
  data.karyawan ??= [];
  data.absensi ??= [];
  data.alat ??= {};
  data.firmware ??= [];
  data.id_terakhir ??= {};

  // Tap lama belum punya id → beri nomor urut.
  const id = data.id_terakhir;
  id.absensi = Math.max(id.absensi || 0, ...data.absensi.map((a) => a.id || 0));
  for (const a of data.absensi) if (!a.id) a.id = ++id.absensi;

  // Pengumuman contoh (hanya kalau belum pernah ada daftar pengumuman).
  if (!Array.isArray(data.pengumuman)) {
    data.pengumuman = [
      { id: 1, title: 'Rapat Guru', description: 'Hari ini pukul 13.00 di aula lantai 2.', icon: 'rapat', active: true, order: 0 },
      { id: 2, title: 'Libur Nasional', description: 'Kamis, 2 Oktober 2026 kantor tutup.', icon: 'libur', active: true, order: 0 },
    ];
  }
  id.pengumuman = Math.max(id.pengumuman || 0, ...data.pengumuman.map((p) => p.id || 0));
  if (typeof data.pengumuman_rev !== 'string') gantiRev(data);

  // Alat lama: { terakhir_terlihat, firmware, ip, rssi, raw_json } → format baru.
  for (const [devId, lama] of Object.entries(data.alat)) {
    const a = { ...alatBaru(), ...lama };
    if (lama.terakhir_terlihat && !a.last_seen_at) a.last_seen_at = lama.terakhir_terlihat;
    delete a.terakhir_terlihat;
    delete a.raw_json;
    data.alat[devId] = a;
  }
  return JSON.stringify(data) !== sebelum;
}

// ---------- Fungsi bantu waktu ------------------------------------------------

// Milidetik → "2026-09-30T07:45:12+07:00" (waktu lokal kantor, ISO 8601).
function isoLokal(ms) {
  return new Date(ms + ZONA_JAM * 3600e3).toISOString().slice(0, 19) + '+0' + ZONA_JAM + ':00';
}
const jamMenit = (iso) => iso.slice(11, 16); // "HH:MM"
const tanggal = (iso) => iso.slice(0, 10);   // "YYYY-MM-DD"
const hariIniLokal = () => tanggal(isoLokal(Date.now()));

// "YYYY-MM-DD" yang benar-benar ada di kalender?
function tanggalValid(t) {
  return typeof t === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(t) &&
         !isNaN(Date.parse(t)) && new Date(Date.parse(t)).toISOString().slice(0, 10) === t;
}

// ---------- Fungsi bantu lain -------------------------------------------------

const ada = (obj, kunci) => Object.hasOwn(obj, kunci);
const panjang = (teks) => [...teks].length;                      // jumlah huruf (bukan byte)
const jamValid = (v) => typeof v === 'string' && /^([01]\d|2[0-3]):[0-5]\d$/.test(v);
const angkaAtauNull = (v) => (typeof v === 'number' && Number.isFinite(v) ? v : null);
const teksAtauNull = (v) => (typeof v === 'string' && v !== '' ? v : null);
const objek = (v) => (v && typeof v === 'object' && !Array.isArray(v) ? v : null);

// Bentuk respons. tangani*() mengembalikan salah satu:
//   { kode, json }              → JSON
//   { kode, csv, namaFile }     → text/csv
//   { kode, berkas: {...} }     → file biner (unduhan firmware)
const jawab = (kode, json) => ({ kode, json });

// Validasi gagal → 422 dengan daftar galat per field. message = galat pertama.
function gagalValidasi(errors) {
  return jawab(422, { ok: false, message: Object.values(errors)[0], errors });
}

// Penanda versi daftar pengumuman: "<jumlah aktif>-<unix time>", supaya alat tahu ada perubahan
// (lihat config.announcements_rev). Angka waktunya selalu naik (minimal +1 dari rev sebelumnya),
// jadi beberapa perubahan dalam detik yang sama tetap menghasilkan rev yang belum pernah dipakai.
function gantiRev(data) {
  const aktif = data.pengumuman.filter((p) => p.active).length;
  const sebelumnya = Number(String(data.pengumuman_rev || '').split('-')[1]) || 0;
  const detik = Math.max(Math.floor(Date.now() / 1000), sebelumnya + 1);
  data.pengumuman_rev = `${aktif}-${detik}`;
}

// ---------- Alat: pendaftaran, config, kesehatan ------------------------------

// Tambah catatan riwayat alat (maks. 200, yang terlama dibuang).
function catatEvent(a, type, message, details = null) {
  a.events.push({ type, message, details, created_at: isoLokal(Date.now()) });
  if (a.events.length > 200) a.events.splice(0, a.events.length - 200);
}

// Setiap request alat: daftarkan alat (kalau baru) dan catat "terakhir terlihat",
// plus firmware/ip/rssi/wifi_ssid kalau dikirim di body atau body.raw.
function daftarkanAlat(data, deviceId, body = {}) {
  const a = (data.alat[deviceId] ??= alatBaru());
  const raw = objek(body.raw) || {};
  a.last_seen_at = isoLokal(Date.now());

  const firmware = teksAtauNull(body.firmware) || teksAtauNull(raw.firmware);
  if (firmware) {
    if (a.firmware && a.firmware !== firmware) {
      catatEvent(a, 'firmware', `Firmware ${a.firmware} -> ${firmware}`, { from: a.firmware, to: firmware });
    }
    a.firmware = firmware;
  }
  for (const kunci of ['ip', 'wifi_ssid']) {
    const v = teksAtauNull(body[kunci]) || teksAtauNull(raw[kunci]);
    if (v) a[kunci] = v;
  }
  const rssi = angkaAtauNull(body.rssi) ?? angkaAtauNull(raw.rssi);
  if (rssi !== null) a.rssi = rssi;
  return a;
}

// Jam restart harian yang berlaku untuk alat ini.
function restartEfektif(data, a) {
  return a.restart_at === null || a.restart_at === undefined ? data.pengaturan.default_restart_at : a.restart_at;
}

// Rilis firmware dengan versi tertentu (atau undefined).
const cariRilis = (data, versi) => data.firmware.find((r) => r.version === versi);

// Pengaturan jarak jauh yang dikirim ke alat lewat /ping dan /heartbeat (bagian 6 spesifikasi).
// base = path request tanpa segmen terakhir ("/api/absensi/ping" → "/api/absensi"), untuk URL firmware.
function config(data, deviceId, req, base) {
  const s = data.pengaturan;
  const a = data.alat[deviceId];
  const c = {
    title: s.title,
    dim_after: s.dim_after,
    dim_level: s.dim_level,
    announcements_rev: data.pengumuman_rev, // berubah → alat memanggil ulang GET /announcements
    restart_at: restartEfektif(data, a),    // "" = tidak restart otomatis
  };
  if (a.pin) c.pin = a.pin; // PIN khusus alat ini (hanya kalau diatur)
  if (a.tap_mode) c.tap_mode = a.tap_mode; // mode absen "auto"/"select" (hanya kalau diatur dari server)

  // Update firmware: hanya kalau alat dijadwalkan, versinya beda, dan versi itu belum pernah gagal di alat ini.
  const rilis = a.firmware_target && cariRilis(data, a.firmware_target);
  if (rilis && rilis.version !== a.firmware && rilis.version !== a.ota_failed) {
    const skema = req.headers['x-forwarded-proto'] || (req.socket.encrypted ? 'https' : 'http');
    c.firmware_update = {
      version: rilis.version,
      url: `${skema}://${req.headers.host}${base}/firmware/${rilis.version}`,
      size: rilis.size,
      md5: rilis.md5,
    };
  }
  return c;
}

// POST /heartbeat: simpan kesehatan alat dari body.raw dan catat kejadian penting.
function catatKesehatan(a, raw) {
  const sekarangMs = Date.now();
  const uptime = angkaAtauNull(raw.uptime_s);
  const alasan = teksAtauNull(raw.reset_reason);

  // 1) Alat menyala ulang: uptime turun, atau uptime lebih kecil dari jeda sejak heartbeat sebelumnya.
  if (a.heartbeat_at && uptime !== null) {
    const jeda = (sekarangMs - Date.parse(a.heartbeat_at)) / 1000;
    if ((a.uptime_s !== null && uptime < a.uptime_s) || uptime < jeda) {
      catatEvent(a, 'boot', 'Menyala ulang: ' + (ALASAN_RESET[alasan] || 'lainnya'),
                 { reset_reason: alasan, uptime_s: uptime });
    }
  }

  // 2) Crash: dikirim berulang sampai heartbeat berhasil → abaikan yang sama dalam 10 menit.
  const crash = objek(raw.crash);
  if (crash) {
    const lalu = [...a.events].reverse().find((e) => e.type === 'crash');
    const sama = lalu && lalu.details && lalu.details.pc === crash.pc && lalu.details.backtrace === crash.backtrace &&
                 sekarangMs - Date.parse(lalu.created_at) < 10 * 60e3;
    if (!sama) {
      catatEvent(a, 'crash', `Program crash (task ${crash.task || '?'}, pc ${crash.pc || '?'})`, crash);
    }
  }

  // 3) Update firmware gagal (alat kembali ke versi lama).
  const otaGagal = teksAtauNull(raw.ota_failed);
  if (otaGagal && otaGagal !== a.ota_failed) {
    catatEvent(a, 'ota_failed', `Update firmware ${otaGagal} gagal, alat kembali ke ${a.firmware || '?'}`,
               { version: otaGagal, firmware: a.firmware });
  }

  // Simpan nilai terbaru (yang tidak dikirim → null).
  a.uptime_s = uptime;
  a.reset_reason = alasan;
  a.rfid_ok = typeof raw.rfid_ok === 'boolean' ? raw.rfid_ok : null;
  a.queue = angkaAtauNull(raw.queue);
  a.free_heap = angkaAtauNull(raw.free_heap);
  a.min_free_heap = angkaAtauNull(raw.min_free_heap);
  a.error = teksAtauNull(raw.error);
  a.ota_failed = otaGagal;
  a.heartbeat_at = isoLokal(sekarangMs);
  // Mode absen (firmware 1.6.0 ke atas; versi lama tidak mengirim → null).
  a.reported_tap_mode = ['auto', 'select'].includes(raw.tap_mode) ? raw.tap_mode : null;
  a.tap_select = ['check_in', 'check_out'].includes(raw.tap_select) ? raw.tap_select : null;
}

// Bentuk <device> untuk API admin, termasuk daftar masalah (issues) dalam bahasa Indonesia.
function dataAlat(data, deviceId) {
  const a = data.alat[deviceId];
  const sekarangMs = Date.now();
  const diamDetik = a.last_seen_at ? (sekarangMs - Date.parse(a.last_seen_at)) / 1000 : Infinity;

  let firmwareState = null;
  if (a.firmware_target) {
    if (a.firmware === a.firmware_target) firmwareState = 'installed';
    else if (a.ota_failed === a.firmware_target) firmwareState = 'failed';
    else firmwareState = 'pending';
  }

  const issues = [];
  if (a.last_seen_at && diamDetik > 600) issues.push('Offline sejak ' + a.last_seen_at);
  if (a.rfid_ok === false) issues.push('Pembaca RFID tidak terdeteksi (E30)');
  if (a.rssi !== null && a.rssi < -80) issues.push(`Sinyal WiFi lemah (${a.rssi} dBm)`);
  if (a.queue !== null && a.queue > 20) issues.push(`${a.queue} tap menunggu di antrean alat`);
  if (a.error) issues.push(`${a.error} ${ARTI_ERROR[a.error] || 'error tidak dikenal'}`);
  if (a.ota_failed) issues.push(`Update firmware ${a.ota_failed} gagal, alat kembali ke ${a.firmware || '?'}`);
  const restartBuruk = a.events.filter((e) => e.type === 'boot' && e.details &&
    ['watchdog', 'panic', 'brownout'].includes(e.details.reset_reason) &&
    sekarangMs - Date.parse(e.created_at) < 24 * 3600e3).length;
  if (restartBuruk >= 3) issues.push(`Sering restart tidak normal: ${restartBuruk}x dalam 24 jam`);
  if (a.min_free_heap !== null && a.min_free_heap < 20000) issues.push(`RAM hampir habis (${a.min_free_heap} byte)`);

  return {
    device_id: deviceId,
    name: a.name,
    online: diamDetik < 180,
    last_seen_at: a.last_seen_at,
    firmware: a.firmware, ip: a.ip, rssi: a.rssi, wifi_ssid: a.wifi_ssid,
    uptime_s: a.uptime_s, reset_reason: a.reset_reason, rfid_ok: a.rfid_ok, queue: a.queue,
    free_heap: a.free_heap, min_free_heap: a.min_free_heap, error: a.error, ota_failed: a.ota_failed,
    pin: a.pin,
    restart_at: restartEfektif(data, a),
    firmware_target: a.firmware_target,
    firmware_state: firmwareState,
    tap_mode: a.tap_mode,                   // pengaturan dari server: null = ikuti alat, "auto", "select"
    reported_tap_mode: a.reported_tap_mode, // mode yang sedang dipakai alat (dari heartbeat)
    tap_select: a.tap_select,               // pilihan DATANG/PULANG di alat saat ini (null = belum memilih)
    issues,
  };
}

// =============================================================================
// (A) API ALAT — sesuai doc/spesifikasi-api.md
// =============================================================================
function tanganiAlat(req, url, body) {
  const bagian = url.pathname.split('/');
  const aksi = bagian[bagian.length - 1];           // "/api/absensi/tap" → "tap"
  const base = bagian.slice(0, -1).join('/');       // "/api/absensi/tap" → "/api/absensi"
  const deviceId = req.headers['x-device-id'] || body.device_id || ''; // header menang atas body
  // Header X-Spec-Version (saat ini "1") boleh diabaikan; berguna kalau nanti ada versi 2.
  const data = muat();
  const sekarang = isoLokal(Date.now());

  // 1) API key wajib cocok. Kunci salah → HTTP 401 (alat tidak memasukkannya ke antrean).
  if (req.headers['x-api-key'] !== data.pengaturan.api_key) {
    return jawab(401, { ok: false, message: 'API key salah' });
  }

  // ID alat wajib (alat selalu mengirim header X-Device-ID).
  if (typeof deviceId !== 'string' || deviceId.trim() === '') {
    return jawab(400, { ok: false, message: 'Header X-Device-ID wajib' });
  }

  // 2) GET {base}/firmware/{versi} — unduh file firmware (update jarak jauh, bagian 6.1).
  //    Hanya untuk alat yang dijadwalkan ke versi itu.
  if (req.method === 'GET' && bagian.length >= 3 && bagian[bagian.length - 2] === 'firmware') {
    const a = daftarkanAlat(data, deviceId);
    simpan(data);
    const versi = decodeURIComponent(aksi);
    const rilis = a.firmware_target === versi && cariRilis(data, versi);
    const file = path.join(DIR_FIRMWARE, versi + '.bin');
    if (!rilis || !/^\d+\.\d+\.\d+$/.test(versi) || !fs.existsSync(file)) {
      return jawab(404, { ok: false, message: 'Firmware tidak tersedia untuk alat ini' });
    }
    return { kode: 200, berkas: { file, size: fs.statSync(file).size, md5: rilis.md5 } };
  }

  // 3) GET /ping — tes koneksi & sinkron jam.
  if (req.method === 'GET' && aksi === 'ping') {
    daftarkanAlat(data, deviceId);
    simpan(data);
    return jawab(200, { ok: true, message: 'Terhubung ke ' + data.pengaturan.title, server_time: sekarang,
                        config: config(data, deviceId, req, base) });
  }

  // 4) POST /heartbeat — tiap 60 detik: catat "terakhir terlihat" dan kesehatan alat.
  if (req.method === 'POST' && aksi === 'heartbeat') {
    const a = daftarkanAlat(data, deviceId, body);
    catatKesehatan(a, objek(body.raw) || {});
    simpan(data);
    return jawab(200, { ok: true, server_time: sekarang, config: config(data, deviceId, req, base) });
  }

  // 5) POST /tap — kartu ditempelkan (mode absen "Otomatis": server yang menentukan masuk/pulang).
  //    POST /check-in & /check-out — sama persis dengan /tap, dipakai alat di mode absen "Pilih Datang/Pulang"
  //    (petugas memilih DATANG atau PULANG di layar alat). Body-nya membawa "mode": "check_in"/"check_out";
  //    yang menentukan di sini adalah endpoint-nya. Aplikasi yang tidak butuh mode ini cukup tidak membuatnya.
  const MODE_ENDPOINT = { tap: null, 'check-in': 'check_in', 'check-out': 'check_out' };
  if (req.method === 'POST' && ada(MODE_ENDPOINT, aksi)) {
    daftarkanAlat(data, deviceId, body);
    const rfid = String(body.rfid || '').trim().toUpperCase();
    if (!rfid) {
      simpan(data);
      return jawab(400, { ok: false, message: 'rfid kosong' }); // format salah → 400
    }

    // Tap yang sama bisa terkirim ulang dari antrean alat (tap_id sama).
    // Jangan dicatat dua kali: kirim lagi respons yang dulu (dari endpoint mana pun: /tap, /check-in, /check-out).
    const tapId = String(body.tap_id || '');
    const lama = tapId && data.absensi.find((a) => a.tap_id === tapId);
    if (lama) {
      simpan(data);
      return jawab(200, lama.hasil);
    }

    // Tap biasa memakai jam server. Tap antrean (queued: true) memakai jam tap asli dari alat
    // (kalau tapped_at null, jam alat belum tersinkron → pakai waktu diterima).
    let waktu = sekarang;
    if (body.queued && body.tapped_at && !isNaN(Date.parse(body.tapped_at))) {
      waktu = isoLokal(Date.parse(body.tapped_at));
    }

    const hasil = tentukanStatus(data, rfid, waktu, MODE_ENDPOINT[aksi]);

    // Semua tap dicatat (termasuk unknown), supaya nomor kartu baru mudah dilihat admin.
    data.absensi.push({ id: ++data.id_terakhir.absensi, tap_id: tapId || null, rfid, device_id: deviceId,
                        jenis: hasil.status, waktu, queued: !!body.queued, raw_json: body, hasil });
    simpan(data);
    return jawab(200, hasil);
  }

  // 6) GET /announcements — pengumuman untuk screensaver.
  //    interval = detik per pengumuman, idle = screensaver muncul setelah alat diam sekian detik.
  if (req.method === 'GET' && aksi === 'announcements') {
    daftarkanAlat(data, deviceId);
    simpan(data);
    const items = urutPengumuman(data.pengumuman.filter((p) => p.active)).slice(0, 10)
      .map((p) => ({ id: String(p.id), title: p.title, description: p.description, icon: p.icon }));
    return jawab(200, { ok: true, interval: data.pengaturan.screensaver_interval,
                        idle: data.pengaturan.screensaver_idle, items });
  }

  return jawab(404, { ok: false, message: 'Endpoint tidak ada' });
}

// =============================================================================
// (B) API ADMIN — untuk aplikasi/admin Anda. Header X-Admin-Key wajib.
// =============================================================================

// ---------- Validasi body admin -----------------------------------------------
// Setiap fungsi periksa*() mengembalikan { errors, nilai }: errors = { field: pesan },
// nilai = field yang lolos (sudah dirapikan). `baru` = true untuk POST (field wajib harus ada).

function periksaPengaturan(body) {
  const errors = {}, nilai = {};
  if (ada(body, 'title')) {
    const t = typeof body.title === 'string' ? body.title.trim() : '';
    if (panjang(t) < 1 || panjang(t) > 30) errors.title = 'Judul harus 1-30 karakter';
    else nilai.title = t;
  }
  const bulat = (kunci, cocok, pesan) => {
    if (!ada(body, kunci)) return;
    if (Number.isInteger(body[kunci]) && cocok(body[kunci])) nilai[kunci] = body[kunci];
    else errors[kunci] = pesan;
  };
  bulat('dim_after', (v) => v === 0 || (v >= 10 && v <= 3600), 'dim_after harus 0 atau 10-3600 (detik)');
  bulat('dim_level', (v) => v >= 0 && v <= 100, 'dim_level harus 0-100 (persen)');
  bulat('screensaver_interval', (v) => v >= 2 && v <= 60, 'screensaver_interval harus 2-60 (detik)');
  bulat('screensaver_idle', (v) => v >= 5 && v <= 600, 'screensaver_idle harus 5-600 (detik)');
  bulat('duplicate_window', (v) => v >= 0 && v <= 3600, 'duplicate_window harus 0-3600 (detik)');
  if (ada(body, 'default_restart_at')) {
    const v = body.default_restart_at;
    if (v === '' || jamValid(v)) nilai.default_restart_at = v;
    else errors.default_restart_at = 'default_restart_at harus "HH:MM" atau "" (mati)';
  }
  if (ada(body, 'api_key')) {
    const v = body.api_key;
    if (typeof v === 'string' && /^[A-Za-z0-9._-]{8,64}$/.test(v)) nilai.api_key = v;
    else errors.api_key = 'api_key harus 8-64 karakter (huruf, angka, titik, garis bawah, minus)';
  }
  return { errors, nilai };
}

function periksaAnggota(body, baru) {
  const errors = {}, nilai = {};
  if (baru) {
    const rfid = typeof body.rfid === 'string' ? body.rfid.trim().toUpperCase() : '';
    if (!rfid) errors.rfid = 'Nomor kartu (rfid) wajib diisi';
    else if (rfid.length > 32 || !/^[0-9A-F]+$/.test(rfid)) errors.rfid = 'Nomor kartu maks. 32 karakter, hanya 0-9 dan A-F';
    else nilai.rfid = rfid;
  }
  if (baru || ada(body, 'name')) {
    const n = typeof body.name === 'string' ? body.name.trim() : '';
    if (panjang(n) < 1 || panjang(n) > 60) errors.name = 'Nama wajib diisi, 1-60 karakter';
    else nilai.name = n;
  }
  if (ada(body, 'photo_url')) {
    const u = body.photo_url;
    if (u === null || u === '') nilai.photo_url = null;
    else if (typeof u === 'string' && /^https?:\/\//.test(u)) nilai.photo_url = u;
    else errors.photo_url = 'photo_url harus URL http(s) atau null';
  }
  if (ada(body, 'active')) {
    if (typeof body.active === 'boolean') nilai.active = body.active;
    else errors.active = 'active harus true atau false';
  }
  return { errors, nilai };
}

function periksaAlat(data, body) {
  const errors = {}, nilai = {};
  if (ada(body, 'name')) {
    const n = body.name;
    if (n === null || n === '') nilai.name = null;
    else if (typeof n === 'string' && panjang(n.trim()) <= 40) nilai.name = n.trim() || null;
    else errors.name = 'Nama alat maks. 40 karakter';
  }
  if (ada(body, 'pin')) {
    const p = body.pin;
    if (p === null || p === '') nilai.pin = null;
    else if (typeof p === 'string' && /^\d{4,8}$/.test(p)) nilai.pin = p;
    else errors.pin = 'PIN harus 4-8 angka (teks), atau "" untuk menghapus';
  }
  if (ada(body, 'restart_at')) {
    const r = body.restart_at;
    if (r === null || r === '' || jamValid(r)) nilai.restart_at = r;
    else errors.restart_at = 'restart_at harus "HH:MM", "" (mati), atau null (pakai bawaan)';
  }
  if (ada(body, 'firmware_target')) {
    const v = body.firmware_target;
    if (v === null) nilai.firmware_target = null;
    else if (typeof v === 'string' && cariRilis(data, v)) nilai.firmware_target = v;
    else errors.firmware_target = 'Versi firmware tidak ada di daftar firmware';
  }
  if (ada(body, 'tap_mode')) {
    const v = body.tap_mode; // null = alat memakai pengaturannya sendiri (config.tap_mode tidak dikirim)
    if (v === null || v === 'auto' || v === 'select') nilai.tap_mode = v;
    else errors.tap_mode = 'tap_mode harus null (ikuti pengaturan alat), "auto", atau "select"';
  }
  return { errors, nilai };
}

function periksaPengumuman(body, baru) {
  const errors = {}, nilai = {};
  if (baru || ada(body, 'title')) {
    const t = typeof body.title === 'string' ? body.title.trim() : '';
    if (panjang(t) < 1 || panjang(t) > 40) errors.title = 'Judul wajib diisi, 1-40 karakter';
    else nilai.title = t;
  }
  if (ada(body, 'description')) {
    const d = body.description === null ? '' : body.description;
    if (typeof d === 'string' && panjang(d.trim()) <= 160) nilai.description = d.trim();
    else errors.description = 'Deskripsi maks. 160 karakter';
  }
  if (ada(body, 'icon')) {
    const i = body.icon === null || body.icon === '' ? 'info' : body.icon;
    if (IKON.includes(i)) nilai.icon = i;
    else errors.icon = 'Ikon harus salah satu: ' + IKON.join(', ');
  }
  if (ada(body, 'active')) {
    if (typeof body.active === 'boolean') nilai.active = body.active;
    else errors.active = 'active harus true atau false';
  }
  if (ada(body, 'order')) {
    if (Number.isInteger(body.order)) nilai.order = body.order;
    else errors.order = 'order harus bilangan bulat';
  }
  return { errors, nilai };
}

// ---------- Bentuk data untuk respons admin -----------------------------------

const dataAnggota = (k) => ({ rfid: k.rfid, name: k.nama, photo_url: k.foto_url || null, active: !!k.aktif });
const urutPengumuman = (daftar) => [...daftar].sort((a, b) => a.order - b.order || a.id - b.id);

// Rekap per anggota per hari antara dua tanggal (termasuk).
function rekap(data, dari, sampai) {
  const kelompok = new Map(); // "tanggal|rfid" → baris
  for (const a of data.absensi) {
    if (!['check_in', 'check_out', 'duplicate'].includes(a.jenis)) continue;
    const tgl = tanggal(a.waktu);
    if (tgl < dari || tgl > sampai) continue;
    const kunci = tgl + '|' + a.rfid;
    if (!kelompok.has(kunci)) {
      const k = data.karyawan.find((x) => x.rfid === a.rfid);
      kelompok.set(kunci, { date: tgl, rfid: a.rfid, name: k ? k.nama : (a.hasil && a.hasil.name) || null,
                            masuk: null, pulang: null, taps: 0 });
    }
    const b = kelompok.get(kunci);
    b.taps++;
    if (a.jenis === 'check_in' && (!b.masuk || a.waktu < b.masuk)) b.masuk = a.waktu;    // check_in pertama
    if (a.jenis === 'check_out' && (!b.pulang || a.waktu > b.pulang)) b.pulang = a.waktu; // check_out terakhir
  }
  return [...kelompok.values()]
    .sort((x, y) => x.date.localeCompare(y.date) || String(x.name).localeCompare(String(y.name)))
    .map((b) => ({
      date: b.date, rfid: b.rfid, name: b.name,
      check_in: b.masuk ? jamMenit(b.masuk) : null,
      check_out: b.pulang ? jamMenit(b.pulang) : null,
      duration_minutes: b.masuk && b.pulang ? Math.floor((Date.parse(b.pulang) - Date.parse(b.masuk)) / 60000) : null,
      taps: b.taps,
    }));
}

// Satu nilai CSV: diberi tanda kutip kalau berisi koma, kutip, atau baris baru.
function selCsv(v) {
  const s = v === null || v === undefined ? '' : String(v);
  return /[",\r\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
}

// Rilis firmware + jumlah alat yang targetnya versi ini.
function dataRilis(data, r) {
  const devices = Object.values(data.alat).filter((a) => a.firmware_target === r.version).length;
  return { version: r.version, size: r.size, md5: r.md5, notes: r.notes, uploaded_at: r.uploaded_at, devices };
}

// POST /admin/firmware?version=1.5.1&notes=... — body = isi file .bin mentah.
function unggahFirmware(data, url, isi, kebesaran) {
  const versi = (url.searchParams.get('version') || '').trim();
  const notes = (url.searchParams.get('notes') || '').trim();
  const errors = {};
  if (!/^\d+\.\d+\.\d+$/.test(versi)) errors.version = 'Versi harus berformat angka.angka.angka, mis. 1.5.1';
  else if (cariRilis(data, versi)) errors.version = `Versi ${versi} sudah ada`;

  if (kebesaran) errors.file = `File terlalu besar (maks. ${UKURAN_FIRMWARE_MAKS} byte)`;
  else if (isi.length < 1) errors.file = 'File firmware kosong';
  else if (isi[0] !== 0xE9) errors.file = 'Bukan file firmware ESP32 (.bin aplikasi, byte pertama harus 0xE9)';
  else if (!errors.version && !isi.includes(Buffer.from(versi))) {
    errors.file = `Versi ${versi} tidak ditemukan di dalam file. Pastikan VERSI_FIRMWARE di config.h sama.`;
  }
  if (Object.keys(errors).length) return gagalValidasi(errors);

  fs.mkdirSync(DIR_FIRMWARE, { recursive: true });
  fs.writeFileSync(path.join(DIR_FIRMWARE, versi + '.bin'), isi);
  const rilis = { version: versi, size: isi.length, md5: crypto.createHash('md5').update(isi).digest('hex'),
                  notes, uploaded_at: isoLokal(Date.now()) };
  data.firmware.push(rilis);
  simpan(data);
  return jawab(201, { ok: true, release: dataRilis(data, rilis) });
}

function tanganiAdmin(req, url, isi, kebesaran) {
  if (req.headers['x-admin-key'] !== ADMIN_KEY) {
    return jawab(401, { ok: false, message: 'Admin key salah' });
  }

  // "/api/absensi/admin/members/0218893066" → ["members", "0218893066"]
  const jalur = url.pathname;
  let seg;
  try {
    seg = jalur.slice(jalur.indexOf('/admin/') + 7).split('/').filter(Boolean).map(decodeURIComponent);
  } catch {
    return jawab(400, { ok: false, message: 'Alamat tidak valid' });
  }
  const [sumber, id, sub] = seg;
  const m = req.method;
  const data = muat();
  const tidakAda = jawab(404, { ok: false, message: 'Endpoint tidak ada' });

  // Unggah firmware memakai body biner, bukan JSON.
  if (m === 'POST' && sumber === 'firmware' && seg.length === 1) return unggahFirmware(data, url, isi, kebesaran);

  if (kebesaran) return jawab(413, { ok: false, message: 'Body terlalu besar' });
  let body = {};
  if (isi.length) {
    try { body = JSON.parse(isi.toString('utf8')); } catch { body = null; }
    if (!objek(body)) return jawab(400, { ok: false, message: 'Body harus JSON berupa objek {...}' });
  }

  // ---- Pengaturan ----------------------------------------------------------
  if (sumber === 'settings' && seg.length === 1) {
    if (m === 'GET') return jawab(200, { ok: true, settings: data.pengaturan });
    if (m === 'PUT') {
      const { errors, nilai } = periksaPengaturan(body);
      if (Object.keys(errors).length) return gagalValidasi(errors);
      Object.assign(data.pengaturan, nilai);
      simpan(data);
      return jawab(200, { ok: true, settings: data.pengaturan });
    }
    return tidakAda;
  }

  // ---- Anggota -------------------------------------------------------------
  if (sumber === 'members') {
    if (seg.length === 1 && m === 'GET') {
      const members = [...data.karyawan].sort((a, b) => a.nama.localeCompare(b.nama)).map(dataAnggota);
      return jawab(200, { ok: true, members });
    }
    if (seg.length === 1 && m === 'POST') {
      const { errors, nilai } = periksaAnggota(body, true);
      if (nilai.rfid && data.karyawan.some((k) => k.rfid === nilai.rfid)) errors.rfid = 'Nomor kartu sudah terdaftar';
      if (Object.keys(errors).length) return gagalValidasi(errors);
      const k = { rfid: nilai.rfid, nama: nilai.name, foto_url: nilai.photo_url ?? null, aktif: nilai.active ?? true };
      data.karyawan.push(k);
      simpan(data);
      return jawab(201, { ok: true, member: dataAnggota(k) });
    }
    if (seg.length === 2) {
      const rfid = id.trim().toUpperCase();
      const k = data.karyawan.find((x) => x.rfid === rfid);
      if (!k) return jawab(404, { ok: false, message: 'Anggota tidak ditemukan' });
      if (m === 'PUT') {
        const { errors, nilai } = periksaAnggota(body, false);
        if (Object.keys(errors).length) return gagalValidasi(errors);
        if (ada(nilai, 'name')) k.nama = nilai.name;
        if (ada(nilai, 'photo_url')) k.foto_url = nilai.photo_url;
        if (ada(nilai, 'active')) k.aktif = nilai.active;
        simpan(data);
        return jawab(200, { ok: true, member: dataAnggota(k) });
      }
      if (m === 'DELETE') {
        data.karyawan = data.karyawan.filter((x) => x !== k);
        simpan(data);
        return jawab(200, { ok: true });
      }
    }
    return tidakAda;
  }

  // ---- Kartu belum terdaftar ----------------------------------------------
  // Nomor kartu dengan tap "unknown" yang SEKARANG belum ada di anggota.
  if (sumber === 'unknown-cards' && seg.length === 1 && m === 'GET') {
    const terdaftar = new Set(data.karyawan.map((k) => k.rfid));
    const kartu = new Map();
    for (const a of data.absensi) {
      if (a.jenis !== 'unknown' || terdaftar.has(a.rfid)) continue;
      const c = kartu.get(a.rfid) || { rfid: a.rfid, taps: 0, first_seen_at: a.waktu, last_seen_at: a.waktu,
                                       last_device_id: a.device_id };
      c.taps++;
      if (a.waktu < c.first_seen_at) c.first_seen_at = a.waktu;
      if (a.waktu >= c.last_seen_at) { c.last_seen_at = a.waktu; c.last_device_id = a.device_id; }
      kartu.set(a.rfid, c);
    }
    const cards = [...kartu.values()].sort((x, y) => Date.parse(y.last_seen_at) - Date.parse(x.last_seen_at));
    return jawab(200, { ok: true, cards });
  }

  // ---- Rekap ---------------------------------------------------------------
  if (sumber === 'report' && seg.length === 1 && m === 'GET') {
    const dari = url.searchParams.get('from') || hariIniLokal();
    const sampai = url.searchParams.get('to') || hariIniLokal();
    const errors = {};
    if (!tanggalValid(dari)) errors.from = 'from harus tanggal YYYY-MM-DD';
    if (!tanggalValid(sampai)) errors.to = 'to harus tanggal YYYY-MM-DD';
    if (!Object.keys(errors).length) {
      const hari = (Date.parse(sampai) - Date.parse(dari)) / 86400e3 + 1;
      if (hari < 1) errors.to = 'to tidak boleh sebelum from';
      else if (hari > 92) errors.to = 'Rentang rekap maks. 92 hari';
    }
    if (Object.keys(errors).length) return gagalValidasi(errors);

    const rows = rekap(data, dari, sampai);
    if (url.searchParams.get('format') === 'csv') {
      const baris = ['tanggal,rfid,nama,masuk,pulang,durasi_menit,jumlah_tap'];
      for (const r of rows) {
        baris.push([r.date, r.rfid, r.name, r.check_in, r.check_out, r.duration_minutes, r.taps].map(selCsv).join(','));
      }
      return { kode: 200, csv: baris.join('\r\n') + '\r\n', namaFile: `rekap-${dari}-${sampai}.csv` };
    }
    return jawab(200, { ok: true, from: dari, to: sampai, rows });
  }

  // ---- Semua tap pada satu tanggal ----------------------------------------
  if (sumber === 'attendances' && seg.length === 1 && m === 'GET') {
    const tgl = url.searchParams.get('date') || hariIniLokal();
    if (!tanggalValid(tgl)) return gagalValidasi({ date: 'date harus tanggal YYYY-MM-DD' });
    const items = data.absensi
      .filter((a) => tanggal(a.waktu) === tgl)
      .sort((x, y) => Date.parse(y.waktu) - Date.parse(x.waktu) || y.id - x.id)
      .map((a) => {
        const k = data.karyawan.find((x) => x.rfid === a.rfid);
        // nama anggota sekarang; anggota sudah dihapus → nama dari balasan tap
        return { id: a.id, time: a.waktu, rfid: a.rfid, name: (k && k.nama) || (a.hasil && a.hasil.name) || null,
                 status: a.jenis, device_id: a.device_id, queued: !!a.queued, tap_id: a.tap_id || null };
      });
    return jawab(200, { ok: true, date: tgl, items });
  }

  // ---- Alat ----------------------------------------------------------------
  if (sumber === 'devices') {
    if (seg.length === 1 && m === 'GET') {
      const devices = Object.keys(data.alat).map((d) => dataAlat(data, d))
        .sort((x, y) => (Date.parse(y.last_seen_at) || 0) - (Date.parse(x.last_seen_at) || 0));
      return jawab(200, { ok: true, devices });
    }
    if (seg.length === 2) {
      const a = data.alat[id];
      if (!a || !ada(data.alat, id)) return jawab(404, { ok: false, message: 'Alat tidak ditemukan' });
      if (m === 'GET') {
        const events = a.events.slice(-20).reverse(); // 20 terbaru, terbaru dulu
        return jawab(200, { ok: true, device: dataAlat(data, id), events });
      }
      if (m === 'PUT') {
        const { errors, nilai } = periksaAlat(data, body);
        if (Object.keys(errors).length) return gagalValidasi(errors);
        Object.assign(a, nilai);
        simpan(data);
        return jawab(200, { ok: true, device: dataAlat(data, id) });
      }
    }
    return tidakAda;
  }

  // ---- Firmware ------------------------------------------------------------
  if (sumber === 'firmware') {
    if (seg.length === 1 && m === 'GET') {
      const releases = [...data.firmware].sort((x, y) => y.uploaded_at.localeCompare(x.uploaded_at))
        .map((r) => dataRilis(data, r));
      return jawab(200, { ok: true, releases });
    }
    const rilis = id && cariRilis(data, id);
    if (seg.length === 2 && m === 'DELETE') {
      if (!rilis) return jawab(404, { ok: false, message: 'Firmware tidak ditemukan' });
      data.firmware = data.firmware.filter((r) => r !== rilis);
      for (const a of Object.values(data.alat)) if (a.firmware_target === id) a.firmware_target = null;
      fs.rmSync(path.join(DIR_FIRMWARE, id + '.bin'), { force: true });
      simpan(data);
      return jawab(200, { ok: true });
    }
    if (seg.length === 3 && sub === 'apply-all' && m === 'POST') {
      if (!rilis) return jawab(404, { ok: false, message: 'Firmware tidak ditemukan' });
      const semua = Object.values(data.alat);
      for (const a of semua) a.firmware_target = id;
      simpan(data);
      return jawab(200, { ok: true, devices: semua.length });
    }
    return tidakAda;
  }

  // ---- Pengumuman ----------------------------------------------------------
  if (sumber === 'announcements') {
    const dataItem = (p) => ({ id: p.id, title: p.title, description: p.description, icon: p.icon,
                               active: p.active, order: p.order });
    if (seg.length === 1 && m === 'GET') {
      return jawab(200, { ok: true, rev: data.pengumuman_rev, items: urutPengumuman(data.pengumuman).map(dataItem) });
    }
    if (seg.length === 1 && m === 'POST') {
      const { errors, nilai } = periksaPengumuman(body, true);
      if (Object.keys(errors).length) return gagalValidasi(errors);
      const p = { id: ++data.id_terakhir.pengumuman, title: nilai.title, description: nilai.description ?? '',
                  icon: nilai.icon ?? 'info', active: nilai.active ?? true, order: nilai.order ?? 0 };
      data.pengumuman.push(p);
      gantiRev(data);
      simpan(data);
      return jawab(201, { ok: true, item: dataItem(p), rev: data.pengumuman_rev });
    }
    if (seg.length === 2) {
      const p = data.pengumuman.find((x) => String(x.id) === id);
      if (!p) return jawab(404, { ok: false, message: 'Pengumuman tidak ditemukan' });
      if (m === 'PUT') {
        const { errors, nilai } = periksaPengumuman(body, false);
        if (Object.keys(errors).length) return gagalValidasi(errors);
        Object.assign(p, nilai);
        gantiRev(data);
        simpan(data);
        return jawab(200, { ok: true, item: dataItem(p), rev: data.pengumuman_rev });
      }
      if (m === 'DELETE') {
        data.pengumuman = data.pengumuman.filter((x) => x !== p);
        gantiRev(data);
        simpan(data);
        return jawab(200, { ok: true, rev: data.pengumuman_rev });
      }
    }
    return tidakAda;
  }

  return tidakAda;
}

// ---------- Pembagi: API admin atau API alat ---------------------------------
function tangani(req, isi, kebesaran) {
  const url = new URL(req.url, 'http://x');
  if (url.pathname.includes('/admin/')) return tanganiAdmin(req, url, isi, kebesaran);

  if (kebesaran) return jawab(413, { ok: false, message: 'Body terlalu besar' });
  let body = {};
  try { body = JSON.parse(isi.toString('utf8') || '{}'); } catch { /* body bukan JSON → anggap kosong */ }
  if (!objek(body)) body = {};
  return tanganiAlat(req, url, body);
}

// ---------- Server HTTP -------------------------------------------------------
http.createServer((req, res) => {
  // Body dikumpulkan sebagai Buffer (unggah firmware berupa data biner). Batas ukuran diperiksa
  // selama membaca: kelebihannya tidak disimpan di memori, cukup ditandai "kebesaran".
  const unggah = req.method === 'POST' && /\/admin\/firmware\/?$/.test(req.url.split('?')[0]);
  const batas = unggah ? UKURAN_FIRMWARE_MAKS : BATAS_BODY_JSON;
  const potongan = [];
  let ukuran = 0;
  let kebesaran = false;
  req.on('data', (c) => {
    if (kebesaran) return;
    ukuran += c.length;
    if (ukuran > batas) { kebesaran = true; potongan.length = 0; return; }
    potongan.push(c);
  });
  req.on('end', () => {
    let hasil;
    try {
      hasil = tangani(req, Buffer.concat(potongan), kebesaran);
    } catch (err) {
      console.error(err);
      hasil = jawab(500, { ok: false, message: 'Galat server' });
    }
    console.log(new Date().toISOString(), req.method, req.url, '→', hasil.kode, (hasil.json && hasil.json.status) || '');

    if (hasil.berkas) {         // unduhan firmware: file biner mentah
      res.writeHead(200, { 'Content-Type': 'application/octet-stream', 'Content-Length': hasil.berkas.size,
                           'x-MD5': hasil.berkas.md5 });
      fs.createReadStream(hasil.berkas.file).pipe(res);
    } else if (hasil.csv !== undefined) {
      res.writeHead(hasil.kode, { 'Content-Type': 'text/csv; charset=utf-8',
                                  'Content-Disposition': `attachment; filename="${hasil.namaFile}"` });
      res.end(hasil.csv);
    } else {
      res.writeHead(hasil.kode, { 'Content-Type': 'application/json; charset=utf-8' });
      res.end(JSON.stringify(hasil.json));
    }
  });
}).listen(PORT, '0.0.0.0', () => {
  const s = muat().pengaturan;
  console.log(`Server contoh Absensi RFID Terintegrasi berjalan di http://0.0.0.0:${PORT}`);
  console.log(`Isi Base URL di alat: http://<ip-laptop>:${PORT}   API key: ${s.api_key}`);
  console.log(`API admin: http://localhost:${PORT}/admin/...   header X-Admin-Key: ${ADMIN_KEY}`);
});
