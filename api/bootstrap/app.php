<?php

use App\Http\Middleware\CatatLogApi;
use App\Http\Middleware\SecureUrlsBehindTunnel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Aplikasi berjalan di belakang Cloudflare Tunnel; cloudflared berjalan di mesin yang sama
        // (-> http://localhost:8133). Hanya proxy lokal itu yang dipercaya, sehingga X-Forwarded-Proto
        // (HTTPS) tetap terdeteksi, dan IP klien diambil dari entri X-Forwarded-For paling kanan yang
        // ditambahkan Cloudflare (header palsu dari klien tidak bisa menggantinya).
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);

        // Setelah TrustProxies: paksa URL https & cookie Secure untuk akses lewat tunnel.
        $middleware->append(SecureUrlsBehindTunnel::class);

        // Semua request API alat dicatat ke storage/logs/api-YYYY-MM-DD.log (channel api_log).
        // Dipasang paling depan di grup "api", sebelum throttle dan cek API key, supaya request
        // yang ditolak (401, 400, 429) ikut tercatat.
        $middleware->api(prepend: [CatatLogApi::class]);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: CatatLogApi::class);

        // Panel admin web.
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('admin.dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Respons error API dibuat kecil & datar untuk mikrokontroler.
        $exceptions->render(function (Throwable $e, Request $request) {
            // Respons yang sudah jadi (mis. 429 dari RateLimiter 'absensi-api') dikirim apa adanya.
            if (! $request->is('api/*') || $e instanceof HttpResponseException) {
                return null;
            }

            // API standar (doc/spesifikasi-api.md): format salah -> 400 {"ok":false,"message":...}.
            if ($e instanceof ValidationException && $request->is('api/absensi/*')) {
                return response()->json(['ok' => false, 'message' => $e->validator->errors()->first()], 400);
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'ok' => false,
                    'status' => 'invalid',
                    'message' => 'Data tidak valid',
                ], 422);
            }

            if ($e instanceof HttpExceptionInterface) {
                $code = $e->getStatusCode();
                [$status, $message] = match ($code) {
                    404 => ['not_found', 'Endpoint tidak ada'],
                    405 => ['method_not_allowed', 'Metode tidak didukung'],
                    429 => ['too_many_requests', 'Terlalu banyak permintaan'],
                    default => ['error', 'Permintaan gagal'],
                };

                return response()->json(['ok' => false, 'status' => $status, 'message' => $message], $code, $e->getHeaders());
            }

            if (config('app.debug')) {
                return null; // biarkan Laravel menampilkan detail error saat debug
            }

            return response()->json([
                'ok' => false,
                'status' => 'error',
                'message' => 'Kesalahan server',
            ], 500);
        });
    })->create();
