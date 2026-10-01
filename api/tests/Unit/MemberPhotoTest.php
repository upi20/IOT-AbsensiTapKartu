<?php

namespace Tests\Unit;

use App\Services\MemberPhoto;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Foto untuk alat: JPEG baseline, muat di 160×160, maksimal 30 KB (spesifikasi bagian 4).
 */
#[RequiresPhpExtension('gd')]
class MemberPhotoTest extends TestCase
{
    public function test_large_noisy_photo_becomes_small_baseline_jpeg(): void
    {
        $source = self::noisyImage(1200, 900, progressive: true);
        $this->assertFalse(self::isBaseline($source));

        $jpeg = (new MemberPhoto)->toDeviceJpeg($source);

        [$width, $height, $type] = getimagesizefromstring($jpeg);
        $this->assertSame(IMAGETYPE_JPEG, $type);
        $this->assertSame([160, 120], [$width, $height]); // rasio dipertahankan
        $this->assertLessThanOrEqual(30 * 1024, strlen($jpeg));
        $this->assertTrue(self::isBaseline($jpeg), 'JPEG harus baseline (SOF0), bukan progressive (SOF2)');
    }

    public function test_png_and_portrait_photo_fit_inside_160(): void
    {
        $image = imagecreatetruecolor(300, 600);
        ob_start();
        imagepng($image);
        $jpeg = (new MemberPhoto)->toDeviceJpeg((string) ob_get_clean());

        [$width, $height] = getimagesizefromstring($jpeg);
        $this->assertSame([80, 160], [$width, $height]);
        $this->assertTrue(self::isBaseline($jpeg));
    }

    public function test_small_photo_is_not_enlarged(): void
    {
        [$width, $height] = getimagesizefromstring((new MemberPhoto)->toDeviceJpeg(self::noisyImage(100, 90)));

        $this->assertSame([100, 90], [$width, $height]);
    }

    public function test_invalid_image_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new MemberPhoto)->toDeviceJpeg('bukan gambar');
    }

    public static function noisyImage(int $width, int $height, bool $progressive = false): string
    {
        $image = imagecreatetruecolor($width, $height);
        mt_srand(42);
        for ($y = 0; $y < $height; $y += 2) {
            for ($x = 0; $x < $width; $x += 2) {
                imagefilledrectangle($image, $x, $y, $x + 1, $y + 1, mt_rand(0, 0xFFFFFF));
            }
        }
        imageinterlace($image, $progressive);
        ob_start();
        imagejpeg($image, null, 95);

        return (string) ob_get_clean();
    }

    /** Baseline JPEG memakai penanda SOF0 (FFC0); progressive memakai SOF2 (FFC2). */
    public static function isBaseline(string $jpeg): bool
    {
        return str_contains($jpeg, "\xFF\xC0") && ! str_contains($jpeg, "\xFF\xC2");
    }
}
