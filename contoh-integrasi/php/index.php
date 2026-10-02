<?php
// =============================================================================
// Contoh server Absensi RFID Terintegrasi — PHP 8 + SQLite, satu file, tanpa framework.
//
// Server ini berisi dua API:
//   (A) API alat  — dipanggil alat absensi (header X-API-Key + X-Device-ID).
//                   Spesifikasi lengkap: ../../doc/spesifikasi-api.md
//   (B) API admin — dipanggil aplikasi/panel admin Anda (header X-Admin-Key), semuanya JSON.
//                   Contoh cara membangun 6 fitur: kartu belum terdaftar, rekap, alat,
//                   firmware, pengumuman, dan pengaturan.
//
// Jalankan:   php -S 0.0.0.0:8080 index.php
// Di alat:    Pengaturan → Server → Base URL = http://<ip-laptop>:8080
//                                   API key  = api_key di pengaturan (awal: ganti-dengan-kunci-anda)
// Admin:      curl -H "X-Admin-Key: ganti-kunci-admin" http://localhost:8080/admin/settings
//             (prefix juga boleh, mis. http://localhost:8080/api/absensi/admin/settings)
//
// File data.sqlite (database) dan folder firmware/ (file .bin hasil unggah) dibuat otomatis.
// Database dari versi lama diperbarui otomatis: tabel & kolom baru ditambahkan saat dijalankan.
// =============================================================================

const ADMIN_KEY = 'ganti-kunci-admin';      // kunci API admin (header X-Admin-Key). GANTI sebelum dipakai sungguhan!
date_default_timezone_set('Asia/Jakarta');  // WIB. Ganti 'Asia/Makassar' (WITA) / 'Asia/Jayapura' (WIT)

// Pengaturan lain disimpan di database dan bisa diubah lewat PUT /admin/settings.
// Ini nilai awalnya (dipakai saat database baru dibuat):
const PENGATURAN_AWAL = [
    'api_key'              => 'ganti-dengan-kunci-anda', // API key yang diisi di alat (header X-API-Key)
    'title'                => 'Aplikasi Contoh',         // judul di layar utama alat (config.title, 1–30 karakter)
    'dim_after'            => 60,      // layar alat meredup setelah diam sekian detik (0 = tidak pernah, atau 10–3600)
    'dim_level'            => 20,      // kecerahan layar saat redup, persen (0–100)
    'screensaver_interval' => 3,       // detik per pengumuman di screensaver (2–60)
    'screensaver_idle'     => 30,      // screensaver muncul setelah alat diam sekian detik (5–600)
    'duplicate_window'     => 60,      // detik: tap ulang dalam jeda ini dianggap "duplicate" (0–3600)
    'default_restart_at'   => '03:00', // jam restart harian untuk alat yang belum diatur sendiri ("HH:MM", "" = mati)
];

const FOLDER_FIRMWARE = __DIR__ . '/firmware'; // file .bin hasil unggah: firmware/<versi>.bin
const MAKS_FIRMWARE   = 1966080;               // byte, ukuran slot OTA partisi min_spiffs

// Kode ikon pengumuman yang dikenal alat.
const IKON = ['info', 'pengumuman', 'kalender', 'jam', 'peringatan', 'rapat', 'libur', 'selamat', 'kesehatan', 'buku'];

// Arti kode error yang tampil di layar alat (raw.error di heartbeat).
const ARTI_ERROR = [
    'E10' => 'WiFi tidak ditemukan',          'E11' => 'Password WiFi salah',
    'E12' => 'WiFi gagal tersambung',         'E13' => 'WiFi tidak memberi alamat IP',
    'E20' => 'Server tidak bisa dihubungi',   'E21' => 'API key salah',
    'E22' => 'Alat ditolak server',           'E23' => 'Alamat API salah',
    'E24' => 'Server error / sibuk',          'E25' => 'Balasan server tidak valid',
    'E26' => 'Server terlalu lama menjawab',  'E30' => 'Pembaca RFID tidak terdeteksi',
    'E31' => 'Jam belum sinkron',
];

// Arti raw.reset_reason (alasan alat menyala ulang).
const ALASAN_RESET = [
    'poweron'  => 'baru dinyalakan / tombol EN',
    'external' => 'reset dari luar',
    'software' => 'restart oleh program',
    'panic'    => 'program error',
    'watchdog' => 'watchdog (program macet)',
    'brownout' => 'listrik turun (brownout)',
    'deepsleep' => 'bangun dari tidur',
    'other'    => 'lainnya',
];

// ---------- Database (dibuat & diperbarui otomatis) ----------------------------
$db = new PDO('sqlite:' . __DIR__ . '/data.sqlite', null, null, [PDO::ATTR_TIMEOUT => 5]);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Tabel yang sudah ada SEBELUM dijalankan (untuk tahu mana yang baru dibuat → isi data contoh).
$tabelLama = $db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);

$db->exec("
    CREATE TABLE IF NOT EXISTS karyawan (        -- anggota: karyawan / siswa pemilik kartu
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
    CREATE INDEX IF NOT EXISTS absensi_waktu ON absensi (waktu);
    CREATE TABLE IF NOT EXISTS alat (
        device_id          TEXT PRIMARY KEY,
        terakhir_terlihat  TEXT,     -- ISO 8601, request terakhir apa pun dari alat ini
        firmware           TEXT,
        ip                 TEXT,
        rssi               INTEGER,
        raw_json           TEXT,     -- isi heartbeat terakhir apa adanya
        -- kolom di bawah ini ditambahkan di versi berikutnya (lihat migrasi di bawah)
        nama               TEXT,     -- nama bebas dari admin (maks. 40 karakter), mis. 'Pintu depan'
        wifi_ssid          TEXT,
        uptime_s           INTEGER,  -- dari heartbeat (raw.*) ...
        reset_reason       TEXT,
        rfid_ok            INTEGER,  -- 1 / 0 / NULL
        queue              INTEGER,
        free_heap          INTEGER,
        min_free_heap      INTEGER,
        error              TEXT,     -- kode error di layar alat, mis. 'E30'; NULL = tidak ada
        ota_failed         TEXT,     -- versi firmware yang gagal dipasang; NULL = tidak ada
        heartbeat_terakhir INTEGER,  -- unix time heartbeat terakhir (untuk mendeteksi restart)
        pin                TEXT,     -- PIN menu Pengaturan khusus alat ini; NULL = tidak dikirim
        restart_at         TEXT,     -- NULL = pakai default_restart_at, '' = mati, 'HH:MM'
        firmware_target    TEXT,     -- versi firmware yang dijadwalkan; NULL = tidak update
        tap_mode           TEXT,     -- mode absen dari server: NULL = ikuti pengaturan di alat, 'auto', 'select'
        tap_mode_alat      TEXT,     -- mode absen yang sedang dipakai alat (raw.tap_mode di heartbeat)
        tap_select         TEXT      -- pilihan DATANG/PULANG di alat: 'check_in' / 'check_out' / NULL
    );
    CREATE TABLE IF NOT EXISTS event_alat (    -- riwayat kejadian per alat (maks. 200 per alat)
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        device_id   TEXT,
        jenis       TEXT,     -- boot / crash / firmware / ota_failed
        pesan       TEXT,
        detail_json TEXT,
        waktu       INTEGER   -- unix time
    );
    CREATE INDEX IF NOT EXISTS event_alat_device ON event_alat (device_id, id);
    CREATE TABLE IF NOT EXISTS firmware (      -- file .bin yang diunggah admin
        versi        TEXT PRIMARY KEY,  -- '1.5.1'
        ukuran       INTEGER,
        md5          TEXT,
        catatan      TEXT,
        diunggah_pada TEXT              -- ISO 8601
    );
    CREATE TABLE IF NOT EXISTS pengumuman (    -- screensaver alat
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        judul     TEXT NOT NULL,     -- maks. 40 karakter
        deskripsi TEXT NOT NULL DEFAULT '', -- maks. 160 karakter
        ikon      TEXT NOT NULL DEFAULT 'info',
        aktif     INTEGER NOT NULL DEFAULT 1,
        urutan    INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS pengaturan (    -- pasangan kunci → nilai (JSON)
        kunci TEXT PRIMARY KEY,
        nilai TEXT
    );
");

// Migrasi ringan: database dari versi lama belum punya kolom-kolom baru tabel alat → tambahkan.
$kolomAlat = $db->query('PRAGMA table_info(alat)')->fetchAll(PDO::FETCH_COLUMN, 1);
foreach ([
    'nama' => 'TEXT', 'wifi_ssid' => 'TEXT', 'uptime_s' => 'INTEGER', 'reset_reason' => 'TEXT',
    'rfid_ok' => 'INTEGER', 'queue' => 'INTEGER', 'free_heap' => 'INTEGER', 'min_free_heap' => 'INTEGER',
    'error' => 'TEXT', 'ota_failed' => 'TEXT', 'heartbeat_terakhir' => 'INTEGER', 'pin' => 'TEXT',
    'restart_at' => 'TEXT', 'firmware_target' => 'TEXT', 'tap_mode' => 'TEXT', 'tap_mode_alat' => 'TEXT',
    'tap_select' => 'TEXT',
] as $kolom => $tipe) {
    if (!in_array($kolom, $kolomAlat, true)) {
        $db->exec("ALTER TABLE alat ADD COLUMN $kolom $tipe");
    }
}

// Pengaturan yang belum ada di database diisi nilai awal (pengaturan lama tidak ditimpa).
$isi = $db->prepare('INSERT OR IGNORE INTO pengaturan (kunci, nilai) VALUES (?, ?)');
foreach (PENGATURAN_AWAL as $kunci => $nilai) {
    $isi->execute([$kunci, json_encode($nilai)]);
}

// Contoh data anggota, hanya saat tabelnya baru dibuat.
if (!in_array('karyawan', $tabelLama, true)) {
    $db->exec("INSERT INTO karyawan (rfid, nama, foto_url, aktif) VALUES
        ('0218893066', 'Budi Santoso', NULL, 1),
        ('0012345678', 'Siti Aminah',  NULL, 1),
        ('0055555555', 'Rina Kurnia',  NULL, 0)"); // contoh kartu nonaktif → "rejected"
}

// Contoh pengumuman, hanya saat tabelnya baru dibuat.
if (!in_array('pengumuman', $tabelLama, true)) {
    $db->exec("INSERT INTO pengumuman (judul, deskripsi, ikon, aktif, urutan) VALUES
        ('Rapat Guru', 'Hari ini pukul 13.00 di aula lantai 2.', 'rapat', 1, 0),
        ('Libur Nasional', 'Kamis, 2 Oktober 2026 kantor tutup.', 'libur', 1, 0)");
    gantiRevPengumuman($db);
}

// =============================================================================
// ATURAN BISNIS — bagian inilah yang biasanya Anda ubah sesuai kebutuhan.
// Menentukan hasil tap kartu; hasilnya langsung menjadi respons /tap, /check-in, dan /check-out.
//   $mode = null        → POST /tap       (mode absen "Otomatis": server yang menentukan masuk/pulang)
//   $mode = 'check_in'  → POST /check-in  (mode "Pilih Datang/Pulang", petugas memilih DATANG di alat)
//   $mode = 'check_out' → POST /check-out (mode "Pilih Datang/Pulang", petugas memilih PULANG di alat)
// =============================================================================
function tentukanStatus(PDO $db, string $rfid, int $waktu, ?string $mode = null): array
{
    $jedaDobel = pengaturan($db)['duplicate_window']; // detik, bisa diubah lewat PUT /admin/settings
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

    // Tap yang diterima (masuk/pulang) pada hari yang sama, dari yang paling awal.
    // Tap dari /tap, /check-in, dan /check-out tersimpan di tabel yang sama (rekap tetap satu).
    $q = $db->prepare("SELECT jenis, waktu FROM absensi
                       WHERE rfid = ? AND jenis IN ('check_in', 'check_out') AND substr(waktu, 1, 10) = ?
                       ORDER BY waktu");
    $q->execute([$rfid, date('Y-m-d', $waktu)]);
    $hariIni = $q->fetchAll();
    $jamTap  = fn(array $a) => date('H:i', strtotime($a['waktu']));
    $masuk   = array_values(array_filter($hariIni, fn($a) => $a['jenis'] === 'check_in'))[0] ?? null; // check_in pertama

    // a) Mode DATANG (/check-in): satu kali per hari. Sudah datang → "duplicate" dengan jam datang pertama.
    if ($mode === 'check_in') {
        if ($masuk) {
            return ['ok' => true, 'status' => 'duplicate', 'message' => 'Sudah absen datang']
                 + array_replace($dasar, ['time' => $jamTap($masuk)]);
        }
        return ['ok' => true, 'status' => 'check_in', 'message' => 'Selamat datang'] + $dasar;
    }

    // b) Mode PULANG (/check-out): boleh berkali-kali (yang terakhir dipakai rekap), kecuali dalam jeda tap ganda.
    if ($mode === 'check_out') {
        foreach (array_reverse($hariIni) as $a) {
            if ($a['jenis'] === 'check_out' && abs($waktu - strtotime($a['waktu'])) < $jedaDobel) {
                return ['ok' => true, 'status' => 'duplicate', 'message' => 'Sudah tercatat']
                     + array_replace($dasar, ['time' => $jamTap($a)]);
            }
        }
        return ['ok' => true, 'status' => 'check_out', 'message' => 'Hati-hati di jalan'] + $dasar
             + ['info' => [$masuk ? 'Masuk tadi ' . $jamTap($masuk) : 'Belum absen datang hari ini']];
    }

    // c) Otomatis (/tap): tap pertama hari itu = masuk, tap berikutnya = pulang.
    if (!$hariIni) {
        return ['ok' => true, 'status' => 'check_in', 'message' => 'Selamat datang'] + $dasar;
    }
    $terakhir = end($hariIni);
    if (abs($waktu - strtotime($terakhir['waktu'])) < $jedaDobel) {
        // "time" = jam tap yang sudah tercatat sebelumnya
        return ['ok' => true, 'status' => 'duplicate', 'message' => 'Sudah tercatat']
             + array_replace($dasar, ['time' => $jamTap($terakhir)]);
    }
    // Contoh "info": maks. 2 baris, masing-masing ±40 karakter.
    return ['ok' => true, 'status' => 'check_out', 'message' => 'Hati-hati di jalan'] + $dasar
         + ['info' => ['Masuk tadi ' . $jamTap($hariIni[0])]];
}

// ---------- Fungsi bantu umum -------------------------------------------------

// Kirim JSON lalu selesai.
function balas(int $kodeHttp, array $data): never
{
    http_response_code($kodeHttp);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Validasi gagal → HTTP 422. "message" = pesan kesalahan pertama, "errors" = semua per field.
function gagalValidasi(array $errors): never
{
    balas(422, ['ok' => false, 'message' => reset($errors), 'errors' => $errors]);
}

// Waktu ISO 8601 dengan offset, mis. 2026-10-02T07:15:00+07:00. Menerima unix time atau teks waktu.
function iso(int|string|null $waktu): ?string
{
    if ($waktu === null || $waktu === '') {
        return null;
    }
    return date('c', is_int($waktu) ? $waktu : strtotime($waktu));
}

// Teks (dipangkas) atau null. Angka diubah jadi teks, teks kosong jadi null.
function teksAtauNull(mixed $v): ?string
{
    if (is_int($v) || is_float($v)) {
        return (string)$v;
    }
    if (!is_string($v) || trim($v) === '') {
        return null;
    }
    return trim($v);
}

// Bilangan bulat, atau null kalau bukan bilangan bulat (teks "12" juga diterima).
function angkaBulat(mixed $v): ?int
{
    if (is_int($v)) {
        return $v;
    }
    if (is_float($v) && floor($v) == $v) {
        return (int)$v;
    }
    if (is_string($v) && preg_match('/^-?\d+$/', trim($v))) {
        return (int)trim($v);
    }
    return null;
}

// true/false, atau null kalau bukan boolean (1/0 dan "true"/"false" juga diterima).
function benarSalah(mixed $v): ?bool
{
    if (is_bool($v)) {
        return $v;
    }
    return match ($v) {
        1, '1', 'true' => true,
        0, '0', 'false' => false,
        default => null,
    };
}

// Jumlah karakter (huruf, bukan byte).
function panjang(string $s): int
{
    return mb_strlen($s, 'UTF-8');
}

// Jam "HH:MM" 00:00–23:59.
function jamValid(mixed $v): bool
{
    return is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1;
}

// Tanggal "YYYY-MM-DD" yang benar-benar ada.
function tanggalValid(mixed $v): bool
{
    return is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) === 1
        && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

// Body JSON objek untuk API admin. Body kosong = {}. Lebih dari 1 MB → 413, bukan JSON objek → 400.
function bodyJson(string $mentah): array
{
    if (strlen($mentah) > 1024 * 1024) {
        balas(413, ['ok' => false, 'message' => 'Body terlalu besar (maks. 1 MB)']);
    }
    if (trim($mentah) === '') {
        return [];
    }
    $b = json_decode($mentah, true);
    if (!is_array($b) || ($b !== [] && array_is_list($b))) {
        balas(400, ['ok' => false, 'message' => 'Body harus JSON berupa objek {...}']);
    }
    return $b;
}

// UPDATE sebagian kolom: $data = [kolom => nilai]. Nama kolom selalu dari kode, bukan dari request.
function perbarui(PDO $db, string $tabel, array $data, string $kolomKunci, string|int $kunci): void
{
    if (!$data) {
        return;
    }
    $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($data)));
    $db->prepare("UPDATE $tabel SET $set WHERE $kolomKunci = ?")
       ->execute([...array_values($data), $kunci]);
}

// ---------- Pengaturan --------------------------------------------------------

// Semua pengaturan (nilai awal ditimpa nilai di database).
function pengaturan(PDO $db): array
{
    $hasil = PENGATURAN_AWAL;
    foreach ($db->query('SELECT kunci, nilai FROM pengaturan') as $r) {
        if (array_key_exists($r['kunci'], $hasil)) {
            $hasil[$r['kunci']] = json_decode($r['nilai'], true);
        }
    }
    return $hasil;
}

function simpanPengaturan(PDO $db, string $kunci, mixed $nilai): void
{
    $db->prepare('INSERT OR REPLACE INTO pengaturan (kunci, nilai) VALUES (?, ?)')
       ->execute([$kunci, json_encode($nilai, JSON_UNESCAPED_UNICODE)]);
}

// Penanda versi daftar pengumuman (config.announcements_rev). Disimpan di tabel pengaturan.
function revPengumuman(PDO $db): string
{
    $q = $db->prepare("SELECT nilai FROM pengaturan WHERE kunci = 'announcements_rev'");
    $q->execute();
    $nilai = $q->fetchColumn();
    return $nilai === false ? '0' : (string)json_decode($nilai, true);
}

// Dipanggil setiap pengumuman berubah. Rev baru = "<jumlah aktif>-<unix time>"; dijamin berbeda
// dari rev lama walau ada dua perubahan dalam detik yang sama → alat mengambil ulang dalam ±1 menit.
function gantiRevPengumuman(PDO $db): string
{
    $aktif = (int)$db->query('SELECT COUNT(*) FROM pengumuman WHERE aktif = 1')->fetchColumn();
    $lama  = (int)(explode('-', revPengumuman($db))[1] ?? 0);
    $rev   = $aktif . '-' . max(time(), $lama + 1);
    simpanPengaturan($db, 'announcements_rev', $rev);
    return $rev;
}

// ---------- Alat --------------------------------------------------------------

function ambilAlat(PDO $db, string $deviceId): ?array
{
    $q = $db->prepare('SELECT * FROM alat WHERE device_id = ?');
    $q->execute([$deviceId]);
    return $q->fetch() ?: null;
}

// Semua request alat: daftarkan alat (kalau baru) dan catat "terakhir terlihat",
// plus firmware/ip/rssi/wifi_ssid kalau dikirim (di body atau body.raw).
// Mengembalikan data alat SEBELUM diperbarui (null = alat baru).
function catatAlat(PDO $db, string $deviceId, array $body): ?array
{
    $lama = ambilAlat($db, $deviceId);
    $raw  = is_array($body['raw'] ?? null) ? $body['raw'] : [];
    $ambil = fn(string $k) => $body[$k] ?? $raw[$k] ?? null;

    $firmware = teksAtauNull($ambil('firmware'));
    if (!$lama) {
        $db->prepare('INSERT INTO alat (device_id) VALUES (?)')->execute([$deviceId]);
    }
    $db->prepare('UPDATE alat SET terakhir_terlihat = ?, firmware = COALESCE(?, firmware), ip = COALESCE(?, ip),
                  rssi = COALESCE(?, rssi), wifi_ssid = COALESCE(?, wifi_ssid) WHERE device_id = ?')
       ->execute([date('c'), $firmware, teksAtauNull($ambil('ip')), angkaBulat($ambil('rssi')),
                  teksAtauNull($ambil('wifi_ssid')), $deviceId]);

    // Versi firmware berubah (mis. setelah update) → catat di riwayat alat.
    if ($lama && $firmware !== null && $lama['firmware'] !== null && $lama['firmware'] !== $firmware) {
        catatEvent($db, $deviceId, 'firmware', "Firmware {$lama['firmware']} -> $firmware",
                   ['from' => $lama['firmware'], 'to' => $firmware]);
    }
    return $lama;
}

// Simpan satu kejadian di riwayat alat; hanya 200 kejadian terbaru per alat yang disimpan.
function catatEvent(PDO $db, string $deviceId, string $jenis, string $pesan, ?array $detail = null): void
{
    $db->prepare('INSERT INTO event_alat (device_id, jenis, pesan, detail_json, waktu) VALUES (?, ?, ?, ?, ?)')
       ->execute([$deviceId, $jenis, $pesan,
                  $detail === null ? null : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                  time()]);
    $db->prepare('DELETE FROM event_alat WHERE device_id = ? AND id NOT IN
                  (SELECT id FROM event_alat WHERE device_id = ? ORDER BY id DESC LIMIT 200)')
       ->execute([$deviceId, $deviceId]);
}

// Status update firmware alat: null (tidak dijadwalkan) / pending / installed / failed.
function statusFirmware(array $a): ?string
{
    $target = $a['firmware_target'];
    return match (true) {
        $target === null              => null,
        $a['firmware'] === $target    => 'installed',
        $a['ota_failed'] === $target  => 'failed',
        default                       => 'pending',
    };
}

// Data alat untuk API admin, termasuk daftar masalah ("issues") dalam bahasa sehari-hari.
function dataAlat(PDO $db, array $a): array
{
    $set      = pengaturan($db);
    $terlihat = $a['terakhir_terlihat'] ? strtotime($a['terakhir_terlihat']) : null;
    $diam     = $terlihat === null ? null : time() - $terlihat; // detik sejak request terakhir
    $rfidOk   = $a['rfid_ok'] === null ? null : (bool)$a['rfid_ok'];
    $rssi     = $a['rssi'] === null ? null : (int)$a['rssi'];
    $queue    = $a['queue'] === null ? null : (int)$a['queue'];
    $minHeap  = $a['min_free_heap'] === null ? null : (int)$a['min_free_heap'];

    $issues = [];
    if ($diam !== null && $diam > 600) {
        $issues[] = 'Offline sejak ' . iso($terlihat);
    }
    if ($rfidOk === false) {
        $issues[] = 'Pembaca RFID tidak terdeteksi (E30)';
    }
    if ($rssi !== null && $rssi < -80) {
        $issues[] = "Sinyal WiFi lemah ($rssi dBm)";
    }
    if ($queue !== null && $queue > 20) {
        $issues[] = "$queue tap menunggu di antrean alat";
    }
    if ($a['error'] !== null) {
        $issues[] = trim($a['error'] . ' ' . (ARTI_ERROR[$a['error']] ?? 'Kode error tidak dikenal'));
    }
    if ($a['ota_failed'] !== null) {
        $issues[] = "Update firmware {$a['ota_failed']} gagal, alat kembali ke " . ($a['firmware'] ?? '?');
    }
    // Restart tidak normal (watchdog/panic/brownout) yang sering dalam 24 jam terakhir.
    $q = $db->prepare("SELECT detail_json FROM event_alat WHERE device_id = ? AND jenis = 'boot' AND waktu >= ?");
    $q->execute([$a['device_id'], time() - 86400]);
    $tidakNormal = 0;
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $detail) {
        $alasan = json_decode((string)$detail, true)['reset_reason'] ?? null;
        $tidakNormal += in_array($alasan, ['watchdog', 'panic', 'brownout'], true) ? 1 : 0;
    }
    if ($tidakNormal >= 3) {
        $issues[] = "Sering restart tidak normal: {$tidakNormal}x dalam 24 jam";
    }
    if ($minHeap !== null && $minHeap < 20000) {
        $issues[] = "RAM hampir habis ($minHeap byte)";
    }

    return [
        'device_id'         => $a['device_id'],
        'name'              => $a['nama'],
        'online'            => $diam !== null && $diam < 180,
        'last_seen_at'      => iso($terlihat),
        'firmware'          => $a['firmware'],
        'ip'                => $a['ip'],
        'rssi'              => $rssi,
        'wifi_ssid'         => $a['wifi_ssid'],
        'uptime_s'          => $a['uptime_s'] === null ? null : (int)$a['uptime_s'],
        'reset_reason'      => $a['reset_reason'],
        'rfid_ok'           => $rfidOk,
        'queue'             => $queue,
        'free_heap'         => $a['free_heap'] === null ? null : (int)$a['free_heap'],
        'min_free_heap'     => $minHeap,
        'error'             => $a['error'],
        'ota_failed'        => $a['ota_failed'],
        'pin'               => $a['pin'],
        'restart_at'        => $a['restart_at'] ?? $set['default_restart_at'], // yang efektif dikirim ke alat
        'firmware_target'   => $a['firmware_target'],
        'firmware_state'    => statusFirmware($a),
        'tap_mode'          => $a['tap_mode'],       // pengaturan dari server: null = ikuti alat, "auto", "select"
        'reported_tap_mode' => $a['tap_mode_alat'],  // mode yang sedang dipakai alat (dari heartbeat)
        'tap_select'        => $a['tap_select'],     // pilihan DATANG/PULANG di alat saat ini (null = belum memilih)
        'issues'            => $issues,
    ];
}

// Pengaturan jarak jauh yang dikirim ke alat lewat /ping dan /heartbeat.
// $baseUrl = alamat API alat ini, mis. "http://192.168.1.10:8080/api/absensi" (untuk URL firmware).
function config(PDO $db, array $alat, string $baseUrl): array
{
    $set = pengaturan($db);
    $c = [
        'title'             => $set['title'],
        'dim_after'         => $set['dim_after'],
        'dim_level'         => $set['dim_level'],
        'announcements_rev' => revPengumuman($db),  // berubah → alat memanggil ulang GET /announcements
        'restart_at'        => $alat['restart_at'] ?? $set['default_restart_at'], // per alat; "" = mati
    ];
    if ($alat['pin'] !== null && $alat['pin'] !== '') {
        $c['pin'] = $alat['pin'];  // PIN khusus alat ini (hanya dikirim kalau diatur)
    }
    if ($alat['tap_mode'] !== null) {
        $c['tap_mode'] = $alat['tap_mode'];  // mode absen "auto"/"select" (hanya dikirim kalau diatur dari server)
    }
    // Update firmware: hanya kalau dijadwalkan, belum terpasang, dan belum pernah gagal di alat ini.
    $target = $alat['firmware_target'];
    if ($target !== null && $target !== $alat['firmware'] && $target !== $alat['ota_failed']) {
        $r = ambilRilis($db, $target);
        if ($r && is_file(fileFirmware($target))) {
            $c['firmware_update'] = [
                'version' => $r['versi'],
                'url'     => $baseUrl . '/firmware/' . rawurlencode($r['versi']),
                'size'    => (int)$r['ukuran'],
                'md5'     => $r['md5'],
            ];
        }
    }
    return $c;
}

// ---------- Firmware ----------------------------------------------------------

function fileFirmware(string $versi): string
{
    return FOLDER_FIRMWARE . '/' . $versi . '.bin';
}

function ambilRilis(PDO $db, string $versi): ?array
{
    $q = $db->prepare('SELECT * FROM firmware WHERE versi = ?');
    $q->execute([$versi]);
    return $q->fetch() ?: null;
}

function dataRilis(PDO $db, array $r): array
{
    $q = $db->prepare('SELECT COUNT(*) FROM alat WHERE firmware_target = ?');
    $q->execute([$r['versi']]);
    return [
        'version'     => $r['versi'],
        'size'        => (int)$r['ukuran'],
        'md5'         => $r['md5'],
        'notes'       => $r['catatan'],
        'uploaded_at' => $r['diunggah_pada'],
        'devices'     => (int)$q->fetchColumn(), // jumlah alat yang dijadwalkan ke versi ini
    ];
}

// ---------- Anggota & pengumuman ----------------------------------------------

function dataAnggota(array $k): array
{
    return ['rfid' => $k['rfid'], 'name' => $k['nama'], 'photo_url' => $k['foto_url'] ?: null,
            'active' => (bool)$k['aktif']];
}

function ambilAnggota(PDO $db, string $rfid): ?array
{
    $q = $db->prepare('SELECT * FROM karyawan WHERE rfid = ?');
    $q->execute([$rfid]);
    return $q->fetch() ?: null;
}

// Periksa isi body anggota. $baru = true untuk POST (rfid & name wajib).
// Mengembalikan [kolom database => nilai] atau langsung membalas 422.
function validasiAnggota(PDO $db, array $b, bool $baru): array
{
    $d = [];
    $e = [];
    if ($baru) {
        $rfid = is_string($b['rfid'] ?? null) || is_int($b['rfid'] ?? null) ? strtoupper(trim((string)$b['rfid'])) : '';
        if ($rfid === '') {
            $e['rfid'] = 'Nomor kartu (rfid) wajib diisi';
        } elseif (strlen($rfid) > 32 || !preg_match('/^[0-9A-F]+$/', $rfid)) {
            $e['rfid'] = 'Nomor kartu hanya boleh angka 0-9 dan huruf A-F, maks. 32 karakter';
        } elseif (ambilAnggota($db, $rfid)) {
            $e['rfid'] = "Nomor kartu $rfid sudah terdaftar";
        } else {
            $d['rfid'] = $rfid;
        }
    }
    if ($baru || array_key_exists('name', $b)) {
        $nama = is_string($b['name'] ?? null) ? trim($b['name']) : '';
        if ($nama === '' || panjang($nama) > 60) {
            $e['name'] = 'Nama wajib diisi, maks. 60 karakter';
        } else {
            $d['nama'] = $nama;
        }
    }
    if (array_key_exists('photo_url', $b)) {
        $foto = $b['photo_url'];
        if ($foto === null || $foto === '') {
            $d['foto_url'] = null;
        } elseif (is_string($foto) && preg_match('#^https?://\S+$#i', trim($foto)) && strlen($foto) <= 500) {
            $d['foto_url'] = trim($foto);
        } else {
            $e['photo_url'] = 'photo_url harus alamat http:// atau https:// (atau null)';
        }
    }
    if (array_key_exists('active', $b)) {
        $aktif = benarSalah($b['active']);
        if ($aktif === null) {
            $e['active'] = 'active harus true atau false';
        } else {
            $d['aktif'] = $aktif ? 1 : 0;
        }
    }
    if ($e) {
        gagalValidasi($e);
    }
    return $d;
}

function dataPengumuman(array $p): array
{
    return ['id' => (int)$p['id'], 'title' => $p['judul'], 'description' => $p['deskripsi'],
            'icon' => $p['ikon'], 'active' => (bool)$p['aktif'], 'order' => (int)$p['urutan']];
}

// Periksa isi body pengumuman. $baru = true untuk POST (title wajib, sisanya nilai bawaan).
function validasiPengumuman(array $b, bool $baru): array
{
    $d = $baru ? ['deskripsi' => '', 'ikon' => 'info', 'aktif' => 1, 'urutan' => 0] : [];
    $e = [];
    if ($baru || array_key_exists('title', $b)) {
        $judul = is_string($b['title'] ?? null) ? trim($b['title']) : '';
        if ($judul === '' || panjang($judul) > 40) {
            $e['title'] = 'Judul wajib diisi, maks. 40 karakter';
        } else {
            $d['judul'] = $judul;
        }
    }
    if (array_key_exists('description', $b)) {
        $isi = $b['description'] ?? '';
        if (!is_string($isi) || panjang(trim($isi)) > 160) {
            $e['description'] = 'Deskripsi maks. 160 karakter';
        } else {
            $d['deskripsi'] = trim($isi);
        }
    }
    if (array_key_exists('icon', $b)) {
        $ikon = $b['icon'] ?? '';
        if ($ikon === '') {
            $d['ikon'] = 'info';
        } elseif (!in_array($ikon, IKON, true)) {
            $e['icon'] = 'Ikon harus salah satu dari: ' . implode(', ', IKON);
        } else {
            $d['ikon'] = $ikon;
        }
    }
    if (array_key_exists('active', $b)) {
        $aktif = benarSalah($b['active']);
        if ($aktif === null) {
            $e['active'] = 'active harus true atau false';
        } else {
            $d['aktif'] = $aktif ? 1 : 0;
        }
    }
    if (array_key_exists('order', $b)) {
        $urutan = angkaBulat($b['order']);
        if ($urutan === null) {
            $e['order'] = 'order harus bilangan bulat';
        } else {
            $d['urutan'] = $urutan;
        }
    }
    if ($e) {
        gagalValidasi($e);
    }
    return $d;
}

// ---------- Permintaan masuk --------------------------------------------------
$metode = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$mentah = file_get_contents('php://input'); // isi body apa adanya (JSON, atau file .bin saat unggah firmware)

// Path yang mengandung "/admin/" → API admin (bagian B, paling bawah file ini).
$posAdmin = strpos($path, '/admin/');
if ($posAdmin !== false) {
    if (!hash_equals(ADMIN_KEY, $_SERVER['HTTP_X_ADMIN_KEY'] ?? '')) {
        balas(401, ['ok' => false, 'message' => 'Admin key salah']);
    }
    // "/api/absensi/admin/members/0218893066" → ['members', '0218893066']
    $segmen = array_map('rawurldecode',
        array_values(array_filter(explode('/', substr($path, $posAdmin + 7)), fn($s) => $s !== '')));
    apiAdmin($db, $metode, $segmen, $mentah);
}

// =============================================================================
// (A) API ALAT — sesuai ../../doc/spesifikasi-api.md
// =============================================================================
$aksi     = basename($path); // "/api/absensi/tap" → "tap"
$body     = json_decode($mentah, true);
$body     = is_array($body) ? $body : [];
$deviceId = $_SERVER['HTTP_X_DEVICE_ID'] ?? ($body['device_id'] ?? ''); // header menang atas body
// Header X-Spec-Version (saat ini "1") boleh diabaikan; berguna kalau nanti ada versi 2.

// 1) API key wajib cocok. Kunci salah → HTTP 401 (alat tidak memasukkannya ke antrean).
if (!hash_equals((string)pengaturan($db)['api_key'], $_SERVER['HTTP_X_API_KEY'] ?? '')) {
    balas(401, ['ok' => false, 'message' => 'API key salah']);
}

// ID alat wajib (alat selalu mengirim header X-Device-ID).
if (!is_string($deviceId) || trim($deviceId) === '') {
    balas(400, ['ok' => false, 'message' => 'Header X-Device-ID wajib']);
}
$deviceId = trim($deviceId);

// Setiap request alat: daftarkan alat otomatis & catat "terakhir terlihat".
$alatLama = catatAlat($db, $deviceId, $body);

// Alamat API alat ini = path request tanpa segmen terakhir ("/api/absensi/ping" → "/api/absensi"),
// dipakai untuk URL unduhan firmware. Di belakang reverse proxy (nginx, Cloudflare, ...) skemanya
// mengikuti header X-Forwarded-Proto kalau ada.
$skema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$proto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0]));
if ($proto === 'http' || $proto === 'https') {
    $skema = $proto;
}
$host    = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] . ':' . $_SERVER['SERVER_PORT']);
$baseUrl = $skema . '://' . $host . substr($path, 0, (int)strrpos($path, '/'));

// 2) GET /ping — tes koneksi & sinkron jam.
if ($metode === 'GET' && $aksi === 'ping') {
    balas(200, [
        'ok'          => true,
        'message'     => 'Terhubung ke ' . pengaturan($db)['title'],
        'server_time' => date('c'),            // contoh: 2026-09-30T07:45:12+07:00
        'config'      => config($db, ambilAlat($db, $deviceId), $baseUrl),
    ]);
}

// 3) POST /heartbeat — tiap 60 detik. Simpan kesehatan alat dari "raw" & catat kejadian penting.
if ($metode === 'POST' && $aksi === 'heartbeat') {
    $raw      = is_array($body['raw'] ?? null) ? $body['raw'] : [];
    $sekarang = time();
    $alat     = ambilAlat($db, $deviceId); // sudah berisi firmware terbaru
    $uptime   = angkaBulat($raw['uptime_s'] ?? null);
    $alasan   = teksAtauNull($raw['reset_reason'] ?? null);
    $rfidOk   = benarSalah($raw['rfid_ok'] ?? null);
    $otaGagal = teksAtauNull($raw['ota_failed'] ?? null);

    // a) Alat menyala ulang: uptime lebih kecil dari sebelumnya, atau lebih kecil dari lama jeda sejak
    //    heartbeat sebelumnya (alat mati lama lalu menyala lagi).
    if ($alatLama && $alatLama['heartbeat_terakhir'] !== null && $uptime !== null) {
        $uptimeLama = $alatLama['uptime_s'];
        if (($uptimeLama !== null && $uptime < (int)$uptimeLama)
            || $uptime < $sekarang - (int)$alatLama['heartbeat_terakhir']) {
            catatEvent($db, $deviceId, 'boot', 'Menyala ulang: ' . (ALASAN_RESET[$alasan] ?? 'lainnya'),
                       ['reset_reason' => $alasan, 'uptime_s' => $uptime]);
        }
    }

    // b) Laporan crash (dikirim ulang sampai heartbeat berhasil → abaikan yang sama dalam 10 menit).
    if (is_array($raw['crash'] ?? null)) {
        $crash = array_map(fn($v) => is_scalar($v) ? (string)$v : null, $raw['crash']);
        $q = $db->prepare("SELECT detail_json, waktu FROM event_alat WHERE device_id = ? AND jenis = 'crash'
                           ORDER BY id DESC LIMIT 1");
        $q->execute([$deviceId]);
        $terakhir = $q->fetch();
        $detailLama = $terakhir ? json_decode((string)$terakhir['detail_json'], true) : null;
        $sama = $terakhir && $sekarang - (int)$terakhir['waktu'] < 600
             && ($detailLama['pc'] ?? null) === ($crash['pc'] ?? null)
             && ($detailLama['backtrace'] ?? null) === ($crash['backtrace'] ?? null);
        if (!$sama) {
            catatEvent($db, $deviceId, 'crash',
                       'Program crash di ' . ($crash['task'] ?? '?') . ' (PC ' . ($crash['pc'] ?? '?') . ')', $crash);
        }
    }

    // c) Update firmware gagal (versi baru gagal menyala, alat kembali ke versi lama).
    if ($otaGagal !== null && $otaGagal !== ($alatLama['ota_failed'] ?? null)) {
        catatEvent($db, $deviceId, 'ota_failed',
                   "Update firmware $otaGagal gagal, alat kembali ke " . ($alat['firmware'] ?? '?'),
                   ['version' => $otaGagal, 'firmware' => $alat['firmware']]);
    }

    perbarui($db, 'alat', [
        'uptime_s'           => $uptime,
        'reset_reason'       => $alasan,
        'rfid_ok'            => $rfidOk === null ? null : (int)$rfidOk,
        'queue'              => angkaBulat($raw['queue'] ?? null),
        'free_heap'          => angkaBulat($raw['free_heap'] ?? null),
        'min_free_heap'      => angkaBulat($raw['min_free_heap'] ?? null),
        'error'              => teksAtauNull($raw['error'] ?? null),  // tidak dikirim = tidak ada error
        'ota_failed'         => $otaGagal,
        'heartbeat_terakhir' => $sekarang,
        // Mode absen (firmware 1.6.0 ke atas; versi lama tidak mengirim → NULL).
        'tap_mode_alat'      => in_array($raw['tap_mode'] ?? null, ['auto', 'select'], true) ? $raw['tap_mode'] : null,
        'tap_select'         => in_array($raw['tap_select'] ?? null, ['check_in', 'check_out'], true) ? $raw['tap_select'] : null,
        'raw_json'           => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ], 'device_id', $deviceId);

    balas(200, ['ok' => true, 'server_time' => date('c'),
                'config' => config($db, ambilAlat($db, $deviceId), $baseUrl)]);
}

// 4) POST /tap — kartu ditempelkan (mode absen "Otomatis": server yang menentukan masuk/pulang).
//    POST /check-in & /check-out — sama persis dengan /tap, dipakai alat di mode absen "Pilih Datang/Pulang"
//    (petugas memilih DATANG atau PULANG di layar alat). Body-nya membawa "mode": "check_in"/"check_out";
//    yang menentukan di sini adalah endpoint-nya. Aplikasi yang tidak butuh mode ini cukup tidak membuatnya.
$modeEndpoint = ['tap' => null, 'check-in' => 'check_in', 'check-out' => 'check_out'];
if ($metode === 'POST' && array_key_exists($aksi, $modeEndpoint)) {
    $rfid = strtoupper(trim((string)($body['rfid'] ?? '')));
    if ($rfid === '') {
        balas(400, ['ok' => false, 'message' => 'rfid kosong']); // format salah → 400
    }

    // Tap yang sama bisa terkirim ulang dari antrean alat (tap_id sama).
    // Jangan dicatat dua kali: kirim lagi respons yang dulu (dari endpoint mana pun: /tap, /check-in, /check-out).
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

    $hasil = tentukanStatus($db, $rfid, $waktu, $modeEndpoint[$aksi]);

    // Semua tap dicatat (termasuk unknown), supaya nomor kartu baru muncul di "kartu belum terdaftar".
    $db->prepare('INSERT INTO absensi (tap_id, rfid, device_id, jenis, waktu, queued, raw_json, hasil_json)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$tapId ?: null, $rfid, $deviceId, $hasil['status'], date('Y-m-d H:i:s', $waktu),
                  empty($body['queued']) ? 0 : 1, json_encode($body), json_encode($hasil)]);

    balas(200, $hasil);
}

// 5) GET /announcements — pengumuman untuk screensaver (opsional).
//    interval = detik per pengumuman (2–60), idle = screensaver muncul setelah alat diam sekian detik (5–600).
if ($metode === 'GET' && $aksi === 'announcements') {
    $set   = pengaturan($db);
    $items = [];
    foreach ($db->query('SELECT * FROM pengumuman WHERE aktif = 1 ORDER BY urutan, id LIMIT 10') as $p) {
        $items[] = ['id' => (string)$p['id'], 'title' => $p['judul'], 'description' => $p['deskripsi'],
                    'icon' => $p['ikon']];
    }
    balas(200, ['ok' => true, 'interval' => $set['screensaver_interval'], 'idle' => $set['screensaver_idle'],
                'items' => $items]);
}

// 6) GET {base}/firmware/{versi} — alat mengunduh file .bin (lihat config.firmware_update).
//    Hanya untuk alat yang dijadwalkan ke versi itu.
if ($metode === 'GET' && preg_match('#/firmware/([^/]+)$#', $path, $m)) {
    $versi = rawurldecode($m[1]);
    $alat  = ambilAlat($db, $deviceId);
    $rilis = $alat['firmware_target'] === $versi ? ambilRilis($db, $versi) : null;
    $file  = fileFirmware($versi);
    if (!$rilis || !is_file($file)) {
        balas(404, ['ok' => false, 'message' => 'Firmware tidak tersedia untuk alat ini']);
    }
    // Kirim isi file apa adanya: buang buffer output, jangan ada teks lain sebelum/sesudahnya.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(200);
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($file));
    header('x-MD5: ' . $rilis['md5']);
    readfile($file);
    exit;
}

balas(404, ['ok' => false, 'message' => 'Endpoint tidak ada']);

// =============================================================================
// (B) API ADMIN — dipanggil aplikasi/panel admin (header X-Admin-Key), semuanya JSON.
//     $seg = segmen path sesudah "/admin/", mis. ['members', '0218893066'].
//     Sukses → 200 (data baru → 201), validasi gagal → 422, tidak ditemukan → 404.
// =============================================================================
function apiAdmin(PDO $db, string $metode, array $seg, string $mentah): never
{
    $n = count($seg);
    [$s0, $s1, $s2] = $seg + [null, null, null];

    // ---------- Pengaturan ---------------------------------------------------
    if ($s0 === 'settings' && $n === 1) {
        if ($metode === 'GET') {
            balas(200, ['ok' => true, 'settings' => pengaturan($db)]);
        }
        if ($metode === 'PUT') {
            $b = bodyJson($mentah);
            $baru = [];
            $e = [];
            if (array_key_exists('title', $b)) {
                $v = is_string($b['title']) ? trim($b['title']) : '';
                if ($v === '' || panjang($v) > 30) {
                    $e['title'] = 'Judul wajib diisi, 1-30 karakter';
                } else {
                    $baru['title'] = $v;
                }
            }
            // Angka: [nilai sah?, pesan kalau salah]
            $aturan = [
                'dim_after'            => [fn($v) => $v === 0 || ($v >= 10 && $v <= 3600), 'dim_after harus 0 atau 10-3600 (detik)'],
                'dim_level'            => [fn($v) => $v >= 0 && $v <= 100, 'dim_level harus 0-100 (persen)'],
                'screensaver_interval' => [fn($v) => $v >= 2 && $v <= 60, 'screensaver_interval harus 2-60 (detik)'],
                'screensaver_idle'     => [fn($v) => $v >= 5 && $v <= 600, 'screensaver_idle harus 5-600 (detik)'],
                'duplicate_window'     => [fn($v) => $v >= 0 && $v <= 3600, 'duplicate_window harus 0-3600 (detik)'],
            ];
            foreach ($aturan as $kunci => [$sah, $pesan]) {
                if (array_key_exists($kunci, $b)) {
                    $v = angkaBulat($b[$kunci]);
                    if ($v === null || !$sah($v)) {
                        $e[$kunci] = $pesan;
                    } else {
                        $baru[$kunci] = $v;
                    }
                }
            }
            if (array_key_exists('default_restart_at', $b)) {
                $v = $b['default_restart_at'];
                if ($v === '' || jamValid($v)) {
                    $baru['default_restart_at'] = $v;
                } else {
                    $e['default_restart_at'] = 'default_restart_at harus "HH:MM" (00:00-23:59) atau "" (mati)';
                }
            }
            if (array_key_exists('api_key', $b)) {
                $v = $b['api_key'];
                if (is_string($v) && preg_match('/^[A-Za-z0-9._-]{8,64}$/', $v)) {
                    $baru['api_key'] = $v;
                } else {
                    $e['api_key'] = 'API key harus 8-64 karakter: huruf, angka, titik, garis bawah, atau tanda minus';
                }
            }
            if ($e) {
                gagalValidasi($e);
            }
            foreach ($baru as $kunci => $v) {
                simpanPengaturan($db, $kunci, $v);
            }
            balas(200, ['ok' => true, 'settings' => pengaturan($db)]);
        }
    }

    // ---------- Anggota ------------------------------------------------------
    if ($s0 === 'members') {
        if ($n === 1 && $metode === 'GET') {
            $anggota = array_map('dataAnggota', $db->query('SELECT * FROM karyawan ORDER BY nama, rfid')->fetchAll());
            balas(200, ['ok' => true, 'members' => $anggota]);
        }
        if ($n === 1 && $metode === 'POST') {
            $d = validasiAnggota($db, bodyJson($mentah), true);
            $d += ['foto_url' => null, 'aktif' => 1];
            $db->prepare('INSERT INTO karyawan (rfid, nama, foto_url, aktif) VALUES (?, ?, ?, ?)')
               ->execute([$d['rfid'], $d['nama'], $d['foto_url'], $d['aktif']]);
            balas(201, ['ok' => true, 'member' => dataAnggota(ambilAnggota($db, $d['rfid']))]);
        }
        if ($n === 2 && in_array($metode, ['PUT', 'DELETE'], true)) {
            $rfid = strtoupper(trim($s1));
            if (!ambilAnggota($db, $rfid)) {
                balas(404, ['ok' => false, 'message' => 'Anggota tidak ditemukan']);
            }
            if ($metode === 'PUT') {
                perbarui($db, 'karyawan', validasiAnggota($db, bodyJson($mentah), false), 'rfid', $rfid);
                balas(200, ['ok' => true, 'member' => dataAnggota(ambilAnggota($db, $rfid))]);
            }
            $db->prepare('DELETE FROM karyawan WHERE rfid = ?')->execute([$rfid]);
            balas(200, ['ok' => true]);
        }
    }

    // ---------- Kartu belum terdaftar -----------------------------------------
    // Nomor kartu yang pernah di-tap dengan hasil "unknown" dan SEKARANG belum ada di anggota.
    if ($s0 === 'unknown-cards' && $n === 1 && $metode === 'GET') {
        $baris = $db->query("
            SELECT a.rfid, COUNT(*) AS taps, MIN(a.waktu) AS pertama, MAX(a.waktu) AS terakhir,
                   (SELECT b.device_id FROM absensi b WHERE b.rfid = a.rfid AND b.jenis = 'unknown'
                    ORDER BY b.waktu DESC, b.id DESC LIMIT 1) AS alat_terakhir
            FROM absensi a
            WHERE a.jenis = 'unknown' AND a.rfid NOT IN (SELECT rfid FROM karyawan)
            GROUP BY a.rfid
            ORDER BY terakhir DESC")->fetchAll();
        $kartu = array_map(fn($r) => [
            'rfid'           => $r['rfid'],
            'taps'           => (int)$r['taps'],
            'first_seen_at'  => iso($r['pertama']),
            'last_seen_at'   => iso($r['terakhir']),
            'last_device_id' => $r['alat_terakhir'],
        ], $baris);
        balas(200, ['ok' => true, 'cards' => $kartu]);
    }

    // ---------- Rekap ---------------------------------------------------------
    // Per anggota per hari: jam masuk (check_in pertama), jam pulang (check_out terakhir), durasi.
    if ($s0 === 'report' && $n === 1 && $metode === 'GET') {
        $dari   = $_GET['from'] ?? date('Y-m-d');
        $sampai = $_GET['to'] ?? date('Y-m-d');
        $e = [];
        if (!tanggalValid($dari)) {
            $e['from'] = 'from harus tanggal YYYY-MM-DD';
        }
        if (!tanggalValid($sampai)) {
            $e['to'] = 'to harus tanggal YYYY-MM-DD';
        }
        if (!$e) {
            $hari = (int)(new DateTime($dari))->diff(new DateTime($sampai))->format('%r%a') + 1;
            if ($hari < 1) {
                $e['to'] = 'to tidak boleh sebelum from';
            } elseif ($hari > 92) {
                $e['to'] = 'Rentang rekap maksimal 92 hari';
            }
        }
        if ($e) {
            gagalValidasi($e);
        }

        $q = $db->prepare("
            SELECT substr(a.waktu, 1, 10) AS tanggal, a.rfid,
                   COALESCE(k.nama, MAX(json_extract(a.hasil_json, '$.name'))) AS nama, -- anggota dihapus → nama saat tap
                   MIN(CASE WHEN a.jenis = 'check_in'  THEN a.waktu END) AS masuk,
                   MAX(CASE WHEN a.jenis = 'check_out' THEN a.waktu END) AS pulang,
                   COUNT(*) AS taps
            FROM absensi a
            LEFT JOIN karyawan k ON k.rfid = a.rfid
            WHERE a.jenis IN ('check_in', 'check_out', 'duplicate') AND a.waktu BETWEEN ? AND ?
            GROUP BY tanggal, a.rfid
            ORDER BY tanggal, COALESCE(k.nama, MAX(json_extract(a.hasil_json, '$.name')), a.rfid), a.rfid");
        $q->execute(["$dari 00:00:00", "$sampai 23:59:59"]);
        $rows = array_map(fn($r) => [
            'date'             => $r['tanggal'],
            'rfid'             => $r['rfid'],
            'name'             => $r['nama'],  // anggota sudah dihapus → nama dari balasan tap (atau null)
            'check_in'         => $r['masuk'] ? substr($r['masuk'], 11, 5) : null,
            'check_out'        => $r['pulang'] ? substr($r['pulang'], 11, 5) : null,
            // (null kalau salah satu kosong, atau pulang lebih awal dari masuk karena tap antrean terlambat)
            'duration_minutes' => $r['masuk'] && $r['pulang'] && $r['pulang'] >= $r['masuk']
                ? intdiv(strtotime($r['pulang']) - strtotime($r['masuk']), 60) : null,
            'taps'             => (int)$r['taps'],
        ], $q->fetchAll());

        if (($_GET['format'] ?? '') === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"rekap-$dari-$sampai.csv\"");
            // Satu nilai CSV: diberi tanda kutip hanya kalau berisi koma, kutip, atau baris baru.
            $sel = fn($v) => preg_match('/[",\r\n]/', (string)$v) ? '"' . str_replace('"', '""', (string)$v) . '"' : (string)$v;
            echo "tanggal,rfid,nama,masuk,pulang,durasi_menit,jumlah_tap\r\n";
            foreach ($rows as $r) {
                echo implode(',', array_map($sel, [$r['date'], $r['rfid'], $r['name'], $r['check_in'], $r['check_out'],
                                                   $r['duration_minutes'], $r['taps']])) . "\r\n";
            }
            exit;
        }
        balas(200, ['ok' => true, 'from' => $dari, 'to' => $sampai, 'rows' => $rows]);
    }

    // Semua tap pada satu hari (terbaru dulu), termasuk unknown/rejected.
    if ($s0 === 'attendances' && $n === 1 && $metode === 'GET') {
        $tanggal = $_GET['date'] ?? date('Y-m-d');
        if (!tanggalValid($tanggal)) {
            gagalValidasi(['date' => 'date harus tanggal YYYY-MM-DD']);
        }
        $q = $db->prepare('SELECT a.*, COALESCE(k.nama, json_extract(a.hasil_json, \'$.name\')) AS nama
                           FROM absensi a LEFT JOIN karyawan k ON k.rfid = a.rfid
                           WHERE a.waktu BETWEEN ? AND ? ORDER BY a.waktu DESC, a.id DESC');
        $q->execute(["$tanggal 00:00:00", "$tanggal 23:59:59"]);
        $items = array_map(fn($r) => [
            'id'        => (int)$r['id'],
            'time'      => iso($r['waktu']),
            'rfid'      => $r['rfid'],
            'name'      => $r['nama'],
            'status'    => $r['jenis'],
            'device_id' => $r['device_id'],
            'queued'    => (bool)$r['queued'],
            'tap_id'    => $r['tap_id'],
        ], $q->fetchAll());
        balas(200, ['ok' => true, 'date' => $tanggal, 'items' => $items]);
    }

    // ---------- Alat ---------------------------------------------------------
    if ($s0 === 'devices') {
        if ($n === 1 && $metode === 'GET') {
            $alat = $db->query('SELECT * FROM alat ORDER BY terakhir_terlihat DESC')->fetchAll();
            balas(200, ['ok' => true, 'devices' => array_map(fn($a) => dataAlat($db, $a), $alat)]);
        }
        if ($n === 2 && in_array($metode, ['GET', 'PUT'], true)) {
            $alat = ambilAlat($db, $s1);
            if (!$alat) {
                balas(404, ['ok' => false, 'message' => 'Alat tidak ditemukan']);
            }
            if ($metode === 'PUT') {
                $b = bodyJson($mentah);
                $d = [];
                $e = [];
                if (array_key_exists('name', $b)) {
                    $v = $b['name'];
                    if ($v === null || (is_string($v) && trim($v) === '')) {
                        $d['nama'] = null;
                    } elseif (is_string($v) && panjang(trim($v)) <= 40) {
                        $d['nama'] = trim($v);
                    } else {
                        $e['name'] = 'Nama alat maks. 40 karakter';
                    }
                }
                if (array_key_exists('pin', $b)) {
                    $v = is_int($b['pin']) ? (string)$b['pin'] : $b['pin'];
                    if ($v === null || $v === '') {
                        $d['pin'] = null;  // hapus: PIN tidak dikirim lagi, alat memakai PIN terakhirnya
                    } elseif (is_string($v) && preg_match('/^\d{4,8}$/', $v)) {
                        $d['pin'] = $v;
                    } else {
                        $e['pin'] = 'PIN harus 4-8 angka (atau "" / null untuk menghapus)';
                    }
                }
                if (array_key_exists('restart_at', $b)) {
                    $v = $b['restart_at'];
                    if ($v === null || $v === '' || jamValid($v)) {
                        $d['restart_at'] = $v;  // null = pakai default_restart_at, "" = mati
                    } else {
                        $e['restart_at'] = 'restart_at harus "HH:MM" (00:00-23:59), "" (mati), atau null (bawaan)';
                    }
                }
                if (array_key_exists('firmware_target', $b)) {
                    $v = $b['firmware_target'];
                    if ($v === null || $v === '') {
                        $d['firmware_target'] = null;
                    } elseif (is_string($v) && ambilRilis($db, $v)) {
                        $d['firmware_target'] = $v;
                    } else {
                        $e['firmware_target'] = 'Versi firmware tidak ada. Unggah dulu lewat POST /admin/firmware';
                    }
                }
                if (array_key_exists('tap_mode', $b)) {
                    $v = $b['tap_mode'];
                    if ($v === null || $v === 'auto' || $v === 'select') {
                        $d['tap_mode'] = $v;  // null = alat memakai pengaturannya sendiri (config.tap_mode tidak dikirim)
                    } else {
                        $e['tap_mode'] = 'tap_mode harus null (ikuti pengaturan alat), "auto", atau "select"';
                    }
                }
                if ($e) {
                    gagalValidasi($e);
                }
                perbarui($db, 'alat', $d, 'device_id', $s1);
                balas(200, ['ok' => true, 'device' => dataAlat($db, ambilAlat($db, $s1))]);
            }
            $q = $db->prepare('SELECT * FROM event_alat WHERE device_id = ? ORDER BY id DESC LIMIT 20');
            $q->execute([$s1]);
            $events = array_map(fn($ev) => [
                'type'       => $ev['jenis'],
                'message'    => $ev['pesan'],
                'details'    => $ev['detail_json'] === null ? null : json_decode($ev['detail_json'], true),
                'created_at' => iso((int)$ev['waktu']),
            ], $q->fetchAll());
            balas(200, ['ok' => true, 'device' => dataAlat($db, $alat), 'events' => $events]);
        }
    }

    // ---------- Firmware -----------------------------------------------------
    if ($s0 === 'firmware') {
        if ($n === 1 && $metode === 'GET') {
            $rilis = $db->query('SELECT * FROM firmware')->fetchAll();
            usort($rilis, fn($a, $b) => version_compare($b['versi'], $a['versi'])); // terbaru dulu
            balas(200, ['ok' => true, 'releases' => array_map(fn($r) => dataRilis($db, $r), $rilis)]);
        }
        // Unggah: POST /admin/firmware?version=1.5.1&notes=teks, body = isi file .bin mentah.
        if ($n === 1 && $metode === 'POST') {
            $versi   = is_string($_GET['version'] ?? null) ? trim($_GET['version']) : '';
            $catatan = is_string($_GET['notes'] ?? null) ? trim($_GET['notes']) : '';
            $ukuran  = strlen($mentah);
            $e = [];
            if (!preg_match('/^\d+\.\d+\.\d+$/', $versi)) {
                $e['version'] = 'Versi harus berformat angka.angka.angka, mis. 1.5.1';
            } elseif (ambilRilis($db, $versi)) {
                $e['version'] = "Versi $versi sudah ada. Hapus dulu atau pakai nomor versi baru";
            }
            if ($ukuran < 1 || $ukuran > MAKS_FIRMWARE) {
                $e['file'] = 'Ukuran file harus 1-' . MAKS_FIRMWARE . ' byte (didapat ' . $ukuran . ' byte)';
            } elseif (ord($mentah[0]) !== 0xE9) {
                $e['file'] = 'Bukan file firmware ESP32 (byte pertama harus 0xE9). Pakai file .bin aplikasi, bukan file gabungan/bootloader';
            } elseif (!isset($e['version']) && !str_contains($mentah, $versi)) {
                $e['file'] = "Versi $versi tidak ditemukan di dalam file. Pastikan VERSI_FIRMWARE di config.h sama.";
            }
            if ($e) {
                gagalValidasi($e);
            }
            if (!is_dir(FOLDER_FIRMWARE)) {
                mkdir(FOLDER_FIRMWARE, 0775, true);
            }
            if (file_put_contents(fileFirmware($versi), $mentah, LOCK_EX) !== $ukuran) {
                balas(500, ['ok' => false, 'message' => 'Gagal menyimpan file firmware']);
            }
            $db->prepare('INSERT INTO firmware (versi, ukuran, md5, catatan, diunggah_pada) VALUES (?, ?, ?, ?, ?)')
               ->execute([$versi, $ukuran, md5($mentah), $catatan, date('c')]);
            balas(201, ['ok' => true, 'release' => dataRilis($db, ambilRilis($db, $versi))]);
        }
        if ($n >= 2) {
            $rilis = ambilRilis($db, $s1);
            // Hapus: file & data dihapus, alat yang dijadwalkan ke versi ini tidak jadi update.
            if ($n === 2 && $metode === 'DELETE') {
                if (!$rilis) {
                    balas(404, ['ok' => false, 'message' => 'Firmware tidak ditemukan']);
                }
                @unlink(fileFirmware($s1));
                $db->prepare('DELETE FROM firmware WHERE versi = ?')->execute([$s1]);
                $db->prepare('UPDATE alat SET firmware_target = NULL WHERE firmware_target = ?')->execute([$s1]);
                balas(200, ['ok' => true]);
            }
            // Jadwalkan versi ini ke SEMUA alat yang terdaftar.
            if ($n === 3 && $s2 === 'apply-all' && $metode === 'POST') {
                if (!$rilis) {
                    balas(404, ['ok' => false, 'message' => 'Firmware tidak ditemukan']);
                }
                $q = $db->prepare('UPDATE alat SET firmware_target = ?');
                $q->execute([$s1]);
                balas(200, ['ok' => true, 'devices' => $q->rowCount()]);
            }
        }
    }

    // ---------- Pengumuman ---------------------------------------------------
    // Setiap perubahan mengganti rev → config.announcements_rev berubah → alat mengambil ulang.
    if ($s0 === 'announcements') {
        if ($n === 1 && $metode === 'GET') {
            $items = $db->query('SELECT * FROM pengumuman ORDER BY urutan, id')->fetchAll();
            balas(200, ['ok' => true, 'rev' => revPengumuman($db), 'items' => array_map('dataPengumuman', $items)]);
        }
        if ($n === 1 && $metode === 'POST') {
            $d = validasiPengumuman(bodyJson($mentah), true);
            $db->prepare('INSERT INTO pengumuman (judul, deskripsi, ikon, aktif, urutan) VALUES (?, ?, ?, ?, ?)')
               ->execute([$d['judul'], $d['deskripsi'], $d['ikon'], $d['aktif'], $d['urutan']]);
            $id = (int)$db->lastInsertId();
            $rev = gantiRevPengumuman($db);
            balas(201, ['ok' => true, 'item' => dataPengumuman(ambilPengumuman($db, $id)), 'rev' => $rev]);
        }
        if ($n === 2 && in_array($metode, ['PUT', 'DELETE'], true)) {
            $id = ctype_digit($s1) ? (int)$s1 : 0;
            if (!ambilPengumuman($db, $id)) {
                balas(404, ['ok' => false, 'message' => 'Pengumuman tidak ditemukan']);
            }
            if ($metode === 'PUT') {
                perbarui($db, 'pengumuman', validasiPengumuman(bodyJson($mentah), false), 'id', $id);
                $rev = gantiRevPengumuman($db);
                balas(200, ['ok' => true, 'item' => dataPengumuman(ambilPengumuman($db, $id)), 'rev' => $rev]);
            }
            $db->prepare('DELETE FROM pengumuman WHERE id = ?')->execute([$id]);
            balas(200, ['ok' => true, 'rev' => gantiRevPengumuman($db)]);
        }
    }

    balas(404, ['ok' => false, 'message' => 'Endpoint tidak ada']);
}

function ambilPengumuman(PDO $db, int $id): ?array
{
    $q = $db->prepare('SELECT * FROM pengumuman WHERE id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}
