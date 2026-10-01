// =============================================================================
// Contoh server API Alat Absensi Tap (v1) — Node.js 18+, hanya modul bawaan.
// Tidak perlu `npm install`.
//
// Jalankan:   node server.js            (port lain: PORT=9000 node server.js)
// Di alat:    Pengaturan → Server → Base URL = http://<ip-laptop>:8080
//                                   API key  = isi konstanta API_KEY di bawah
// Spesifikasi lengkap: ../../doc/spesifikasi-api.md
//
// Data disimpan di data.json (dibuat otomatis). File ini dibaca ulang setiap
// permintaan, jadi Anda bisa mengedit daftar karyawan tanpa restart server.
// =============================================================================

const http = require('http');
const fs = require('fs');
const path = require('path');

const API_KEY = 'ganti-dengan-kunci-anda';
const JUDUL = 'Aplikasi Contoh';   // judul di layar utama alat (config.title, maks. ±30 karakter)
const REDUP_SETELAH = 60;          // layar alat meredup setelah diam sekian detik (config.dim_after, 0 = tidak pernah, atau 10–3600)
const REDUP_TERANG = 20;           // kecerahan layar saat redup, persen (config.dim_level, 0–100)
const JEDA_DOBEL = 60;             // detik: tap ulang dalam jeda ini dianggap "duplicate"
const ZONA_JAM = 7;                // WIB = 7, WITA = 8, WIT = 9
const PORT = Number(process.env.PORT) || 8080;
const FILE_DATA = path.join(__dirname, 'data.json');

// Pengumuman untuk screensaver alat (opsional, GET /announcements). Maks. 10 item, judul maks. 40
// karakter, deskripsi maks. 160. Kode ikon: info, pengumuman, kalender, jam, peringatan, rapat,
// libur, selamat, kesehatan, buku.
const PENGUMUMAN = [
  { id: '1', title: 'Rapat Guru', description: 'Hari ini pukul 13.00 di aula lantai 2.', icon: 'rapat' },
  { id: '2', title: 'Libur Nasional', description: 'Kamis, 2 Oktober 2026 kantor tutup.', icon: 'libur' },
];
const PENGUMUMAN_REV = '1'; // GANTI nilai ini setiap PENGUMUMAN diubah → alat mengambil ulang dalam ±1 menit

// PIN menu Pengaturan per alat (config.pin, 4–8 digit). Kunci = X-Device-ID yang tampil di layar alat.
// Alat yang tidak ada di daftar ini tidak dikirimi PIN, jadi PIN-nya tetap (bawaan 2026).
const PIN_ALAT = {
  // 'ABS-79438C': '4321',
  // 'ABS-1A2B3C': '5678',
};

// ---------- Penyimpanan (file JSON) -------------------------------------------

function muat() {
  if (!fs.existsSync(FILE_DATA)) {
    // Contoh data awal. Nomor kartu (rfid) berupa teks 10 digit.
    simpan({
      karyawan: [
        { rfid: '0218893066', nama: 'Budi Santoso', foto_url: null, aktif: true },
        { rfid: '0012345678', nama: 'Siti Aminah', foto_url: null, aktif: true },
        { rfid: '0055555555', nama: 'Rina Kurnia', foto_url: null, aktif: false }, // contoh kartu nonaktif
      ],
      absensi: [],  // { tap_id, rfid, device_id, jenis, waktu, queued, raw_json, hasil }
      alat: {},     // device_id → { terakhir_terlihat, firmware, ip, rssi, raw_json }
    });
  }
  return JSON.parse(fs.readFileSync(FILE_DATA, 'utf8'));
}

function simpan(data) {
  fs.writeFileSync(FILE_DATA, JSON.stringify(data, null, 2));
}

// ---------- Fungsi bantu waktu ------------------------------------------------

// Milidetik → "2026-09-30T07:45:12+07:00" (waktu lokal kantor, ISO 8601).
function isoLokal(ms) {
  return new Date(ms + ZONA_JAM * 3600e3).toISOString().slice(0, 19) + '+0' + ZONA_JAM + ':00';
}
const jamMenit = (iso) => iso.slice(11, 16); // "HH:MM"
const tanggal = (iso) => iso.slice(0, 10);   // "YYYY-MM-DD"

// Pengaturan jarak jauh yang dikirim ke alat lewat /ping dan /heartbeat (semua opsional).
function config(deviceId) {
  const c = {
    title: JUDUL,
    dim_after: REDUP_SETELAH,
    dim_level: REDUP_TERANG,
    announcements_rev: PENGUMUMAN_REV, // berubah → alat memanggil ulang GET /announcements
  };
  if (typeof deviceId === 'string' && Object.hasOwn(PIN_ALAT, deviceId)) c.pin = PIN_ALAT[deviceId]; // PIN khusus alat ini
  return c;
}

// ATURAN BISNIS — bagian inilah yang biasanya Anda ubah sesuai kebutuhan.
// Hasilnya langsung menjadi respons /tap.
function tentukanStatus(data, rfid, waktu) {
  const k = data.karyawan.find((x) => x.rfid === rfid);
  if (!k) {
    return { ok: false, status: 'unknown', message: 'Kartu tidak terdaftar', time: jamMenit(waktu) };
  }

  const dasar = { name: k.nama, time: jamMenit(waktu), photo_url: k.foto_url || null };

  if (!k.aktif) {
    return { ok: false, status: 'rejected', message: 'Kartu nonaktif', ...dasar };
  }

  // Tap yang diterima (masuk/pulang) pada hari yang sama, diurutkan dari yang paling awal.
  const hariIni = data.absensi
    .filter((a) => a.rfid === rfid && ['check_in', 'check_out'].includes(a.jenis))
    .filter((a) => tanggal(a.waktu) === tanggal(waktu))
    .map((a) => a.waktu)
    .sort();

  if (hariIni.length === 0) {
    return { ok: true, status: 'check_in', message: 'Selamat datang', ...dasar };
  }
  const terakhir = hariIni[hariIni.length - 1];
  if (Math.abs(Date.parse(waktu) - Date.parse(terakhir)) < JEDA_DOBEL * 1000) {
    // "time" = jam tap yang sudah tercatat sebelumnya
    return { ok: true, status: 'duplicate', message: 'Sudah tercatat', ...dasar, time: jamMenit(terakhir) };
  }
  // Contoh "info": maks. 2 baris, masing-masing ±40 karakter.
  return { ok: true, status: 'check_out', message: 'Hati-hati di jalan', ...dasar,
           info: ['Masuk tadi ' + jamMenit(hariIni[0])] };
}

// ---------- Endpoint -------------------------------------------------------------
// Mengembalikan [kodeHttp, objekJson].
function tangani(req, body) {
  const aksi = new URL(req.url, 'http://x').pathname.split('/').pop(); // "/api/absensi/tap" → "tap"
  const deviceId = req.headers['x-device-id'] || body.device_id || ''; // header menang atas body
  // Header X-Spec-Version (saat ini "1") boleh diabaikan; berguna kalau nanti ada versi 2.
  const sekarang = isoLokal(Date.now());

  // 1) API key wajib cocok. Kunci salah → HTTP 401 (alat tidak memasukkannya ke antrean).
  if (req.headers['x-api-key'] !== API_KEY) {
    return [401, { ok: false, message: 'API key salah' }];
  }

  // ID alat wajib (alat selalu mengirim header X-Device-ID).
  if (typeof deviceId !== 'string' || deviceId.trim() === '') {
    return [400, { ok: false, message: 'Header X-Device-ID wajib' }];
  }

  // 2) GET /ping — tes koneksi & sinkron jam.
  if (req.method === 'GET' && aksi === 'ping') {
    return [200, { ok: true, message: 'Terhubung ke ' + JUDUL, server_time: sekarang, config: config(deviceId) }];
  }

  // 3) POST /heartbeat — tiap 60 detik; cukup catat "terakhir terlihat".
  if (req.method === 'POST' && aksi === 'heartbeat') {
    const data = muat();
    const raw = body.raw || {};
    data.alat[deviceId] = {
      terakhir_terlihat: sekarang, firmware: body.firmware || null,
      ip: raw.ip || null, rssi: raw.rssi ?? null, raw_json: body,
    };
    simpan(data);
    return [200, { ok: true, server_time: sekarang, config: config(deviceId) }];
  }

  // 4) POST /tap — kartu ditempelkan.
  if (req.method === 'POST' && aksi === 'tap') {
    const rfid = String(body.rfid || '').trim().toUpperCase();
    if (!rfid) return [400, { ok: false, message: 'rfid kosong' }]; // format salah → 400

    // Tap yang sama bisa terkirim ulang dari antrean alat (tap_id sama).
    // Jangan dicatat dua kali: kirim lagi respons yang dulu.
    const data = muat();
    const tapId = String(body.tap_id || '');
    const lama = tapId && data.absensi.find((a) => a.tap_id === tapId);
    if (lama) return [200, lama.hasil];

    // Tap biasa memakai jam server. Tap antrean (queued: true) memakai jam tap asli dari alat
    // (kalau tapped_at null, jam alat belum tersinkron → pakai waktu diterima).
    let waktu = sekarang;
    if (body.queued && body.tapped_at && !isNaN(Date.parse(body.tapped_at))) {
      waktu = isoLokal(Date.parse(body.tapped_at));
    }

    const hasil = tentukanStatus(data, rfid, waktu);

    // Semua tap dicatat (termasuk unknown), supaya nomor kartu baru mudah dilihat admin.
    data.absensi.push({ tap_id: tapId || null, rfid, device_id: deviceId, jenis: hasil.status, waktu,
                        queued: !!body.queued, raw_json: body, hasil });
    simpan(data);
    return [200, hasil];
  }

  // 5) GET /announcements — pengumuman untuk screensaver (opsional).
  //    interval = detik per pengumuman (2–60), idle = screensaver muncul setelah alat diam sekian detik (5–600).
  if (req.method === 'GET' && aksi === 'announcements') {
    return [200, { ok: true, interval: 3, idle: 30, items: PENGUMUMAN }];
  }

  return [404, { ok: false, message: 'Endpoint tidak ada' }];
}

// ---------- Server HTTP -------------------------------------------------------
http.createServer((req, res) => {
  let isi = '';
  req.on('data', (potongan) => (isi += potongan));
  req.on('end', () => {
    let kode, hasil;
    try {
      let body = {};
      try { body = JSON.parse(isi || '{}'); } catch { /* body bukan JSON → anggap kosong */ }
      if (typeof body !== 'object' || body === null) body = {};
      [kode, hasil] = tangani(req, body);
    } catch (err) {
      console.error(err);
      [kode, hasil] = [500, { ok: false, message: 'Galat server' }];
    }
    console.log(new Date().toISOString(), req.method, req.url, '→', kode, hasil.status || '');
    res.writeHead(kode, { 'Content-Type': 'application/json; charset=utf-8' });
    res.end(JSON.stringify(hasil));
  });
}).listen(PORT, '0.0.0.0', () => {
  console.log(`Server contoh absensi berjalan di http://0.0.0.0:${PORT}`);
  console.log(`Isi Base URL di alat: http://<ip-laptop>:${PORT}   API key: ${API_KEY}`);
});
