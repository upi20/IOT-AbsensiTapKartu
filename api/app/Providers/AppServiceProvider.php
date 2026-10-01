<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Skema https untuk akses lewat Cloudflare Tunnel diatur per request oleh
        // App\Http\Middleware\SecureUrlsBehindTunnel (akses lokal http tetap berfungsi).

        Paginator::defaultView('admin.partials.pagination');
        Paginator::defaultSimpleView('admin.partials.pagination');

        // Lapisan throttle kedua untuk login panel admin (per IP). Batas per akun ada di AuthController.
        RateLimiter::for('admin-login', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // API alat: per alat (X-Device-ID) + IP. Sangat longgar: alat normal hanya beberapa request per
        // menit, dan antrean dikirim paling cepat 1 tap/detik. Tebakan API key dibatasi lebih ketat di
        // App\Http\Middleware\AuthenticateAbsensiDevice.
        RateLimiter::for('absensi-api', fn (Request $request) => Limit::perMinute(240)
            ->by($request->header('X-Device-ID', '').'|'.$request->ip())
            ->response(fn (Request $request, array $headers) => response()->json(
                ['ok' => false, 'message' => 'Terlalu banyak permintaan'], 429, $headers,
            )));
    }
}
