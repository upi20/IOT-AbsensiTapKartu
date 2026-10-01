<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AttendanceStatus;
use App\Enums\AttendanceType;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Device;
use App\Models\Member;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API lama /api/v1 (X-Device-Key per alat). USANG: dipertahankan hanya untuk firmware lama.
 * Alat baru memakai API standar /api/absensi (lihat AbsensiController).
 */
class DeviceController extends Controller
{
    public function ping(Request $request): JsonResponse
    {
        $now = now();

        return response()->json([
            'ok' => true,
            'status' => 'pong',
            'device' => $this->device($request)->name,
            'time' => $now->toIso8601String(),
            'unix' => $now->getTimestamp(),
        ]);
    }

    public function tap(Request $request, AttendanceService $service): JsonResponse
    {
        $raw = $request->input('uid');
        $uid = is_string($raw) ? Member::normalizeUid($raw) : '';

        if (! Member::isValidUid($uid)) {
            return response()->json([
                'ok' => false,
                'status' => 'invalid',
                'message' => 'UID tidak valid',
            ], 422);
        }

        $result = $service->tap($this->device($request), $uid);
        $name = $result->member?->name;

        [$http, $body] = match ($result->status()) {
            AttendanceStatus::UnknownCard => [404, ['ok' => false, 'status' => 'unknown_card', 'message' => 'Kartu tidak terdaftar', 'uid' => $uid]],
            AttendanceStatus::Inactive => [403, ['ok' => false, 'status' => 'inactive', 'name' => $name, 'message' => 'Kartu nonaktif']],
            AttendanceStatus::Duplicate => [200, ['ok' => true, 'status' => 'duplicate', 'name' => $name, 'message' => 'Sudah tercatat',
                'time' => $result->earlier->tapped_at->format('H:i')]],
            AttendanceStatus::Success => [200, ['ok' => true, 'status' => $result->type()->value, 'name' => $name,
                'message' => $result->type() === AttendanceType::CheckIn ? 'Selamat datang' : 'Sampai jumpa',
                'time' => $result->attendance->tapped_at->format('H:i')]],
        };

        return response()->json($body, $http);
    }

    public function today(Request $request): JsonResponse
    {
        $limit = min(
            max((int) $request->query('limit', config('absensi.today_default_limit')), 1),
            (int) config('absensi.today_max_limit'),
        );

        $now = now();

        $items = Attendance::query()
            ->with('member:id,name')
            ->where('status', AttendanceStatus::Success)
            ->whereBetween('tapped_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])
            ->latest('tapped_at')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Attendance $a) => [
                'name' => $a->member?->name,
                'type' => $a->type?->value,
                'time' => $a->tapped_at->format('H:i'),
            ]);

        return response()->json([
            'ok' => true,
            'status' => 'ok',
            'date' => $now->toDateString(),
            'count' => $items->count(),
            'items' => $items,
        ]);
    }

    private function device(Request $request): Device
    {
        return $request->attributes->get('device');
    }
}
