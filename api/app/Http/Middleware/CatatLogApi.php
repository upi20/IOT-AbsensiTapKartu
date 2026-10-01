<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Mencatat setiap request ke API alat (/api/...) ke channel log `api_log`
 * (storage/logs/api-YYYY-MM-DD.log), terpisah dari laravel.log.
 *
 * Yang dicatat: SELURUH parameter yang dikirim alat, alat mana pengirimnya (X-Device-ID),
 * dan balasan server (kode status dan isi JSON). Tujuannya menelusuri tap yang gagal,
 * alat yang tidak tersambung, atau firmware yang mengirim data salah.
 *
 * Rahasia tidak ikut tertulis: API key (X-API-Key / X-Device-Key) hanya dicatat ada atau
 * tidaknya ("[present, hidden]"), dan PIN alat (config.pin di balasan /ping & /heartbeat)
 * diganti "[hidden]".
 *
 * Kunci di log berbahasa Inggris: method, url, device, request, response, error, duration_ms.
 *
 * Pencatatan tidak boleh mengganggu request. Semua langkahnya dibungkus
 * try/catch: kalau log gagal ditulis, request tetap diproses seperti biasa.
 * Middleware ini juga tidak pernah mengubah request maupun response.
 */
class CatatLogApi
{
    /** String sepanjang ini yang berbentuk base64 diringkas, bukan ditulis utuh. */
    private const AMBANG_BASE64 = 256;

    /**
     * Batas keras panjang satu nilai di log. Isian dari alat pendek (nomor kartu, waktu,
     * nama WiFi), jadi yang melewati ini hampir pasti data rusak dan dipotong supaya
     * satu baris log tidak berukuran megabita.
     */
    private const BATAS_PANJANG_NILAI = 5000;

    /** Kunci yang nilainya rahasia (dibandingkan tanpa membedakan huruf besar/kecil). */
    private const KUNCI_RAHASIA = ['password', 'password_confirmation', 'api_key', 'device_key', 'pin'];

    public function handle(Request $request, Closure $next): Response
    {
        $mulai = microtime(true);

        // Diambil sebelum controller berjalan, supaya yang tercatat persis kiriman alat.
        $parameter = $this->ambilParameter($request);

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->catat($request, $parameter, $mulai, null, $e);

            throw $e;
        }

        $this->catat($request, $parameter, $mulai, $response);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function ambilParameter(Request $request): array
    {
        try {
            $parameter = [
                'input' => $this->bersihkan($request->input()),
                'files' => $this->bersihkan($request->allFiles()),
            ];

            // Body yang bukan JSON (atau tanpa Content-Type JSON) tidak terbaca oleh input().
            // Isi mentahnya tetap dicatat supaya kiriman firmware yang salah bisa dilihat.
            $mentah = $request->getContent();
            if ($parameter['input'] === [] && $mentah !== '') {
                $parameter['raw'] = $this->bersihkan($mentah);
            }

            return $parameter;
        } catch (Throwable $e) {
            return ['read_failed' => $e->getMessage()];
        }
    }

    private function catat(Request $request, array $parameter, float $mulai, ?Response $response, ?Throwable $galat = null): void
    {
        try {
            $device = $request->attributes->get('device');

            // Isi log memakai istilah Inggris (method, request, response, error) supaya mudah dibaca.
            Log::channel('api_log')->info('api_request', [
                'method' => $request->method(),
                'url' => $request->path(),
                'device' => [
                    'id' => $request->header('X-Device-ID'),
                    'registered' => $device instanceof Device ? [
                        'id' => $device->id,
                        'name' => $device->name,
                    ] : null,
                ],
                'api_key' => $this->adaKunci($request) ? '[present, hidden]' : null,
                'spec_version' => $request->header('X-Spec-Version'),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'duration_ms' => (int) round((microtime(true) - $mulai) * 1000),
                'request' => $parameter,
                'response' => $this->ringkasBalasan($response),
                'error' => $galat ? [
                    'class' => $galat::class,
                    'message' => $galat->getMessage(),
                    'location' => $galat->getFile().':'.$galat->getLine(),
                ] : null,
            ]);
        } catch (Throwable) {
            // Log yang gagal ditulis tidak boleh menggagalkan request alat.
        }
    }

    /** API standar memakai X-API-Key, API lama (v1) memakai X-Device-Key. */
    private function adaKunci(Request $request): bool
    {
        return $request->header('X-API-Key', '') !== '' || $request->header('X-Device-Key', '') !== '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function ringkasBalasan(?Response $response): ?array
    {
        if (! $response) {
            return null;
        }

        $ringkas = ['status' => $response->getStatusCode()];

        // Balasan API kecil (status tap, nama, pesan, config), jadi isinya dicatat utuh.
        if ($response instanceof JsonResponse) {
            $ringkas['body'] = $this->bersihkan($response->getData(true));
        }

        return $ringkas;
    }

    /**
     * Semua kunci tetap dicatat. Hanya nilai rahasia yang disembunyikan, dan nilai yang
     * terlalu besar untuk log yang diringkas (berkas unggahan, base64, teks sangat panjang).
     * String JSON dibuka dulu supaya isinya ikut terbaca.
     */
    private function bersihkan(mixed $nilai, ?string $kunci = null): mixed
    {
        if ($kunci !== null && in_array(strtolower($kunci), self::KUNCI_RAHASIA, true)) {
            return '[hidden]';
        }

        if ($nilai instanceof UploadedFile) {
            return [
                '_file' => $nilai->getClientOriginalName(),
                'size_bytes' => $nilai->getSize(),
                'mime' => $nilai->getClientMimeType(),
                'valid' => $nilai->isValid(),
            ];
        }

        if (is_array($nilai)) {
            $hasil = [];
            foreach ($nilai as $k => $v) {
                $hasil[$k] = $this->bersihkan($v, is_string($k) ? $k : null);
            }

            return $hasil;
        }

        if (! is_string($nilai)) {
            return $nilai;
        }

        $awal = ltrim($nilai);
        if (($awal[0] ?? '') === '{' || ($awal[0] ?? '') === '[') {
            $json = json_decode($nilai, true);
            if (is_array($json)) {
                return ['_json' => $this->bersihkan($json)];
            }
        }

        if ($this->tampakBase64($nilai)) {
            return [
                '_base64' => true,
                'length' => strlen($nilai),
                'preview' => substr($nilai, 0, 40),
            ];
        }

        if (strlen($nilai) > self::BATAS_PANJANG_NILAI) {
            return [
                '_truncated' => true,
                'length' => strlen($nilai),
                'preview' => substr($nilai, 0, 500),
            ];
        }

        return $nilai;
    }

    private function tampakBase64(string $nilai): bool
    {
        if (strlen($nilai) < self::AMBANG_BASE64) {
            return false;
        }

        if (str_starts_with($nilai, 'data:')) {
            return true;
        }

        // Posesif (++): tanpa backtracking. Kuantifier biasa pada string 3 MB
        // yang punya satu karakter asing kehabisan pcre.backtrack_limit dan
        // mengembalikan false, sehingga string utuhnya tertulis ke log.
        return preg_match('/^[A-Za-z0-9+\/=\r\n]++$/', $nilai) === 1;
    }
}
