<?php
// =============================================================================
// Contoh server API Alat Absensi Tap (v1) — PHP 8 + SQLite, satu file, tanpa framework.
//
// Jalankan:   php -S 0.0.0.0:8080 index.php
// Di alat:    Pengaturan → Server → Base URL = http://<ip-laptop>:8080
//                                   API key  = isi konstanta API_KEY di bawah
// Spesifikasi lengkap: ../../doc/spesifikasi-api.md
//
// File data.sqlite dan tabelnya dibuat otomatis saat pertama kali dijalankan.
// =============================================================================

const API_KEY    = 'ganti-dengan-kunci-anda';
const JUDUL      = 'Aplikasi Contoh';   // judul di layar utama alat (config.title, maks. ±30 karakter)
const REDUP_SETELAH = 60;                // layar alat meredup setelah diam sekian detik (config.dim_after, 0 = tidak pernah, atau 10–3600)
const REDUP_TERANG  = 20;                // kecerahan layar saat redup, persen (config.dim_level, 0–100)
const JEDA_DOBEL = 60;                  // detik: tap ulang dalam jeda ini dianggap "duplicate"
date_default_timezone_set('Asia/Jakarta'); // WIB. Ganti 'Asia/Makassar' (WITA) / 'Asia/Jayapura' (WIT)

// Pengumuman untuk screensaver alat (opsional, GET /announcements). Maks. 10 item, judul maks. 40
// karakter, deskripsi maks. 160. Kode ikon: info, pengumuman, kalender, jam, peringatan, rapat,
// libur, selamat, kesehatan, buku.
const PENGUMUMAN = [
    ['id' => '1', 'title' => 'Rapat Guru', 'description' => 'Hari ini pukul 13.00 di aula lantai 2.', 'icon' => 'rapat'],
    ['id' => '2', 'title' => 'Libur Nasional', 'description' => 'Kamis, 2 Oktober 2026 kantor tutup.', 'icon' => 'libur'],
];
const PENGUMUMAN_REV = '1'; // GANTI nilai ini setiap PENGUMUMAN diubah → alat mengambil ulang dalam ±1 menit

// PIN menu Pengaturan per alat (config.pin, 4–8 digit). Kunci = X-Device-ID yang tampil di layar alat.
// Alat yang tidak ada di daftar ini tidak dikirimi PIN, jadi PIN-nya tetap (bawaan 2026).
const PIN_ALAT = [
    // 'ABS-79438C' => '4321',
    // 'ABS-1A2B3C' => '5678',
];

// ---------- Database (dibuat otomatis) ----------------------------------------
$db = new PDO('sqlite:' . __DIR__ . '/data.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec("
    CREATE TABLE IF NOT EXISTS karyawan (
        rfid     TEXT PRIMARY KEY,           -- nomor kartu 10 digit, contoh '0218893066'
        nama     TEXT NOT NULL,
        foto_url TEXT,                       -- JPEG baseline maks. 160x160 px & 30 KB, boleh NULL
        aktif    INTEGER NOT NULL DEFAULT 1  -- 0 = kartu ditolak
    );
    CREATE TABLE IF NOT EXISTS absensi (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        tap_id    TEXT UNIQUE, -- ID unik dari alat; sama saat tap dikirim ulang dari antrean
        rfid      TEXT,
        device_id TEXT,
        jenis     TEXT,     -- check_in / check_out / duplicate / unknown / rejected
        waktu     TEXT,     -- 'YYYY-MM-DD HH:MM:SS' waktu lokal
        queued    INTEGER,  -- 1 = tap antrean (dikirim belakangan oleh alat)
        raw_json  TEXT,     -- isi request asli, untuk jejak
        hasil_json TEXT     -- respons yang dikirim, dipakai lagi kalau tap_id terkirim ulang
    );
    CREATE TABLE IF NOT EXISTS alat (
        device_id         TEXT PRIMARY KEY,
        terakhir_terlihat TEXT,
        firmware          TEXT,
        ip                TEXT,
        rssi              INTEGER,
        raw_json          TEXT
    );
");

// Contoh data karyawan, hanya diisi kalau tabel masih kosong.
if ($db->query('SELECT COUNT(*) FROM karyawan')->fetchColumn() == 0) {
    $db->exec("INSERT INTO karyawan (rfid, nama, foto_url, aktif) VALUES
        ('0218893066', 'Budi Santoso', NULL, 1),
        ('0012345678', 'Siti Aminah',  NULL, 1),
        ('0055555555', 'Rina Kurnia',  NULL, 0)"); // contoh kartu nonaktif → "rejected"
}

// ---------- Fungsi bantu -------------------------------------------------------

// Kirim JSON lalu selesai.
function balas(int $kodeHttp, array $data): never
{
    http_response_code($kodeHttp);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Pengaturan jarak jauh yang dikirim ke alat lewat /ping dan /heartbeat (semua opsional).
function config($deviceId): array
{
    $c = [
        'title' => JUDUL,
        'dim_after' => REDUP_SETELAH,
        'dim_level' => REDUP_TERANG,
        'announcements_rev' => PENGUMUMAN_REV, // berubah → alat memanggil ulang GET /announcements
        'restart_at' => '03:00',               // jam restart harian alat ("HH:MM", jam di layar alat); "" = tidak restart otomatis
    ];
    if (is_string($deviceId) && isset(PIN_ALAT[$deviceId])) {
        $c['pin'] = PIN_ALAT[$deviceId];       // PIN khusus alat ini
    }
    return $c;
}

// ATURAN BISNIS — bagian inilah yang biasanya Anda ubah sesuai kebutuhan.
// Hasilnya langsung menjadi respons /tap.
function tentukanStatus(PDO $db, string $rfid, int $waktu): array
{
    $jam = date('H:i', $waktu);

    $q = $db->prepare('SELECT * FROM karyawan WHERE rfid = ?');
    $q->execute([$rfid]);
    $k = $q->fetch();

    if (!$k) {
        return ['ok' => false, 'status' => 'unknown', 'message' => 'Kartu tidak terdaftar', 'time' => $jam];
    }

    $dasar = ['name' => $k['nama'], 'time' => $jam, 'photo_url' => $k['foto_url'] ?: null];

    if (!$k['aktif']) {
        return ['ok' => false, 'status' => 'rejected', 'message' => 'Kartu nonaktif'] + $dasar;
    }

    // Tap pertama & terakhir yang diterima (masuk/pulang) pada hari yang sama.
    $q = $db->prepare("SELECT MIN(waktu) AS pertama, MAX(waktu) AS terakhir FROM absensi
                       WHERE rfid = ? AND jenis IN ('check_in', 'check_out') AND substr(waktu, 1, 10) = ?");
    $q->execute([$rfid, date('Y-m-d', $waktu)]);
    ['pertama' => $pertama, 'terakhir' => $terakhir] = $q->fetch();

    if ($terakhir === null) {
        return ['ok' => true, 'status' => 'check_in', 'message' => 'Selamat datang'] + $dasar;
    }
    if (abs($waktu - strtotime($terakhir)) < JEDA_DOBEL) {
        // "time" = jam tap yang sudah tercatat sebelumnya
        return ['ok' => true, 'status' => 'duplicate', 'message' => 'Sudah tercatat',
                'time' => date('H:i', strtotime($terakhir))] + $dasar;
    }
    // Contoh "info": maks. 2 baris, masing-masing ±40 karakter.
    return ['ok' => true, 'status' => 'check_out', 'message' => 'Hati-hati di jalan'] + $dasar
         + ['info' => ['Masuk tadi ' . date('H:i', strtotime($pertama))]];
}

// ---------- Permintaan masuk --------------------------------------------------
$metode   = $_SERVER['REQUEST_METHOD'];
$aksi     = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)); // "/api/absensi/tap" → "tap"
$body     = json_decode(file_get_contents('php://input'), true);
$body     = is_array($body) ? $body : [];
$deviceId = $_SERVER['HTTP_X_DEVICE_ID'] ?? ($body['device_id'] ?? ''); // header menang atas body
// Header X-Spec-Version (saat ini "1") boleh diabaikan; berguna kalau nanti ada versi 2.

// 1) API key wajib cocok. Kunci salah → HTTP 401 (alat tidak memasukkannya ke antrean).
if (!hash_equals(API_KEY, $_SERVER['HTTP_X_API_KEY'] ?? '')) {
    balas(401, ['ok' => false, 'message' => 'API key salah']);
}

// ID alat wajib (alat selalu mengirim header X-Device-ID).
if (!is_string($deviceId) || trim($deviceId) === '') {
    balas(400, ['ok' => false, 'message' => 'Header X-Device-ID wajib']);
}

// 2) GET /ping — tes koneksi & sinkron jam.
if ($metode === 'GET' && $aksi === 'ping') {
    balas(200, [
        'ok'          => true,
        'message'     => 'Terhubung ke ' . JUDUL,
        'server_time' => date('c'),            // contoh: 2026-09-30T07:45:12+07:00
        'config'      => config($deviceId),
    ]);
}

// 3) POST /heartbeat — tiap 60 detik; cukup catat "terakhir terlihat".
if ($metode === 'POST' && $aksi === 'heartbeat') {
    $raw = is_array($body['raw'] ?? null) ? $body['raw'] : [];
    $db->prepare('INSERT OR REPLACE INTO alat (device_id, terakhir_terlihat, firmware, ip, rssi, raw_json)
                  VALUES (?, ?, ?, ?, ?, ?)')
       ->execute([$deviceId, date('c'), $body['firmware'] ?? null, $raw['ip'] ?? null,
                  $raw['rssi'] ?? null, json_encode($body)]);
    balas(200, ['ok' => true, 'server_time' => date('c'), 'config' => config($deviceId)]);
}

// 4) POST /tap — kartu ditempelkan.
if ($metode === 'POST' && $aksi === 'tap') {
    $rfid = strtoupper(trim((string)($body['rfid'] ?? '')));
    if ($rfid === '') {
        balas(400, ['ok' => false, 'message' => 'rfid kosong']); // format salah → 400
    }

    // Tap yang sama bisa terkirim ulang dari antrean alat (tap_id sama).
    // Jangan dicatat dua kali: kirim lagi respons yang dulu.
    $tapId = (string)($body['tap_id'] ?? '');
    if ($tapId !== '') {
        $q = $db->prepare('SELECT hasil_json FROM absensi WHERE tap_id = ?');
        $q->execute([$tapId]);
        if ($lama = $q->fetchColumn()) {
            balas(200, json_decode($lama, true));
        }
    }

    // Tap biasa memakai jam server. Tap antrean (queued: true) memakai jam tap asli dari alat
    // (kalau tapped_at null, jam alat belum tersinkron → pakai waktu diterima).
    $waktu = time();
    if (!empty($body['queued']) && !empty($body['tapped_at'])) {
        $waktu = strtotime($body['tapped_at']) ?: $waktu;
    }

    $hasil = tentukanStatus($db, $rfid, $waktu);

    // Semua tap dicatat (termasuk unknown), supaya nomor kartu baru mudah dilihat admin.
    $db->prepare('INSERT INTO absensi (tap_id, rfid, device_id, jenis, waktu, queued, raw_json, hasil_json)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$tapId ?: null, $rfid, $deviceId, $hasil['status'], date('Y-m-d H:i:s', $waktu),
                  empty($body['queued']) ? 0 : 1, json_encode($body), json_encode($hasil)]);

    balas(200, $hasil);
}

// 5) GET /announcements — pengumuman untuk screensaver (opsional).
//    interval = detik per pengumuman (2–60), idle = screensaver muncul setelah alat diam sekian detik (5–600).
if ($metode === 'GET' && $aksi === 'announcements') {
    balas(200, ['ok' => true, 'interval' => 3, 'idle' => 30, 'items' => PENGUMUMAN]);
}

balas(404, ['ok' => false, 'message' => 'Endpoint tidak ada']);
