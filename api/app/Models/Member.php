<?php

namespace App\Models;

use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Anggota (siswa / karyawan). `card_uid` = nomor kartu. Untuk kartu baru disimpan sebagai
 * 10 digit desimal seperti yang dikirim alat (mis. "0218893066"); data lama boleh tetap hex.
 */
#[Fillable(['name', 'identifier', 'card_uid', 'is_active'])]
class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use HasFactory;

    public const NAME_MIN = 2;

    public const NAME_MAX = 60;

    /** NIS/NIP maksimal 30 karakter: huruf, angka, titik, strip, garis miring. */
    public const IDENTIFIER_MAX = 30;

    public const IDENTIFIER_PATTERN = '/^[A-Za-z0-9.\/-]+$/';

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Normalisasi nomor kartu: buang spasi/titik dua/strip, jadikan huruf besar.
     * " 0218893066 " => "0218893066", "a1:b2 c3-d4" => "A1B2C3D4"
     */
    public static function normalizeUid(?string $uid): string
    {
        return strtoupper(preg_replace('/[\s:\-]+/', '', (string) $uid));
    }

    /** 10 digit desimal, atau hex 8–20 karakter (UID 4/7/10 byte). */
    public static function isValidUid(string $normalizedUid): bool
    {
        return (bool) preg_match('/^[0-9A-F]{8,20}$/', $normalizedUid);
    }

    /**
     * Query anggota pemilik nomor kartu yang dikirim alat: Member::byCard('0218893066')->first().
     *
     * Kompatibilitas data lama: kartu yang dulu didaftarkan sebagai hex 4 byte (mis. "0A0B0C0D",
     * urutan byte seperti dibaca RC522) tetap dikenali saat alat mengirim nomor 10 digit
     * ("0218893066" = byte yang sama dibaca little-endian).
     */
    public function scopeByCard(Builder $query, string $cardNumber): void
    {
        $candidates = [$cardNumber];

        if (preg_match('/^[0-9]{10}$/', $cardNumber) && (int) $cardNumber <= 0xFFFFFFFF) {
            $candidates[] = strtoupper(bin2hex(pack('V', (int) $cardNumber)));
        }

        $query->whereIn('card_uid', $candidates)->orderBy('id');
    }

    public function setCardUidAttribute(?string $value): void
    {
        $this->attributes['card_uid'] = static::normalizeUid($value);
    }

    /** URL absolut foto (mengikuti host & skema request, jadi https lewat tunnel). */
    public function photoUrl(): ?string
    {
        return $this->photo_path ? asset('storage/'.$this->photo_path) : null;
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }
}
