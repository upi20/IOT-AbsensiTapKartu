<?php

namespace App\Http\Middleware;

use App\Models\Device;
use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * API standar (doc/spesifikasi-api.md bagian 2):
 *  - header X-API-Key harus sama dengan API key di Pengaturan  -> kalau tidak: 401
 *  - header X-Device-ID wajib ada                              -> kalau tidak: 400
 *  - body POST harus objek JSON                                -> kalau tidak: 400
 * Header X-Spec-Version diterima tetapi tidak diperiksa (saat ini hanya ada versi 1).
 *
 * Menebak API key diperlambat: setelah 20 kali API key salah dalam 1 menit dari satu IP, semua
 * request dari IP itu dijawab 429 sampai menit itu lewat (juga yang key-nya benar, agar balasan
 * tidak bisa dipakai untuk mengetahui key yang benar).
 *
 * Alat dicari dari X-Device-ID dan dibuat otomatis saat pertama kali menghubungi server.
 * Setiap request mencatat "terakhir terlihat" (plus firmware/IP/RSSI/SSID kalau dikirim).
 */
class AuthenticateAbsensiDevice
{
    /** Batas API key salah per IP per menit. */
    public const WRONG_KEY_LIMIT = 20;

    public function handle(Request $request, Closure $next): Response
    {
        $limiterKey = 'absensi-wrong-key:'.$request->ip();

        if (RateLimiter::tooManyAttempts($limiterKey, self::WRONG_KEY_LIMIT)) {
            return response()->json(['ok' => false, 'message' => 'Terlalu banyak permintaan'], 429, [
                'Retry-After' => RateLimiter::availableIn($limiterKey),
            ]);
        }

        $key = (string) $request->header('X-API-Key', '');

        if ($key === '' || ! hash_equals(Setting::apiKey(), $key)) {
            RateLimiter::hit($limiterKey);

            return response()->json(['ok' => false, 'message' => 'API key salah'], 401);
        }

        $code = trim((string) $request->header('X-Device-ID', ''));

        if ($code === '') {
            return response()->json(['ok' => false, 'message' => 'Header X-Device-ID wajib'], 400);
        }

        if (! preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $code)) {
            return response()->json(['ok' => false, 'message' => 'X-Device-ID tidak valid'], 400);
        }

        $body = [];

        if ($request->isMethod('POST')) {
            $body = json_decode($request->getContent(), true);

            if (! is_array($body) || (array_is_list($body) && $body !== [])) {
                return response()->json(['ok' => false, 'message' => 'Body harus berupa JSON'], 400);
            }
        }

        // Kalau device_id di body berbeda, yang dipakai tetap header X-Device-ID.
        $device = Device::firstOrCreate(['code' => $code], ['name' => $code]);
        $device->recordContact($body);

        $request->attributes->set('device', $device);

        return $next($request);
    }
}
