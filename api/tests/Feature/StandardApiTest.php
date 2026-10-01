<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Device;
use App\Models\Member;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * API standar /api/absensi sesuai doc/spesifikasi-api.md.
 */
class StandardApiTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'rahasia-kantor-123';

    private const DEVICE = 'ABS-1A2B3C';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-30 07:45:12', 'Asia/Jakarta'));
        Setting::setValue(Setting::API_KEY, self::KEY);
        Setting::setValue(Setting::TITLE, 'PT Contoh Sejahtera');
    }

    private function headers(?string $key = self::KEY, ?string $device = self::DEVICE): array
    {
        return array_filter([
            'X-API-Key' => $key,
            'X-Device-ID' => $device,
            'X-Spec-Version' => '1',
            'Accept' => 'application/json',
        ]);
    }

    private function tap(string $rfid, array $extra = [], string $base = '/api/absensi')
    {
        static $counter = 0;

        return $this->postJson($base.'/tap', $extra + [
            'device_id' => self::DEVICE,
            'tap_id' => '1A2B3C-'.sprintf('%08X', ++$counter),
            'rfid' => $rfid,
            'tapped_at' => now()->toIso8601String(),
            'queued' => false,
            'raw' => ['uid_hex' => '0A0B0C0D', 'wifi_ssid' => 'Kantor-2.4G', 'rssi' => -52, 'ip' => '192.168.1.23', 'firmware' => '1.1.0'],
        ], $this->headers());
    }

    // ----- Autentikasi -----

    public function test_missing_or_wrong_api_key_is_401(): void
    {
        foreach ([null, 'salah'] as $key) {
            $this->getJson('/api/absensi/ping', $this->headers($key))
                ->assertStatus(401)
                ->assertExactJson(['ok' => false, 'message' => 'API key salah']);
        }

        $this->postJson('/api/absensi/tap', ['rfid' => '0218893066'], $this->headers('salah'))->assertStatus(401);
        $this->assertDatabaseCount('attendances', 0);
        $this->assertDatabaseCount('devices', 0);
    }

    public function test_missing_device_id_is_400(): void
    {
        foreach ([['GET', 'ping'], ['POST', 'tap'], ['POST', 'heartbeat']] as [$method, $path]) {
            $this->json($method, '/api/absensi/'.$path, [], $this->headers(device: null))
                ->assertStatus(400)
                ->assertJson(['ok' => false])
                ->assertJsonStructure(['message']);
        }
    }

    public function test_malformed_tap_is_400(): void
    {
        $this->postJson('/api/absensi/tap', ['tap_id' => 'x', 'rfid' => ''], $this->headers())
            ->assertStatus(400)->assertJson(['ok' => false, 'message' => 'Nomor kartu tidak valid']);

        $this->call('POST', '/api/absensi/tap', [], [], [], $this->transformHeadersToServerVars($this->headers() + ['Content-Type' => 'application/json']), 'bukan json')
            ->assertStatus(400)->assertJson(['ok' => false, 'message' => 'Body harus berupa JSON']);

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_validation_error_under_absensi_returns_400_with_message(): void
    {
        Route::post('/api/absensi/uji-validasi', fn (Request $request) => $request->validate(['rfid' => 'required']));

        $this->postJson('/api/absensi/uji-validasi', [], $this->headers())
            ->assertStatus(400)
            ->assertExactJson(['ok' => false, 'message' => 'Rfid wajib diisi.']);
    }

    // ----- Batas request -----

    public function test_returns_429_after_240_requests_per_minute_from_one_device(): void
    {
        for ($i = 0; $i < 240; $i++) {
            $this->getJson('/api/absensi/ping', $this->headers())->assertOk();
        }

        $this->getJson('/api/absensi/ping', $this->headers())
            ->assertStatus(429)
            ->assertExactJson(['ok' => false, 'message' => 'Terlalu banyak permintaan']);

        // Alat lain di IP yang sama punya jatah sendiri; menit berikutnya jatah pulih.
        $this->getJson('/api/absensi/ping', $this->headers(device: 'ABS-LAIN'))->assertOk();
        $this->travel(61)->seconds();
        $this->getJson('/api/absensi/ping', $this->headers())->assertOk();
    }

    public function test_returns_429_after_20_wrong_api_keys_per_minute_from_one_ip(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->getJson('/api/absensi/ping', $this->headers('tebakan-'.$i))->assertStatus(401);
        }

        $this->getJson('/api/absensi/ping', $this->headers('tebakan-lagi'))
            ->assertStatus(429)
            ->assertExactJson(['ok' => false, 'message' => 'Terlalu banyak permintaan']);

        // Selama diblokir, key yang benar dari IP itu juga 429 (balasan tidak membocorkan key yang benar).
        $this->getJson('/api/absensi/ping', $this->headers())->assertStatus(429);

        // IP lain tidak terpengaruh, dan blokir berakhir setelah 1 menit.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->getJson('/api/absensi/ping', $this->headers())->assertOk();
        $this->travel(61)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('/api/absensi/ping', $this->headers())->assertOk();
    }

    public function test_correct_api_key_does_not_count_toward_wrong_key_limit(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/absensi/ping', $this->headers())->assertOk();
        }

        $this->getJson('/api/absensi/ping', $this->headers('salah'))->assertStatus(401);
    }

    // ----- /ping & /heartbeat -----

    public function test_ping_returns_documented_shape_and_registers_device(): void
    {
        Device::create(['code' => self::DEVICE, 'name' => self::DEVICE, 'pin' => '4321']);

        $this->getJson('/api/absensi/ping', $this->headers())
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'message' => 'Terhubung ke PT Contoh Sejahtera',
                'server_time' => '2026-09-30T07:45:12+07:00',
                'config' => ['pin' => '4321', 'title' => 'PT Contoh Sejahtera', 'dim_after' => 60, 'dim_level' => 20, 'announcements_rev' => '0-0-3-30'],
            ]);

        $device = Device::where('code', self::DEVICE)->sole();
        $this->assertSame(self::DEVICE, $device->name);
        $this->assertTrue($device->isOnline());
    }

    public function test_config_omits_pin_when_not_set(): void
    {
        $this->getJson('/api/absensi/ping', $this->headers())
            ->assertOk()
            ->assertJsonPath('config', ['title' => 'PT Contoh Sejahtera', 'dim_after' => 60, 'dim_level' => 20, 'announcements_rev' => '0-0-3-30'])
            ->assertJsonMissingPath('config.pin');
    }

    public function test_heartbeat_creates_then_updates_device(): void
    {
        $body = [
            'device_id' => self::DEVICE,
            'firmware' => '1.1.0',
            'time' => '2026-09-30T07:46:00+07:00',
            'raw' => ['wifi_ssid' => 'Kantor-2.4G', 'rssi' => -52, 'ip' => '192.168.1.23', 'uptime_s' => 3660, 'queue' => 0],
        ];

        $this->postJson('/api/absensi/heartbeat', $body, $this->headers())
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'server_time' => '2026-09-30T07:45:12+07:00',
                'config' => ['title' => 'PT Contoh Sejahtera', 'dim_after' => 60, 'dim_level' => 20, 'announcements_rev' => '0-0-3-30'],
            ]);

        $device = Device::where('code', self::DEVICE)->sole();
        $this->assertSame('1.1.0', $device->firmware);
        $this->assertSame('192.168.1.23', $device->ip);
        $this->assertSame(-52, $device->rssi);
        $this->assertSame('Kantor-2.4G', $device->wifi_ssid);
        $this->assertSame(3660, $device->last_payload['raw']['uptime_s']);

        $this->travel(2)->minutes();
        $body['firmware'] = '1.2.0';
        $body['raw'] = null; // raw boleh null
        $this->postJson('/api/absensi/heartbeat', $body, $this->headers())->assertOk();

        $device->refresh();
        $this->assertDatabaseCount('devices', 1);
        $this->assertSame('1.2.0', $device->firmware);
        $this->assertSame('192.168.1.23', $device->ip); // tidak dihapus kalau tidak dikirim
        $this->assertTrue($device->last_seen_at->equalTo(now()->startOfSecond()));

        $this->travel(4)->minutes();
        $this->assertFalse($device->isOnline());
    }

    public function test_header_device_id_wins_over_body(): void
    {
        $this->postJson('/api/absensi/heartbeat', ['device_id' => 'ABS-LAIN'], $this->headers())->assertOk();

        $this->assertDatabaseHas('devices', ['code' => self::DEVICE]);
        $this->assertDatabaseMissing('devices', ['code' => 'ABS-LAIN']);
    }

    // ----- /tap -----

    public function test_first_tap_is_check_in(): void
    {
        $member = Member::factory()->create(['name' => 'Budi Santoso', 'card_uid' => '0218893066']);

        $this->tap('0218893066')
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'status' => 'check_in',
                'name' => 'Budi Santoso',
                'message' => 'Selamat datang',
                'time' => '07:45',
            ]);

        $attendance = Attendance::sole();
        $this->assertSame($member->id, $attendance->member_id);
        $this->assertSame('check_in', $attendance->type->value);
        $this->assertSame('0218893066', $attendance->payload['rfid']);
        $this->assertSame(self::DEVICE, $attendance->device->code);
        $this->assertSame('192.168.1.23', $attendance->device->ip);
    }

    public function test_later_tap_is_check_out_with_check_in_info(): void
    {
        Member::factory()->create(['name' => 'Budi Santoso', 'card_uid' => '0218893066']);

        $this->tap('0218893066')->assertJson(['status' => 'check_in']);

        $this->travelTo(Carbon::parse('2026-09-30 16:02:00', 'Asia/Jakarta'));
        $this->tap('0218893066')
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'status' => 'check_out',
                'name' => 'Budi Santoso',
                'message' => 'Sampai jumpa',
                'time' => '16:02',
                'info' => ['Masuk 07:45'],
            ]);
    }

    public function test_duplicate_within_60_seconds_returns_earlier_time(): void
    {
        Member::factory()->create(['name' => 'Budi Santoso', 'card_uid' => '0218893066']);

        $this->tap('0218893066')->assertJson(['status' => 'check_in']);

        $this->travel(40)->seconds();
        $this->tap('0218893066')
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'status' => 'duplicate',
                'name' => 'Budi Santoso',
                'message' => 'Sudah tercatat',
                'time' => '07:45',
            ]);

        $this->assertSame(['success', 'duplicate'], Attendance::orderBy('id')->pluck('status')->map->value->all());
    }

    public function test_unknown_card_is_recorded_for_registration(): void
    {
        $this->tap('0000012345')
            ->assertOk()
            ->assertExactJson(['ok' => false, 'status' => 'unknown', 'message' => 'Kartu belum terdaftar']);

        $this->assertDatabaseHas('attendances', ['member_id' => null, 'card_uid' => '0000012345', 'status' => 'unknown_card']);
    }

    public function test_inactive_member_is_rejected(): void
    {
        Member::factory()->inactive()->create(['name' => 'Siti Aminah', 'card_uid' => '0218893066']);

        $this->tap('0218893066')
            ->assertOk()
            ->assertExactJson(['ok' => false, 'status' => 'rejected', 'name' => 'Siti Aminah', 'message' => 'Kartu nonaktif']);

        $this->assertDatabaseHas('attendances', ['status' => 'inactive']);
    }

    public function test_queued_tap_uses_tapped_at(): void
    {
        Member::factory()->create(['name' => 'Budi Santoso', 'card_uid' => '0218893066']);
        $this->travelTo(Carbon::parse('2026-09-30 09:10:00', 'Asia/Jakarta'));

        // Tap asli jam 07:30 (dikirim dari antrean jam 09:10) -> masuk jam 07:30.
        $this->tap('0218893066', ['queued' => true, 'tapped_at' => '2026-09-30T07:30:05+07:00'])
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'check_in', 'time' => '07:30']);

        // Offset lain tetap dikonversi ke Asia/Jakarta: 01:00Z = 08:00 WIB -> pulang.
        $this->tap('0218893066', ['queued' => true, 'tapped_at' => '2026-09-30T01:00:00Z'])
            ->assertJson(['status' => 'check_out', 'time' => '08:00', 'info' => ['Masuk 07:30']]);

        // tapped_at null -> waktu diterima.
        $this->tap('0218893066', ['queued' => true, 'tapped_at' => null])->assertJson(['status' => 'check_out', 'time' => '09:10']);

        // Tap biasa (queued false) selalu memakai jam server walau tapped_at berbeda.
        $this->travel(5)->minutes();
        $this->tap('0218893066', ['tapped_at' => '2026-09-30T06:00:00+07:00'])->assertJson(['time' => '09:15']);

        $this->assertSame(
            ['07:30:05', '08:00:00', '09:10:00', '09:15:00'],
            Attendance::orderBy('id')->get()->map(fn ($a) => $a->tapped_at->format('H:i:s'))->all(),
        );
    }

    public function test_queued_tapped_at_outside_the_allowed_window_falls_back_to_receive_time(): void
    {
        Member::factory()->create(['name' => 'Budi Santoso', 'card_uid' => '0218893066']);

        // Diterima 30/09 07:45:12. Lebih dari 5 menit di masa depan -> waktu diterima.
        $this->tap('0218893066', ['queued' => true, 'tapped_at' => '2026-09-30T07:50:13+07:00'])
            ->assertJson(['status' => 'check_in', 'time' => '07:45']);

        // Lebih dari 30 hari yang lalu -> waktu diterima.
        $this->travel(2)->minutes();
        $this->tap('0218893066', ['queued' => true, 'tapped_at' => '2026-08-31T07:45:11+07:00'])
            ->assertJson(['status' => 'check_out', 'time' => '07:47']);

        // Tepat di dalam batas: dipakai apa adanya.
        $this->tap('0218893066', ['queued' => true, 'tapped_at' => '2026-09-30T07:52:12+07:00'])
            ->assertJson(['status' => 'check_out', 'time' => '07:52']);
        $this->tap('0218893066', ['queued' => true, 'tapped_at' => '2026-08-31T08:00:00+07:00'])
            ->assertJson(['status' => 'check_in', 'time' => '08:00']);

        $this->assertSame(
            ['2026-09-30 07:45:12', '2026-09-30 07:47:12', '2026-09-30 07:52:12', '2026-08-31 08:00:00'],
            Attendance::orderBy('id')->get()->map(fn ($a) => $a->tapped_at->format('Y-m-d H:i:s'))->all(),
        );
    }

    public function test_queued_tap_earlier_than_existing_check_in_becomes_the_check_in_of_the_day(): void
    {
        Member::factory()->create(['name' => 'Budi Santoso', 'card_uid' => '0218893066']);

        // Tap langsung 07:45 = masuk. Lalu tap antrean dari alat lain, jam aslinya 07:30.
        $this->tap('0218893066')->assertJson(['status' => 'check_in', 'time' => '07:45']);
        $this->tap('0218893066', ['queued' => true, 'tapped_at' => '2026-09-30T07:30:00+07:00'])
            ->assertJson(['status' => 'check_in', 'time' => '07:30']);

        // check_in lama tidak diubah; jam masuk hari itu = check_in paling awal.
        $this->assertSame(
            [['07:45:12', 'check_in'], ['07:30:00', 'check_in']],
            Attendance::orderBy('id')->get()->map(fn ($a) => [$a->tapped_at->format('H:i:s'), $a->type->value])->all(),
        );

        $this->travelTo(Carbon::parse('2026-09-30 16:00:00', 'Asia/Jakarta'));
        $this->tap('0218893066')->assertJson(['status' => 'check_out', 'time' => '16:00', 'info' => ['Masuk 07:30']]);
    }

    public function test_resent_tap_id_is_not_recorded_twice(): void
    {
        Member::factory()->create(['name' => 'Budi Santoso', 'card_uid' => '0218893066']);

        $this->tap('0218893066', ['tap_id' => '1A2B3C-5F3A9C21'])->assertJson(['status' => 'check_in', 'time' => '07:45']);

        // Alat mengirim ulang tap yang sama dari antrean, 10 menit kemudian.
        $this->travel(10)->minutes();
        $this->tap('0218893066', ['tap_id' => '1A2B3C-5F3A9C21', 'queued' => true, 'tapped_at' => '2026-09-30T07:45:12+07:00'])
            ->assertOk()
            ->assertExactJson(['ok' => true, 'status' => 'duplicate', 'name' => 'Budi Santoso', 'message' => 'Sudah tercatat', 'time' => '07:45']);

        $this->assertDatabaseCount('attendances', 1);

        // Tap baru (tap_id lain) tetap dicatat.
        $this->tap('0218893066')->assertJson(['status' => 'check_out']);
        $this->assertDatabaseCount('attendances', 2);
    }

    public function test_resent_unknown_tap_repeats_result(): void
    {
        $this->tap('0000012345', ['tap_id' => 'T1'])->assertJson(['status' => 'unknown']);
        $this->tap('0000012345', ['tap_id' => 'T1'])->assertExactJson(['ok' => false, 'status' => 'unknown', 'message' => 'Kartu belum terdaftar']);

        $this->assertDatabaseCount('attendances', 1);
    }

    public function test_same_tap_id_from_another_device_is_separate(): void
    {
        $this->tap('0000012345', ['tap_id' => 'T1']);
        $this->postJson('/api/absensi/tap', ['tap_id' => 'T1', 'rfid' => '0000012345', 'queued' => false, 'tapped_at' => null], $this->headers(device: 'ABS-999999'))->assertOk();

        $this->assertDatabaseCount('attendances', 2);
    }

    public function test_photo_url_is_absolute_when_member_has_photo(): void
    {
        Member::factory()->create(['card_uid' => '0218893066'])->forceFill(['photo_path' => 'anggota/1-abc.jpg'])->save();

        // Alat di jaringan lokal (Base URL http://192.168.1.10:8133/api/absensi): URL foto ikut host itu.
        $this->tap('0218893066', [], 'http://192.168.1.10:8133/api/absensi')
            ->assertJsonPath('photo_url', 'http://192.168.1.10:8133/storage/anggota/1-abc.jpg');

        // Lewat Cloudflare Tunnel (X-Forwarded-Proto: https) URL ikut https.
        $this->travel(2)->minutes();
        $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'absensi.example.com'])
            ->tap('0218893066', [], 'http://127.0.0.1:8133/api/absensi')
            ->assertJsonPath('photo_url', 'https://absensi.example.com/storage/anggota/1-abc.jpg');
    }

    public function test_forwarded_headers_from_a_non_local_address_are_ignored(): void
    {
        Member::factory()->create(['card_uid' => '0218893066'])->forceFill(['photo_path' => 'anggota/1-abc.jpg'])->save();

        // Hanya cloudflared lokal (127.0.0.1 / ::1) yang dipercaya sebagai proxy.
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.50'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'palsu.example'])
            ->tap('0218893066', [], 'http://192.168.1.10:8133/api/absensi')
            ->assertJsonPath('photo_url', 'http://192.168.1.10:8133/storage/anggota/1-abc.jpg');
    }

    public function test_no_photo_url_without_photo(): void
    {
        Member::factory()->create(['card_uid' => '0218893066']);

        $this->tap('0218893066')->assertJsonMissingPath('photo_url');
    }

    public function test_legacy_hex_card_is_found_by_10_digit_number(): void
    {
        // Kartu lama didaftarkan sebagai hex 0A0B0C0D (API v1); alat baru mengirim 0218893066.
        Member::factory()->create(['name' => 'Budi Santoso', 'card_uid' => '0A0B0C0D']);

        $this->tap('0218893066')->assertJson(['status' => 'check_in', 'name' => 'Budi Santoso']);
    }

    public function test_messages_fit_the_screen(): void
    {
        foreach (['Selamat datang', 'Sampai jumpa', 'Sudah tercatat', 'Kartu belum terdaftar', 'Kartu nonaktif', 'API key salah', 'Terlalu banyak permintaan'] as $message) {
            $this->assertLessThanOrEqual(32, mb_strlen($message));
        }
    }
}
