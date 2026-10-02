<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Device;
use App\Models\Member;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Once;
use Tests\TestCase;

/**
 * Mode absen (firmware 1.6.0+): POST /check-in & /check-out (mode pilih), config.tap_mode, raw.tap_mode &
 * raw.tap_select dari heartbeat, dan pengaturannya di halaman Alat.
 */
class TapModeTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'rahasia-kantor-123';

    private const DEVICE = 'ABS-1A2B3C';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-30 07:45:12', 'Asia/Jakarta'));
        Setting::setValue(Setting::API_KEY, self::KEY);
    }

    private function headers(string $key = self::KEY): array
    {
        return ['X-API-Key' => $key, 'X-Device-ID' => self::DEVICE, 'X-Spec-Version' => '1', 'Accept' => 'application/json'];
    }

    /** POST ke /tap, /check-in, atau /check-out dengan body seperti yang dikirim alat. */
    private function send(string $endpoint, string $rfid, array $extra = [])
    {
        static $counter = 0;

        $mode = ['check-in' => 'check_in', 'check-out' => 'check_out'][$endpoint] ?? null;

        return $this->postJson('/api/absensi/'.$endpoint, $extra + array_filter([
            'device_id' => self::DEVICE,
            'tap_id' => '1A2B3C-'.sprintf('%08X', ++$counter),
            'rfid' => $rfid,
            'tapped_at' => now()->toIso8601String(),
            'queued' => false,
            'mode' => $mode,
            'raw' => ['uid_hex' => '0A0B0C0D', 'firmware' => '1.6.0'],
        ], fn ($value) => $value !== null), $this->headers());
    }

    private function heartbeat(array $raw)
    {
        return $this->postJson('/api/absensi/heartbeat', ['device_id' => self::DEVICE, 'firmware' => '1.6.0', 'raw' => $raw], $this->headers());
    }

    private function device(): Device
    {
        return Device::where('code', self::DEVICE)->sole();
    }

    private function budi(): Member
    {
        return Member::factory()->create(['name' => 'Budi Santoso', 'card_uid' => '0218893066']);
    }

    // ----- POST /check-in -----

    public function test_check_in_records_check_in_then_duplicate_for_the_rest_of_the_day(): void
    {
        $member = $this->budi();

        $this->send('check-in', '0218893066')
            ->assertOk()
            ->assertExactJson(['ok' => true, 'status' => 'check_in', 'name' => 'Budi Santoso', 'message' => 'Selamat datang', 'time' => '07:45']);

        // Datang lagi kapan pun di hari yang sama -> duplicate dengan jam check_in pertama.
        $this->travelTo(Carbon::parse('2026-09-30 13:20:00', 'Asia/Jakarta'));
        $this->send('check-in', '0218893066')
            ->assertOk()
            ->assertExactJson(['ok' => true, 'status' => 'duplicate', 'name' => 'Budi Santoso', 'message' => 'Sudah absen datang', 'time' => '07:45']);

        $attendances = Attendance::orderBy('id')->get();
        $this->assertSame([['success', 'check_in'], ['duplicate', null]], $attendances->map(fn ($a) => [$a->status->value, $a->type?->value])->all());
        $this->assertSame($member->id, $attendances[0]->member_id);
        $this->assertSame('check_in', $attendances[0]->payload['mode']);
        $this->assertSame('0218893066', $attendances[0]->payload['rfid']);

        // Hari berikutnya datang lagi.
        $this->travelTo(Carbon::parse('2026-10-01 07:50:00', 'Asia/Jakarta'));
        $this->send('check-in', '0218893066')->assertJson(['status' => 'check_in', 'time' => '07:50']);
    }

    public function test_check_in_after_check_out_on_the_same_day_is_still_check_in(): void
    {
        $this->budi();

        $this->send('check-out', '0218893066')->assertJson(['status' => 'check_out']);
        $this->travel(10)->seconds();
        $this->send('check-in', '0218893066')->assertJson(['status' => 'check_in', 'message' => 'Selamat datang']);
    }

    // ----- POST /check-out -----

    public function test_check_out_without_check_in_and_duplicate_within_window(): void
    {
        $this->budi();
        $this->travelTo(Carbon::parse('2026-09-30 16:02:00', 'Asia/Jakarta'));

        $this->send('check-out', '0218893066')
            ->assertOk()
            ->assertExactJson([
                'ok' => true, 'status' => 'check_out', 'name' => 'Budi Santoso', 'message' => 'Hati-hati di jalan',
                'time' => '16:02', 'info' => ['Belum absen datang hari ini'],
            ]);

        $this->travel(40)->seconds();
        $this->send('check-out', '0218893066')
            ->assertOk()
            ->assertExactJson(['ok' => true, 'status' => 'duplicate', 'name' => 'Budi Santoso', 'message' => 'Sudah tercatat', 'time' => '16:02']);

        // Lewat jeda tap ganda: pulang dicatat lagi (jam pulang = check_out terakhir).
        $this->travel(2)->minutes();
        $this->send('check-out', '0218893066')->assertJson(['status' => 'check_out', 'time' => '16:04']);

        $this->assertSame(
            [['success', 'check_out'], ['duplicate', null], ['success', 'check_out']],
            Attendance::orderBy('id')->get()->map(fn ($a) => [$a->status->value, $a->type?->value])->all(),
        );
    }

    public function test_check_out_shows_check_in_time_and_ignores_the_check_in_for_duplicates(): void
    {
        $this->budi();

        $this->send('check-in', '0218893066')->assertJson(['status' => 'check_in', 'time' => '07:45']);

        // Pulang 20 detik setelah datang tetap dicatat (jeda tap ganda hanya membandingkan check_out).
        $this->travel(20)->seconds();
        $this->send('check-out', '0218893066')
            ->assertExactJson([
                'ok' => true, 'status' => 'check_out', 'name' => 'Budi Santoso', 'message' => 'Hati-hati di jalan',
                'time' => '07:45', 'info' => ['Masuk tadi 07:45'],
            ]);

        $this->travelTo(Carbon::parse('2026-09-30 16:02:00', 'Asia/Jakarta'));
        $this->send('check-out', '0218893066')->assertJson(['status' => 'check_out', 'time' => '16:02', 'info' => ['Masuk tadi 07:45']]);
    }

    public function test_duplicate_window_is_configurable_for_check_out(): void
    {
        config(['absensi.duplicate_window_seconds' => 10]);
        $this->budi();

        $this->send('check-out', '0218893066')->assertJson(['status' => 'check_out']);
        $this->travel(15)->seconds();
        $this->send('check-out', '0218893066')->assertJson(['status' => 'check_out']);
    }

    // ----- Sama dengan /tap -----

    public function test_unknown_and_inactive_cards_on_both_endpoints(): void
    {
        Member::factory()->inactive()->create(['name' => 'Siti Aminah', 'card_uid' => '0287454020']);

        foreach (['check-in', 'check-out'] as $endpoint) {
            $this->send($endpoint, '0000012345')
                ->assertOk()
                ->assertExactJson(['ok' => false, 'status' => 'unknown', 'message' => 'Kartu belum terdaftar']);

            $this->send($endpoint, '0287454020')
                ->assertOk()
                ->assertExactJson(['ok' => false, 'status' => 'rejected', 'name' => 'Siti Aminah', 'message' => 'Kartu nonaktif']);
        }

        $this->assertSame(
            ['unknown_card', 'inactive', 'unknown_card', 'inactive'],
            Attendance::orderBy('id')->pluck('status')->map->value->all(),
        );
    }

    public function test_auth_and_malformed_requests_are_rejected_like_tap(): void
    {
        foreach (['check-in', 'check-out'] as $endpoint) {
            $this->postJson('/api/absensi/'.$endpoint, ['rfid' => '0218893066'], $this->headers('salah'))
                ->assertStatus(401)
                ->assertExactJson(['ok' => false, 'message' => 'API key salah']);

            $this->postJson('/api/absensi/'.$endpoint, ['rfid' => ''], $this->headers())
                ->assertStatus(400)
                ->assertExactJson(['ok' => false, 'message' => 'Nomor kartu tidak valid']);

            $this->postJson('/api/absensi/'.$endpoint, ['rfid' => '0218893066', 'tap_id' => 123], $this->headers())
                ->assertStatus(400)
                ->assertExactJson(['ok' => false, 'message' => 'tap_id tidak valid']);
        }

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_tap_id_is_idempotent_across_tap_check_in_and_check_out(): void
    {
        $this->budi();

        // Dicatat di /tap, dikirim ulang ke /check-out & /check-in: tidak dicatat lagi.
        $this->send('tap', '0218893066', ['tap_id' => 'T1'])->assertJson(['status' => 'check_in', 'time' => '07:45']);
        $this->travel(10)->minutes();
        foreach (['check-out', 'check-in', 'tap'] as $endpoint) {
            $this->send($endpoint, '0218893066', ['tap_id' => 'T1', 'queued' => true, 'tapped_at' => '2026-09-30T07:45:12+07:00'])
                ->assertOk()
                ->assertExactJson(['ok' => true, 'status' => 'duplicate', 'name' => 'Budi Santoso', 'message' => 'Sudah tercatat', 'time' => '07:45']);
        }
        $this->assertDatabaseCount('attendances', 1);

        // Dicatat di /check-out, dikirim ulang ke /tap.
        $this->send('check-out', '0218893066', ['tap_id' => 'T2'])->assertJson(['status' => 'check_out', 'time' => '07:55']);
        $this->send('tap', '0218893066', ['tap_id' => 'T2'])->assertJson(['status' => 'duplicate', 'time' => '07:55']);
        $this->assertDatabaseCount('attendances', 2);

        // Kartu tidak dikenal: hasil yang sama diulang.
        $this->send('check-in', '0000012345', ['tap_id' => 'T3'])->assertJson(['status' => 'unknown']);
        $this->send('check-out', '0000012345', ['tap_id' => 'T3'])->assertExactJson(['ok' => false, 'status' => 'unknown', 'message' => 'Kartu belum terdaftar']);
        $this->assertDatabaseCount('attendances', 3);
    }

    public function test_tap_after_select_mode_uses_the_same_attendance_data(): void
    {
        $this->budi();

        $this->send('check-in', '0218893066')->assertJson(['status' => 'check_in']);
        $this->travelTo(Carbon::parse('2026-09-30 16:00:00', 'Asia/Jakarta'));
        $this->send('tap', '0218893066')->assertJson(['status' => 'check_out', 'message' => 'Sampai jumpa', 'info' => ['Masuk 07:45']]);
    }

    public function test_queued_taps_use_tapped_at_like_tap(): void
    {
        $this->budi();
        $this->travelTo(Carbon::parse('2026-09-30 09:10:00', 'Asia/Jakarta'));

        $this->send('check-in', '0218893066', ['queued' => true, 'tapped_at' => '2026-09-30T07:30:05+07:00'])
            ->assertJson(['status' => 'check_in', 'time' => '07:30']);

        // Offset lain dikonversi ke Asia/Jakarta: 01:00Z = 08:00 WIB.
        $this->send('check-out', '0218893066', ['queued' => true, 'tapped_at' => '2026-09-30T01:00:00Z'])
            ->assertJson(['status' => 'check_out', 'time' => '08:00', 'info' => ['Masuk tadi 07:30']]);

        // tapped_at null atau di luar batas (> 5 menit di masa depan / > 30 hari lalu) -> waktu diterima.
        $this->send('check-out', '0218893066', ['queued' => true, 'tapped_at' => null])->assertJson(['status' => 'check_out', 'time' => '09:10']);
        $this->travel(2)->minutes();
        $this->send('check-out', '0218893066', ['queued' => true, 'tapped_at' => '2026-09-30T09:20:00+07:00'])->assertJson(['status' => 'check_out', 'time' => '09:12']);
        $this->travel(2)->minutes();
        $this->send('check-out', '0218893066', ['queued' => true, 'tapped_at' => '2026-08-30T09:00:00+07:00'])->assertJson(['status' => 'check_out', 'time' => '09:14']);

        // Tap biasa selalu memakai jam server.
        $this->travel(2)->minutes();
        $this->send('check-out', '0218893066', ['tapped_at' => '2026-09-30T06:00:00+07:00'])->assertJson(['time' => '09:16']);

        $this->assertSame(
            ['07:30:05', '08:00:00', '09:10:00', '09:12:00', '09:14:00', '09:16:00'],
            Attendance::orderBy('id')->get()->map(fn ($a) => $a->tapped_at->format('H:i:s'))->all(),
        );
    }

    public function test_messages_fit_the_screen(): void
    {
        foreach (['Sudah absen datang', 'Hati-hati di jalan'] as $message) {
            $this->assertLessThanOrEqual(32, mb_strlen($message));
        }
        foreach (['Masuk tadi 07:45', 'Belum absen datang hari ini'] as $info) {
            $this->assertLessThanOrEqual(40, mb_strlen($info));
        }
    }

    // ----- config.tap_mode & heartbeat -----

    public function test_config_tap_mode_is_sent_only_when_set(): void
    {
        $this->getJson('/api/absensi/ping', $this->headers())->assertOk()->assertJsonMissingPath('config.tap_mode');
        $this->heartbeat([])->assertOk()->assertJsonMissingPath('config.tap_mode');

        foreach (['select', 'auto'] as $mode) {
            $this->device()->update(['tap_mode' => $mode]);
            $this->getJson('/api/absensi/ping', $this->headers())->assertJsonPath('config.tap_mode', $mode);
            $this->heartbeat([])->assertJsonPath('config.tap_mode', $mode);
        }

        // Alat lain tidak ikut.
        $this->getJson('/api/absensi/ping', ['X-Device-ID' => 'ABS-0002'] + $this->headers())->assertJsonMissingPath('config.tap_mode');

        $this->device()->update(['tap_mode' => null]);
        $this->getJson('/api/absensi/ping', $this->headers())->assertJsonMissingPath('config.tap_mode');
    }

    public function test_heartbeat_stores_reported_tap_mode_and_selection(): void
    {
        $this->heartbeat(['tap_mode' => 'select', 'tap_select' => 'check_out'])->assertOk();
        $this->assertSame(['select', 'check_out'], [$this->device()->reported_tap_mode, $this->device()->tap_select]);

        // tap_select tidak dikirim / null = belum memilih.
        $this->heartbeat(['tap_mode' => 'select'])->assertOk();
        $this->assertSame(['select', null], [$this->device()->reported_tap_mode, $this->device()->tap_select]);

        $this->heartbeat(['tap_mode' => 'auto', 'tap_select' => null])->assertOk();
        $this->assertSame(['auto', null], [$this->device()->reported_tap_mode, $this->device()->tap_select]);

        // Nilai tidak sah dan firmware lama (tanpa tap_mode) = null.
        $this->heartbeat(['tap_mode' => 'manual', 'tap_select' => 'pulang'])->assertOk();
        $this->assertSame([null, null], [$this->device()->reported_tap_mode, $this->device()->tap_select]);

        $this->heartbeat(['tap_mode' => 'select', 'tap_select' => 'check_in']);
        $this->heartbeat([])->assertOk();
        $this->assertSame([null, null], [$this->device()->reported_tap_mode, $this->device()->tap_select]);

        // Pengaturan dari server tidak diubah oleh laporan alat.
        $this->assertNull($this->device()->tap_mode);
    }

    // ----- Halaman Alat -----

    public function test_admin_sets_tap_mode_and_sees_reported_mode(): void
    {
        $admin = User::where('username', 'admin')->sole();
        $this->heartbeat(['tap_mode' => 'select', 'tap_select' => 'check_in']);
        $device = $this->device();

        $this->actingAs($admin)->get(route('admin.devices.index'))
            ->assertOk()
            ->assertSeeInOrder(['Mode absen di alat', 'Pilih Datang/Pulang', 'Pilihan saat ini: Datang'])
            ->assertSeeInOrder(['name="tap_mode"', '<option value="">Ikuti pengaturan di alat</option>', 'value="auto"', 'Otomatis (1 endpoint /tap)', 'value="select"', 'Pilih Datang/Pulang di alat'], false);

        $this->put(route('admin.devices.update', $device), ['name' => 'Pintu Utama', 'tap_mode' => 'select'])
            ->assertRedirect(route('admin.devices.index'))
            ->assertSessionHasNoErrors();
        $this->assertSame('select', $device->fresh()->tap_mode);
        $this->get(route('admin.devices.index'))->assertSee('<option value="select" selected>', false);

        $this->put(route('admin.devices.update', $device), ['name' => 'Pintu Utama', 'tap_mode' => 'auto'])->assertSessionHasNoErrors();
        $this->assertSame('auto', $device->fresh()->tap_mode);

        // Kosong = ikuti pengaturan di alat (null); tidak dikirim = tidak diubah.
        $this->put(route('admin.devices.update', $device), ['name' => 'Pintu Utama', 'tap_mode' => ''])->assertSessionHasNoErrors();
        $this->assertNull($device->fresh()->tap_mode);
        $device->update(['tap_mode' => 'auto']);
        $this->put(route('admin.devices.update', $device), ['name' => 'Pintu Utama'])->assertSessionHasNoErrors();
        $this->assertSame('auto', $device->fresh()->tap_mode);

        foreach (['manual', 'AUTO', 'check_in'] as $invalid) {
            $this->put(route('admin.devices.update', $device), ['name' => 'Pintu Utama', 'tap_mode' => $invalid])
                ->assertSessionHasErrorsIn('device-'.$device->id, ['tap_mode' => 'Mode absen tidak dikenal.']);
        }
        $this->assertSame('auto', $device->fresh()->tap_mode);

        // Pilihan belum dibuat / mode otomatis / firmware lama.
        $this->heartbeat(['tap_mode' => 'select']);
        $this->get(route('admin.devices.index'))->assertSee('Pilihan saat ini: belum dipilih');
        $this->heartbeat(['tap_mode' => 'auto']);
        $this->get(route('admin.devices.index'))->assertSeeInOrder(['Mode absen di alat', 'Otomatis'])->assertDontSee('Pilihan saat ini');
        $this->heartbeat([]);
        $this->get(route('admin.devices.index'))->assertSee('belum dilaporkan (firmware 1.6.0+)');
    }

    // ----- Sebelum migrasi -----

    public function test_api_and_devices_page_still_work_before_tap_mode_migration(): void
    {
        // Batalkan migrasi mode absen di dalam transaksi tes (DDL PostgreSQL ikut di-rollback setelah tes).
        (require database_path('migrations/2026_10_02_000001_add_tap_mode_columns_to_devices_table.php'))->down();
        Once::flush();
        $this->assertFalse(Device::tapModeReady());

        $this->budi();

        $this->getJson('/api/absensi/ping', $this->headers())->assertOk()->assertJsonMissingPath('config.tap_mode');
        $this->heartbeat(['tap_mode' => 'select', 'tap_select' => 'check_in', 'uptime_s' => 60])
            ->assertOk()
            ->assertJsonMissingPath('config.tap_mode');
        $this->send('tap', '0218893066')->assertOk()->assertJson(['status' => 'check_in']);
        $this->send('check-in', '0218893066')->assertOk()->assertJson(['status' => 'duplicate', 'message' => 'Sudah absen datang']);
        $this->travel(2)->minutes();
        $this->send('check-out', '0218893066')->assertOk()->assertJson(['status' => 'check_out', 'info' => ['Masuk tadi 07:45']]);
        $this->assertSame(60, $this->device()->uptime_s);

        $admin = User::where('username', 'admin')->sole();
        $this->actingAs($admin)->get(route('admin.devices.index'))->assertOk()->assertDontSee('Mode absen');
        $this->put(route('admin.devices.update', $this->device()), ['name' => 'Pintu Utama', 'tap_mode' => 'select'])
            ->assertRedirect(route('admin.devices.index'))
            ->assertSessionHasNoErrors();
        $this->assertSame('Pintu Utama', $this->device()->name);
    }
}
