<?php

namespace App\Models;

use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Pengumuman untuk screensaver alat (doc/spesifikasi-api.md bagian 8).
 * Alat mengambilnya lewat GET /api/absensi/announcements: hanya yang aktif, urut `sort_order`, maks. 10.
 */
#[Fillable(['title', 'description', 'icon', 'is_active', 'sort_order'])]
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    public const TITLE_MAX = 40;

    public const DESCRIPTION_MAX = 160;

    /** Alat hanya menampilkan 10 pengumuman pertama. */
    public const DEVICE_LIMIT = 10;

    public const SORT_ORDER_MAX = 999;

    /** Kode ikon yang tersedia di alat => label di panel. */
    public const ICONS = [
        'info' => 'Informasi umum',
        'pengumuman' => 'Pengumuman',
        'kalender' => 'Tanggal/acara',
        'jam' => 'Waktu/jadwal',
        'peringatan' => 'Peringatan',
        'rapat' => 'Rapat',
        'libur' => 'Libur',
        'selamat' => 'Ucapan selamat',
        'kesehatan' => 'Kesehatan',
        'buku' => 'Pendidikan/belajar',
    ];

    protected $attributes = [
        'icon' => 'info',
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Urutan tampil: nomor urut, lalu yang dibuat lebih dulu.
     *
     * @param  Builder<Announcement>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    public function iconLabel(): string
    {
        return self::ICONS[$this->icon] ?? self::ICONS['info'];
    }

    /**
     * Isi respons GET /announcements.
     *
     * @return array{interval: int, idle: int, items: list<array{id: string, title: string, description?: string, icon: string}>}
     */
    public static function deviceFeed(): array
    {
        $items = static::query()
            ->where('is_active', true)
            ->ordered()
            ->limit(self::DEVICE_LIMIT)
            ->get()
            ->map(fn (Announcement $announcement): array => array_filter([
                'id' => (string) $announcement->id,
                'title' => $announcement->title,
                'description' => $announcement->description,
                'icon' => $announcement->icon,
            ], fn ($value) => $value !== null && $value !== ''))
            ->all();

        return [
            'interval' => Setting::screensaverInterval(),
            'idle' => Setting::screensaverIdle(),
            'items' => $items,
        ];
    }

    /**
     * Penanda versi untuk config.announcements_rev: "<jumlah>-<unix perubahan terakhir>-<interval>-<idle>",
     * mis. "2-1759212345-3-30". Berubah saat pengumuman ditambah/diubah/dihapus atau pengaturan screensaver diganti.
     */
    public static function revision(): string
    {
        $stats = static::query()->toBase()->selectRaw('count(*) as total, max(updated_at) as latest')->first();

        return implode('-', [
            (int) $stats->total,
            $stats->latest === null ? 0 : Carbon::parse($stats->latest)->getTimestamp(),
            Setting::screensaverInterval(),
            Setting::screensaverIdle(),
        ]);
    }
}
