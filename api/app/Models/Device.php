<?php

namespace App\Models;

use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Alat absensi.
 *
 * API standar (/api/absensi): alat dikenali dari `code` (header X-Device-ID) dan dibuat
 * otomatis saat pertama kali menghubungi server. API lama (/api/v1, usang): alat dikenali
 * dari `api_key` (hash SHA-256 dari X-Device-Key).
 *
 * `pin` = PIN menu Pengaturan alat ini (4–8 digit), dikirim lewat config.pin. Kosong = alat memakai PIN-nya sendiri.
 */
#[Fillable(['code', 'name', 'location', 'pin'])]
#[Hidden(['api_key'])]
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory;

    /** Alat dianggap aktif kalau terakhir menghubungi server kurang dari 3 menit lalu. */
    public const ONLINE_SECONDS = 180;

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'last_payload' => 'array',
            'rssi' => 'integer',
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

        $this->save();
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
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
