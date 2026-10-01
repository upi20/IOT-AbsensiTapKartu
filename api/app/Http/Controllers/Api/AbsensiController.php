<?php

namespace App\Http\Controllers\Api;

use App\Enums\AttendanceStatus;
use App\Enums\AttendanceType;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Device;
use App\Models\FirmwareRelease;
use App\Models\Member;
use App\Models\Setting;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * API standar alat absensi — implementasi acuan dari doc/spesifikasi-api.md.
 * Base URL: {APP_URL}/api/absensi. Autentikasi: middleware AuthenticateAbsensiDevice.
 */
class AbsensiController extends Controller
{
    /** GET /ping — tes koneksi & sinkronisasi jam (spesifikasi bagian 3). */
    public function ping(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'message' => 'Terhubung ke '.Setting::title(),
            'server_time' => now()->toIso8601String(),
            'config' => $this->deviceConfig($request->attributes->get('device')),
        ]);
    }

    /**
     * POST /heartbeat — tanda alat aktif (bagian 5). Kontak alat sudah dicatat oleh middleware; di sini data
     * kesehatan (raw.uptime_s, reset_reason, rfid_ok, queue, RAM, error, ota_failed, crash) disimpan, kalau migrasinya
     * sudah dijalankan.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');
        if (Device::monitoringReady()) {
            $device->recordHeartbeat($request->json()->all());
        }

        return response()->json([
            'ok' => true,
            'server_time' => now()->toIso8601String(),
            'config' => $this->deviceConfig($request->attributes->get('device')),
        ]);
    }

    /**
     * GET /firmware/{release} — file .bin untuk update jarak jauh (bagian 6, config.firmware_update).
     * Hanya boleh diunduh alat yang dijadwalkan update ke firmware ini. Header x-MD5 diperiksa oleh alat.
     */
    public function firmware(Request $request, int $release): BinaryFileResponse|JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');
        $firmware = FirmwareRelease::find($release);

        if (! $firmware || $device->firmware_release_id !== $firmware->id || ! is_file($firmware->absolutePath())) {
            return response()->json(['ok' => false, 'message' => 'Firmware tidak tersedia untuk alat ini'], 404);
        }

        return response()->file($firmware->absolutePath(), [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) filesize($firmware->absolutePath()),
            'x-MD5' => $firmware->md5,
        ]);
    }

    /** GET /announcements — pengumuman untuk screensaver alat (bagian 8): hanya yang aktif, urut, maks. 10. */
    public function announcements(): JsonResponse
    {
        return response()->json(['ok' => true] + Announcement::deviceFeed());
    }

    /** POST /tap — kartu ditempelkan (bagian 4). Semua hasil bisnis dibalas HTTP 200. */
    public function tap(Request $request, AttendanceService $service): JsonResponse
    {
        $rfid = Member::normalizeUid(is_string($request->json('rfid')) ? $request->json('rfid') : '');

        if (! Member::isValidUid($rfid)) {
            return response()->json(['ok' => false, 'message' => 'Nomor kartu tidak valid'], 400);
        }

        $tapId = $request->json('tap_id');

        if ($tapId !== null && (! is_string($tapId) || $tapId === '' || strlen($tapId) > 64)) {
            return response()->json(['ok' => false, 'message' => 'tap_id tidak valid'], 400);
        }

        // Tap antrean (queued) memakai waktu tap asli dari alat (kalau null / tidak wajar: waktu diterima);
        // tap biasa memakai jam server.
        $queued = filter_var($request->json('queued'), FILTER_VALIDATE_BOOLEAN);
        $at = $queued ? $this->queuedTapTime($request->json('tapped_at')) : null;

        /** @var Device $device */
        $device = $request->attributes->get('device');
        $result = $service->tap($device, $rfid, $at, $request->json()->all(), $tapId);

        $member = $result->member;
        $tappedAt = $result->attendance->tapped_at;
        $status = $result->status();

        // Tap yang dikirim ulang dan dulu diterima dijawab "duplicate" dengan jam tap aslinya.
        if ($result->resent && in_array($status, [AttendanceStatus::Success, AttendanceStatus::Duplicate], true)) {
            $status = AttendanceStatus::Duplicate;
            $duplicateOf = $tappedAt;
        } else {
            $duplicateOf = $result->earlier?->tapped_at;
        }

        $body = match ($status) {
            AttendanceStatus::UnknownCard => [
                'ok' => false,
                'status' => 'unknown',
                'message' => 'Kartu belum terdaftar',
            ],
            AttendanceStatus::Inactive => [
                'ok' => false,
                'status' => 'rejected',
                'name' => $member?->name,
                'message' => 'Kartu nonaktif',
            ],
            AttendanceStatus::Duplicate => [
                'ok' => true,
                'status' => 'duplicate',
                'name' => $member?->name,
                'message' => 'Sudah tercatat',
                'time' => $duplicateOf->format('H:i'),
            ],
            AttendanceStatus::Success => $result->type() === AttendanceType::CheckIn
                ? [
                    'ok' => true,
                    'status' => 'check_in',
                    'name' => $member->name,
                    'message' => 'Selamat datang',
                    'time' => $tappedAt->format('H:i'),
                ]
                : [
                    'ok' => true,
                    'status' => 'check_out',
                    'name' => $member->name,
                    'message' => 'Sampai jumpa',
                    'time' => $tappedAt->format('H:i'),
                    'info' => $this->checkOutInfo($service, $member, $tappedAt),
                ],
        };

        if ($member?->photo_path) {
            $body['photo_url'] = $member->photoUrl();
        }

        return response()->json(array_filter($body, fn ($value) => $value !== null));
    }

    /**
     * Baris keterangan saat pulang, mis. ["Masuk 07:45"].
     *
     * @return list<string>
     */
    private function checkOutInfo(AttendanceService $service, Member $member, Carbon $tappedAt): array
    {
        $checkIn = $service->checkInTime($member, $tappedAt);

        return $checkIn ? ['Masuk '.$checkIn->format('H:i')] : [];
    }

    /**
     * Pengaturan jarak jauh (bagian 6). Hanya kunci yang diisi di panel yang dikirim, kecuali restart_at.
     * PIN dan jam restart diatur per alat (halaman Alat), judul berlaku untuk semua alat. announcements_rev berubah
     * setiap pengumuman / pengaturan screensaver berubah, sehingga alat mengambil ulang GET /announcements.
     * firmware_update hanya dikirim kalau alat dijadwalkan update dan versinya belum terpasang / belum pernah gagal.
     *
     * @return object{pin?: string, title?: string, dim_after?: int, dim_level?: int, announcements_rev: string, restart_at: string, firmware_update?: array{version: string, url: string, size: int, md5: string}}
     */
    private function deviceConfig(Device $device): object
    {
        // restart_at selalu dikirim: "" berarti alat tidak restart otomatis, jadi tidak ikut disaring.
        $config = array_filter([
            'pin' => $device->pin,
            'title' => Setting::getValue(Setting::TITLE),
            'dim_after' => Setting::dimAfter(),
            'dim_level' => Setting::dimLevel(),
            'announcements_rev' => Announcement::revision(),
        ], fn ($value) => $value !== null && $value !== '') + [
            'restart_at' => $device->restart_at ?? '',
        ];

        // URL dibangun dari request alat (skema & host sama dengan Base URL alat), seperti photo_url.
        if ($update = $device->pendingFirmwareUpdate()) {
            $config['firmware_update'] = [
                'version' => $update->version,
                'url' => route('absensi.firmware', $update),
                'size' => $update->size,
                'md5' => $update->md5,
            ];
        }

        return (object) $config;
    }

    /**
     * tapped_at tap antrean, mis. "2026-09-30T07:45:12+07:00" -> Carbon (Asia/Jakarta).
     * Null (= pakai waktu diterima) kalau kosong, tidak valid, lebih dari 5 menit di masa depan,
     * atau lebih dari 30 hari yang lalu (jam alat kemungkinan salah).
     */
    private function queuedTapTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value)) {
            return null;
        }

        try {
            $at = Carbon::parse($value)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }

        if ($at->gt(now()->addMinutes(5)) || $at->lt(now()->subDays(30))) {
            return null;
        }

        return $at;
    }
}
