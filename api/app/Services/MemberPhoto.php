<?php

namespace App\Services;

use App\Models\Member;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Foto anggota untuk layar alat (spesifikasi bagian 4, "Foto"):
 * JPEG baseline (bukan progressive), muat di dalam 160×160 piksel, maksimal 30 KB.
 * Diproses dengan ekstensi GD dan disimpan di disk "public" (storage/app/public/anggota).
 */
class MemberPhoto
{
    public const MAX_SIDE = 160;

    public const MAX_BYTES = 30 * 1024;

    /** GD dengan dukungan JPEG & PNG tersedia? Kalau tidak, unggah foto dinonaktifkan di panel. */
    public static function isSupported(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagejpeg');
    }

    /** Ganti foto anggota dengan hasil olahan unggahan. */
    public function replace(Member $member, UploadedFile $file): void
    {
        $bytes = (string) file_get_contents($file->getRealPath());
        $path = 'anggota/'.$member->id.'-'.Str::lower(Str::random(8)).'.jpg';

        Storage::disk('public')->put($path, $this->toDeviceJpeg($bytes, $this->exifOrientation($file->getRealPath())));

        $this->delete($member);
        $member->forceFill(['photo_path' => $path])->save();
    }

    public function delete(Member $member): void
    {
        if ($member->photo_path) {
            Storage::disk('public')->delete($member->photo_path);
            $member->forceFill(['photo_path' => null])->save();
        }
    }

    /**
     * Ubah gambar (JPEG/PNG apa pun) menjadi JPEG baseline ≤ 160×160 dan ≤ 30 KB.
     * Kualitas diturunkan bertahap sampai ukuran file muat.
     */
    public function toDeviceJpeg(string $bytes, int $orientation = 1): string
    {
        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            throw new \InvalidArgumentException('Gambar tidak bisa dibaca.');
        }

        // Foto dari ponsel sering disimpan miring dengan penanda EXIF Orientation.
        $source = match ($orientation) {
            3 => imagerotate($source, 180, 0),
            6 => imagerotate($source, -90, 0),
            8 => imagerotate($source, 90, 0),
            default => $source,
        };

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, self::MAX_SIDE / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $image = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255)); // latar putih untuk PNG transparan
        imagecopyresampled($image, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imageinterlace($image, false); // baseline, bukan progressive

        for ($quality = 85; $quality >= 10; $quality -= 10) {
            ob_start();
            imagejpeg($image, null, $quality);
            $jpeg = (string) ob_get_clean();

            if (strlen($jpeg) <= self::MAX_BYTES) {
                break;
            }
        }

        return $jpeg;
    }

    private function exifOrientation(string $path): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($path);

        return is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
    }
}
