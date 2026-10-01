<?php

namespace App\Models;

use Carbon\CarbonInterval;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * Alat absensi.
 *
 * API standar (/api/absensi): alat dikenali dari `code` (header X-Device-ID) dan dibuat
 * otomatis saat pertama kali menghubungi server. API lama (/api/v1, usang): alat dikenali
 * dari `api_key` (hash SHA-256 dari X-Device-Key).
 *
 * `pin` = PIN menu Pengaturan alat ini (4–8 digit), dikirim lewat config.pin. Kosong = alat memakai PIN-nya sendiri.
 * `restart_at` = jam restart harian alat ini ("HH:MM", jam lokal alat), dikirim lewat config.restart_at.
 * Null = alat tidak restart otomatis (dikirim sebagai ""). Bawaan 03:00.
 *
 * Kesehatan alat (heartbeat_at, uptime_s, reset_reason, rfid_ok, queue, free_heap, min_free_heap, error_code,
 * ota_failed) hanya diisi dari body /heartbeat, lihat recordHeartbeat(). Kejadian penting dicatat di DeviceEvent.
 * `firmware_release_id` = target update firmware jarak jauh (OTA), dikirim lewat config.firmware_update.
 */
#[Fillable(['code', 'name', 'location', 'pin', 'restart_at', 'firmware_release_id'])]
#[Hidden(['api_key'])]
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory;

    /** Alat dianggap aktif kalau terakhir menghubungi server kurang dari 3 menit lalu. */
    public const ONLINE_SECONDS = 180;

    /** Jam restart harian bawaan, sama dengan default kolom devices.restart_at. */
    public const DEFAULT_RESTART_AT = '03:00';

    // ----- Batas peringatan kesehatan (healthIssues) -----

    /** Peringatan "Offline" kalau tidak menghubungi server lebih dari 10 menit. */
    public const OFFLINE_WARNING_SECONDS = 600;

    /** Sinyal WiFi di bawah ini (dBm) dianggap lemah. */
    public const WEAK_RSSI = -80;

    /** Antrean tap offline lebih dari ini dianggap menumpuk. */
    public const QUEUE_WARNING = 20;

    /** RAM bebas terendah sejak menyala di bawah ini (byte) dianggap hampir habis. */
    public const LOW_HEAP_BYTES = 20000;

    /** Restart tidak normal sebanyak ini dalam 24 jam memicu peringatan. */
    public const ABNORMAL_RESET_WARNING = 3;

    /** Penyebab restart yang tidak normal. */
    public const ABNORMAL_RESETS = ['watchdog', 'panic', 'brownout'];

    /** raw.reset_reason dari firmware => keterangan. */
    public const RESET_REASONS = [
        'poweron' => 'baru dinyalakan / tombol EN',
        'external' => 'reset dari luar',
        'software' => 'restart oleh program',
        'panic' => 'program error',
        'watchdog' => 'watchdog (program macet)',
        'brownout' => 'listrik turun (brownout)',
        'deepsleep' => 'bangun dari tidur',
        'other' => 'lainnya',
    ];

    /** raw.error (kode error di layar alat) => arti. */
    public const ERROR_CODES = [
        'E10' => 'Nama WiFi tidak ditemukan',
        'E11' => 'Password WiFi salah',
        'E12' => 'Gagal tersambung ke WiFi',
        'E13' => 'Tidak mendapat IP dari router (DHCP)',
        'E20' => 'Server tidak terjangkau / DNS gagal',
        'E21' => 'API key salah (401)',
        'E22' => 'Alat ditolak server (403)',
        'E23' => 'Alamat API tidak ditemukan (404)',
        'E24' => 'Server error (5xx)',
        'E25' => 'Balasan server tidak valid',
        'E26' => 'Server tidak menjawab (timeout)',
        'E30' => 'Pembaca RFID tidak terdeteksi',
        'E31' => 'Jam belum sinkron',
    ];

    /** Crash dengan PC & backtrace sama dalam rentang ini tidak dicatat ulang (firmware mengirim ulang sampai heartbeat berhasil). */
    private const CRASH_DEDUPE_MINUTES = 10;

    /** Nilai awal model baru (mis. alat yang dibuat otomatis oleh middleware), sama dengan default kolom. */
    protected $attributes = [
        'restart_at' => self::DEFAULT_RESTART_AT,
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'last_payload' => 'array',
            'rssi' => 'integer',
            'heartbeat_at' => 'datetime',
            'uptime_s' => 'integer',
            'rfid_ok' => 'boolean',
            'queue' => 'integer',
            'free_heap' => 'integer',
            'min_free_heap' => 'integer',
            'firmware_release_id' => 'integer',
        ];
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->diffInSeconds(now(), true) < self::ONLINE_SECONDS;
    }

    /**
     * Catat kontak dari alat (API standar): waktu terakhir terlihat, plus firmware, IP, RSSI dan
     * SSID kalau dikirim di body (/tap mengirimnya di "raw", /heartbeat juga di level atas).
     * Versi firmware yang berganti dicatat sebagai kejadian "firmware" (dari request mana pun, karena
     * setelah update alat bisa saja mengirim /ping lebih dulu daripada /heartbeat).
     *
     * @param  array<string, mixed>  $body
     */
    public function recordContact(array $body): void
    {
        $raw = is_array($body['raw'] ?? null) ? $body['raw'] : [];
        $value = fn (string $key) => $body[$key] ?? $raw[$key] ?? null;

        $this->last_seen_at = now();

        if (is_string($value('firmware'))) {
            $this->firmware = Str::limit($value('firmware'), 32, '');
        }
        if (is_string($value('ip'))) {
            $this->ip = Str::limit($value('ip'), 45, '');
        }
        if (is_numeric($value('rssi'))) {
            $this->rssi = max(-32768, min(32767, (int) $value('rssi')));
        }
        if (is_string($value('wifi_ssid'))) {
            $this->wifi_ssid = Str::limit($value('wifi_ssid'), 64, '');
        }
        if ($body !== []) {
            $this->last_payload = $body;
        }

        $oldFirmware = $this->getOriginal('firmware');
        $firmwareChanged = $this->exists && $oldFirmware !== null && $this->isDirty('firmware');

        $this->save();

        if ($firmwareChanged && self::monitoringReady()) {
            DeviceEvent::record($this, 'firmware', "Firmware {$oldFirmware} -> {$this->firmware}", [
                'from' => $oldFirmware,
                'to' => $this->firmware,
            ]);
        }
    }

    /**
     * Migrasi kesehatan alat & update firmware (tabel device_events dkk.) sudah dijalankan? Sebelum itu API alat
     * tetap jalan seperti dulu, hanya tanpa data kesehatan & riwayat kejadian.
     */
    public static function monitoringReady(): bool
    {
        return Schema::hasTable('device_events');
    }

    /**
     * Simpan data kesehatan dari body /heartbeat (raw.uptime_s, reset_reason, rfid_ok, queue, free_heap,
     * min_free_heap, error, ota_failed, crash) dan catat kejadian: boot, crash, ota_failed.
     * Nilai yang tidak dikirim / tipenya salah dibiarkan, kecuali error & ota_failed: tidak dikirim = tidak ada.
     *
     * @param  array<string, mixed>  $body
     */
    public function recordHeartbeat(array $body): void
    {
        $raw = is_array($body['raw'] ?? null) ? $body['raw'] : [];
        $count = fn (string $key) => is_int($raw[$key] ?? null) ? max(0, min(2147483647, $raw[$key])) : null;

        $previousUptime = $this->uptime_s;
        $previousHeartbeatAt = $this->heartbeat_at;
        $hadHeartbeat = $previousHeartbeatAt !== null;
        $previousOtaFailed = $this->ota_failed;
        $resetReason = is_string($raw['reset_reason'] ?? null) && $raw['reset_reason'] !== ''
            ? Str::limit($raw['reset_reason'], 16, '')
            : null;

        $this->heartbeat_at = now();

        if ($resetReason !== null) {
            $this->reset_reason = $resetReason;
        }
        if (is_bool($raw['rfid_ok'] ?? null)) {
            $this->rfid_ok = $raw['rfid_ok'];
        }
        foreach (['queue', 'free_heap', 'min_free_heap'] as $key) {
            if ($count($key) !== null) {
                $this->{$key} = $count($key);
            }
        }

        $this->error_code = is_string($raw['error'] ?? null) && $raw['error'] !== '' ? Str::limit($raw['error'], 8, '') : null;
        $this->ota_failed = is_string($raw['ota_failed'] ?? null) && $raw['ota_failed'] !== '' ? Str::limit($raw['ota_failed'], 32, '') : null;

        // Menyala ulang: uptime lebih kecil dari sebelumnya, atau lebih kecil dari jarak sejak heartbeat
        // sebelumnya (restart di antaranya, mis. dua kali restart cepat), atau dulu tidak dikirim padahal
        // sudah pernah heartbeat.
        $booted = false;

        if (is_int($raw['uptime_s'] ?? null)) {
            $uptime = max(0, $raw['uptime_s']);
            $booted = $previousUptime !== null
                ? $uptime < $previousUptime || $uptime < $previousHeartbeatAt->diffInSeconds(now(), true)
                : $hadHeartbeat;
            $this->uptime_s = $uptime;
        }

        $this->save();

        if ($booted) {
            DeviceEvent::record($this, 'boot', $resetReason ? 'Menyala ulang: '.self::resetReasonLabel($resetReason) : 'Menyala ulang', [
                'reset_reason' => $resetReason,
                'uptime_s' => $this->uptime_s,
                'previous_uptime_s' => $previousUptime,
            ]);
        }

        if (is_array($raw['crash'] ?? null)) {
            $this->recordCrash($raw['crash']);
        }

        if ($this->ota_failed !== null && $this->ota_failed !== $previousOtaFailed) {
            DeviceEvent::record($this, 'ota_failed', "Update firmware ke {$this->ota_failed} gagal, alat kembali ke ".($this->firmware ?? 'versi lama'), [
                'version' => $this->ota_failed,
                'firmware' => $this->firmware,
            ]);
        }
    }

    /**
     * Crash dari raw.crash ({task, pc, backtrace, elf}; backtrace = daftar PC dipisah spasi, elf = build ID). Firmware mengirimnya ulang di setiap heartbeat sampai
     * ada yang berhasil, jadi crash yang sama (PC + backtrace) dalam 10 menit terakhir tidak dicatat lagi.
     *
     * @param  array<mixed>  $crash
     */
    private function recordCrash(array $crash): void
    {
        $text = fn (string $key, int $max) => is_string($crash[$key] ?? null) ? Str::limit($crash[$key], $max, '') : null;
        $details = ['task' => $text('task', 32), 'pc' => $text('pc', 32), 'backtrace' => $text('backtrace', 1000), 'elf' => $text('elf', 64)];

        $duplicate = $this->events()
            ->where('type', 'crash')
            ->where('created_at', '>=', now()->subMinutes(self::CRASH_DEDUPE_MINUTES))
            ->get()
            ->contains(fn (DeviceEvent $event) => ($event->details['pc'] ?? null) === $details['pc']
                && ($event->details['backtrace'] ?? null) === $details['backtrace']);

        if ($duplicate) {
            return;
        }

        $message = 'Program error (crash)'
            .($details['task'] ? " di task {$details['task']}" : '')
            .($details['pc'] ? ", PC {$details['pc']}" : '');

        DeviceEvent::record($this, 'crash', $message, $details);
    }

    public static function resetReasonLabel(string $reason): string
    {
        return self::RESET_REASONS[$reason] ?? $reason;
    }

    public static function errorLabel(string $code): string
    {
        return isset(self::ERROR_CODES[$code]) ? "{$code}: ".self::ERROR_CODES[$code] : $code;
    }

    /**
     * Peringatan kesehatan alat untuk panel admin. Kosong = sehat.
     *
     * @return list<string>
     */
    public function healthIssues(): array
    {
        $issues = [];

        if ($this->last_seen_at !== null && $this->last_seen_at->diffInSeconds(now(), true) > self::OFFLINE_WARNING_SECONDS) {
            $issues[] = 'Offline sejak '.$this->last_seen_at->translatedFormat('d M H:i').' ('.$this->last_seen_at->diffForHumans().')';
        }

        if ($this->rfid_ok === false) {
            $issues[] = 'Pembaca RFID tidak terdeteksi (E30)';
        }

        if ($this->error_code !== null && ! ($this->error_code === 'E30' && $this->rfid_ok === false)) {
            $issues[] = 'Error '.self::errorLabel($this->error_code);
        }

        if ($this->rssi !== null && $this->rssi < self::WEAK_RSSI) {
            $issues[] = "Sinyal WiFi lemah ({$this->rssi} dBm)";
        }

        if ($this->queue !== null && $this->queue > self::QUEUE_WARNING) {
            $issues[] = "Antrean offline menumpuk: {$this->queue} tap belum terkirim";
        }

        $abnormal = $this->bootEventsLastDay
            ->map(fn (DeviceEvent $event) => $event->details['reset_reason'] ?? null)
            ->filter(fn ($reason) => in_array($reason, self::ABNORMAL_RESETS, true))
            ->countBy()
            ->sortDesc();

        if ($abnormal->sum() >= self::ABNORMAL_RESET_WARNING) {
            $issues[] = "Sering restart tidak normal: {$abnormal->sum()}x dalam 24 jam ("
                .$abnormal->map(fn ($n, $reason) => "{$reason} {$n}")->implode(', ').')'
                .($abnormal->has('brownout') ? '. Brownout: cek adaptor/kabel daya' : '');
        }

        if ($this->ota_failed !== null) {
            $issues[] = "Update firmware ke {$this->ota_failed} gagal, alat kembali ke ".($this->firmware ?? 'versi lama');
        }

        if ($this->min_free_heap !== null && $this->min_free_heap < self::LOW_HEAP_BYTES) {
            $issues[] = 'RAM hampir habis (terendah '.Number::fileSize($this->min_free_heap, 1).')';
        }

        return $issues;
    }

    /** Lama menyala saat heartbeat terakhir, mis. "3 jam 5 menit". */
    public function uptimeForHumans(): ?string
    {
        return $this->uptime_s === null ? null : CarbonInterval::seconds($this->uptime_s)->cascade()->forHumans(['parts' => 2]);
    }

    /**
     * Update firmware yang harus dikirim lewat config.firmware_update: target ada, versinya beda dengan yang
     * terpasang, dan belum pernah gagal dipasang di alat ini (alat melapor raw.ota_failed).
     */
    public function pendingFirmwareUpdate(): ?FirmwareRelease
    {
        $target = $this->firmwareRelease;

        return $target && $target->version !== $this->firmware && $target->version !== $this->ota_failed
            ? $target
            : null;
    }

    /**
     * Status update firmware untuk panel admin: [label, warna lencana], null kalau tidak ada target.
     *
     * @return array{0: string, 1: string}|null
     */
    public function firmwareUpdateState(): ?array
    {
        $target = $this->firmwareRelease;

        return match (true) {
            $target === null => null,
            $target->version === $this->firmware => ['Sudah terpasang', 'success'],
            $target->version === $this->ota_failed => ['Gagal, kembali ke '.($this->firmware ?? 'versi lama'), 'danger'],
            default => ['Menunggu alat mengunduh', 'info'],
        };
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeviceEvent::class);
    }

    /** Kejadian "boot" 24 jam terakhir, untuk menghitung restart tidak normal di healthIssues(). */
    public function bootEventsLastDay(): HasMany
    {
        return $this->events()->where('type', 'boot')->where('created_at', '>=', now()->subDay());
    }

    /** Target update firmware jarak jauh. */
    public function firmwareRelease(): BelongsTo
    {
        return $this->belongsTo(FirmwareRelease::class);
    }

    // ----- API lama /api/v1 (X-Device-Key per alat) -----

    public static function hashKey(string $plainKey): string
    {
        return hash('sha256', $plainKey);
    }

    public static function generatePlainKey(): string
    {
        return Str::random(40);
    }

    /**
     * Buat alat untuk API lama. Mengembalikan [Device, plainKey].
     * Plain key hanya tersedia saat ini; di DB hanya disimpan hash SHA-256.
     *
     * @return array{0: Device, 1: string}
     */
    public static function register(string $name, ?string $location = null): array
    {
        $plainKey = static::generatePlainKey();

        $device = new static(['name' => $name, 'location' => $location]);
        $device->api_key = static::hashKey($plainKey);
        $device->save();

        return [$device, $plainKey];
    }

    public static function findByPlainKey(?string $plainKey): ?self
    {
        if ($plainKey === null || $plainKey === '') {
            return null;
        }

        return static::where('api_key', static::hashKey($plainKey))->first();
    }
}
