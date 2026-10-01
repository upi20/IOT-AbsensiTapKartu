<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Akses lewat Cloudflare Tunnel selalu HTTPS di sisi browser, walaupun cloudflared
 * meneruskan ke http://127.0.0.1:8133. Jika request datang lewat HTTPS (X-Forwarded-Proto,
 * proxy sudah dipercaya) atau memakai host publik APP_URL, semua URL/redirect/form
 * dibangkitkan dengan https dan cookie sesi diberi atribut Secure.
 * Akses langsung http://127.0.0.1:8133 tetap memakai http agar bisa dipakai lokal.
 */
class SecureUrlsBehindTunnel
{
    public function handle(Request $request, Closure $next): Response
    {
        $appUrl = (string) config('app.url');
        $publicHost = parse_url($appUrl, PHP_URL_HOST);

        $viaPublicHttps = str_starts_with($appUrl, 'https://')
            && is_string($publicHost)
            && strcasecmp($request->getHost(), $publicHost) === 0;

        if ($request->isSecure() || $viaPublicHttps) {
            URL::forceScheme('https');
            config(['session.secure' => true]);
        }

        return $next($request);
    }
}
