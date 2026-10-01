<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentikasi perangkat via header "X-Device-Key: <plain key>".
 * Perangkat valid disimpan di $request->attributes['device'] dan last_seen_at diperbarui.
 */
class AuthenticateDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = Device::findByPlainKey($request->header('X-Device-Key'));

        if ($device === null) {
            return response()->json([
                'ok' => false,
                'status' => 'unauthorized',
                'message' => 'Perangkat tidak dikenal',
            ], 401);
        }

        $device->forceFill(['last_seen_at' => now()])->saveQuietly();

        $request->attributes->set('device', $device);

        return $next($request);
    }
}
