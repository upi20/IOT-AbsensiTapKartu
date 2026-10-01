<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Pengaturan global key/value, diubah dari halaman Pengaturan di panel admin.
 *
 *  - api_key    : harus sama dengan header X-API-Key dari alat (teks biasa, bukan hash)
 *  - title      : judul di layar utama alat
 *  - screensaver_interval : lama tiap pengumuman tampil di screensaver alat (detik, 2–60)
 *  - screensaver_idle     : screensaver muncul setelah alat diam sekian detik (5–600)
 *  - dim_after  : lampu layar alat meredup setelah diam sekian detik (0 = tidak pernah, atau 10–3600)
 *  - dim_level  : kecerahan layar saat redup (persen, 0–100)
 */
class Setting extends Model
{
    public const API_KEY = 'api_key';

    public const TITLE = 'title';

    public const SCREENSAVER_INTERVAL = 'screensaver_interval';

    public const SCREENSAVER_IDLE = 'screensaver_idle';

    public const DIM_AFTER = 'dim_after';

    public const DIM_LEVEL = 'dim_level';

    /** Bawaan & batas layar redup sesuai spesifikasi bagian 6. dim_after 1–9 tidak sah. */
    public const DIM_AFTER_DEFAULT = 60;

    public const DIM_AFTER_MIN = 10;

    public const DIM_AFTER_MAX = 3600;

    public const DIM_LEVEL_DEFAULT = 20;

    public const DIM_LEVEL_MAX = 100;

    /** Bawaan & batas screensaver sesuai spesifikasi bagian 8. */
    public const SCREENSAVER_INTERVAL_DEFAULT = 3;

    public const SCREENSAVER_INTERVAL_MIN = 2;

    public const SCREENSAVER_INTERVAL_MAX = 60;

    public const SCREENSAVER_IDLE_DEFAULT = 30;

    public const SCREENSAVER_IDLE_MIN = 5;

    public const SCREENSAVER_IDLE_MAX = 600;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    public static function getValue(string $key, ?string $default = null): ?string
    {
        return static::query()->find($key)?->value ?? $default;
    }

    public static function setValue(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function forget(string $key): void
    {
        static::query()->whereKey($key)->delete();
    }

    /** API key alat. Dibuat acak (24 karakter) kalau belum ada. */
    public static function apiKey(): string
    {
        $key = static::getValue(self::API_KEY);

        if ($key === null || $key === '') {
            $key = static::regenerateApiKey();
        }

        return $key;
    }

    public static function regenerateApiKey(): string
    {
        $key = Str::random(24);
        static::setValue(self::API_KEY, $key);

        return $key;
    }

    public static function title(): string
    {
        return static::getValue(self::TITLE) ?: config('app.name');
    }

    /** Lama tiap pengumuman tampil (detik). Bawaan 3 kalau belum diatur atau tidak valid. */
    public static function screensaverInterval(): int
    {
        return static::intValue(self::SCREENSAVER_INTERVAL, self::SCREENSAVER_INTERVAL_DEFAULT,
            self::SCREENSAVER_INTERVAL_MIN, self::SCREENSAVER_INTERVAL_MAX);
    }

    /** Screensaver muncul setelah alat diam sekian detik. Bawaan 30 kalau belum diatur atau tidak valid. */
    public static function screensaverIdle(): int
    {
        return static::intValue(self::SCREENSAVER_IDLE, self::SCREENSAVER_IDLE_DEFAULT,
            self::SCREENSAVER_IDLE_MIN, self::SCREENSAVER_IDLE_MAX);
    }

    /** Layar alat meredup setelah diam sekian detik (0 = tidak pernah). Bawaan 60 kalau belum diatur atau tidak valid. */
    public static function dimAfter(): int
    {
        $value = filter_var(static::getValue(self::DIM_AFTER), FILTER_VALIDATE_INT);

        return $value === 0 ? 0 : static::intValue(self::DIM_AFTER, self::DIM_AFTER_DEFAULT,
            self::DIM_AFTER_MIN, self::DIM_AFTER_MAX);
    }

    /** Kecerahan layar saat redup (persen, 0 = mati). Bawaan 20 kalau belum diatur atau tidak valid. */
    public static function dimLevel(): int
    {
        return static::intValue(self::DIM_LEVEL, self::DIM_LEVEL_DEFAULT, 0, self::DIM_LEVEL_MAX);
    }

    private static function intValue(string $key, int $default, int $min, int $max): int
    {
        $value = filter_var(static::getValue($key), FILTER_VALIDATE_INT);

        return $value === false || $value < $min || $value > $max ? $default : $value;
    }
}
