<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Once;
use Tests\TestCase;

/**
 * Jadwal mode pilih (firmware 1.11.0+): config.tap_schedule, raw.tap_schedule dari heartbeat, dan pengaturannya di
 * halaman Alat.
 */
class TapScheduleTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'rahasia-kantor-123';

    private const DEVICE = 'ABS-1A2B3C';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-14 07:45:12', 'Asia/Jakarta'));
        Setting::setValue(Setting::API_KEY, self::KEY);
    }

    private function headers(): array
    {
        return ['X-API-Key' => self::KEY, 'X-Device-ID' => self::DEVICE, 'X-Spec-Version' => '1', 'Accept' => 'application/json'];
    }

    private function ping()
    {
        return $this->getJson('/api/absensi/ping', $this->headers());
    }

    private function heartbeat(array $raw)
    {
        return $this->postJson('/api/absensi/heartbeat', ['device_id' => self::DEVICE, 'firmware' => '1.11.0', 'raw' => $raw], $this->headers());
    }

    private function device(): Device
    {
        return Device::where('code', self::DEVICE)->sole();
    }

    private function save(Device $device, array $schedule)
    {
        return $this->put(route('admin.devices.update', $device), ['name' => 'Pintu Utama'] + $schedule);
    }

    // ----- config.tap_schedule -----

    public function test_config_tap_schedule_is_object_when_set_and_null_otherwise(): void
    {
        // Belum diatur: null (alat mematikan jadwalnya).
        $this->ping()->assertOk()->assertJsonPath('config.tap_schedule', null);
        $this->assertArrayHasKey('tap_schedule', $this->ping()->json('config'));
        $this->heartbeat([])->assertOk()->assertJsonPath('config.tap_schedule', null);

        $this->device()->update(['schedule_check_in' => '06:00', 'schedule_check_out' => '17:00']);
        $this->ping()->assertJsonPath('config.tap_schedule', ['check_in' => '06:00', 'check_out' => '17:00']);
        $this->heartbeat([])->assertJsonPath('config.tap_schedule', ['check_in' => '06:00', 'check_out' => '17:00']);

        // Alat lain tidak ikut.
        $this->getJson('/api/absensi/ping', ['X-Device-ID' => 'ABS-0002'] + $this->headers())->assertJsonPath('config.tap_schedule', null);

        // Dikosongkan admin: null lagi.
        $this->device()->update(['schedule_check_in' => null, 'schedule_check_out' => null]);
        $this->ping()->assertJsonPath('config.tap_schedule', null);
        $this->assertArrayHasKey('tap_schedule', $this->ping()->json('config'));
    }

    public function test_config_tap_schedule_is_null_when_stored_values_are_incomplete_or_invalid(): void
    {
        $this->ping();

        foreach ([['06:00', null], [null, '17:00'], ['17:00', '06:00'], ['08:00', '08:00'], ['6:00', '17:00']] as [$in, $out]) {
            $this->device()->update(['schedule_check_in' => $in, 'schedule_check_out' => $out]);
            $this->ping()->assertJsonPath('config.tap_schedule', null);
        }
    }

    // ----- raw.tap_schedule dari heartbeat -----

    public function test_heartbeat_stores_reported_tap_schedule(): void
    {
        $this->heartbeat(['tap_mode' => 'select', 'tap_schedule' => ['check_in' => '06:00', 'check_out' => '17:00']])->assertOk();
        $this->assertSame('06:00-17:00', $this->device()->reported_tap_schedule);

        // null, tidak dikirim (firmware lama) dan nilai tidak sah = null.
        foreach ([['tap_schedule' => null], [], ['tap_schedule' => ['check_in' => '17:00', 'check_out' => '06:00']], ['tap_schedule' => '06:00-17:00']] as $raw) {
            $this->heartbeat(['tap_schedule' => ['check_in' => '06:00', 'check_out' => '17:00']]);
            $this->heartbeat($raw)->assertOk();
            $this->assertNull($this->device()->reported_tap_schedule);
        }

        // Pengaturan dari server tidak diubah oleh laporan alat.
        $this->assertNull($this->device()->schedule_check_in);
    }

    // ----- Halaman Alat -----

    public function test_admin_sets_and_clears_tap_schedule(): void
    {
        $admin = User::where('username', 'admin')->sole();
        $this->heartbeat(['tap_mode' => 'select', 'tap_select' => 'check_in', 'tap_schedule' => ['check_in' => '06:30', 'check_out' => '16:00']]);
        $device = $this->device();

        $this->actingAs($admin)->get(route('admin.devices.index'))
            ->assertOk()
            ->assertSeeInOrder(['Mode absen di alat', 'Pilihan saat ini: Datang', 'Jadwal di alat:', '06:30–16:00'])
            ->assertSeeInOrder(['Jadwal DATANG otomatis', 'name="schedule_check_in"', 'Jadwal PULANG otomatis', 'name="schedule_check_out"'], false)
            ->assertSee('Mode Pilih tanpa jadwal');

        $this->save($device, ['schedule_check_in' => '06:00', 'schedule_check_out' => '17:00'])
            ->assertRedirect(route('admin.devices.index'))
            ->assertSessionHasNoErrors();
        $this->assertSame(['06:00', '17:00'], [$device->fresh()->schedule_check_in, $device->fresh()->schedule_check_out]);
        $this->get(route('admin.devices.index'))
            ->assertSee('value="06:00"', false)
            ->assertSee('value="17:00"', false)
            ->assertSeeInOrder(['DATANG otomatis pukul', '06:00', 'PULANG pukul', '17:00']);

        // Tidak dikirim = tidak diubah.
        $this->save($device, [])->assertSessionHasNoErrors();
        $this->assertSame(['06:00', '17:00'], [$device->fresh()->schedule_check_in, $device->fresh()->schedule_check_out]);

        // Dikosongkan keduanya = tanpa jadwal.
        $this->save($device, ['schedule_check_in' => '', 'schedule_check_out' => ''])->assertSessionHasNoErrors();
        $this->assertSame([null, null], [$device->fresh()->schedule_check_in, $device->fresh()->schedule_check_out]);
        $this->ping()->assertJsonPath('config.tap_schedule', null);
    }

    public function test_admin_tap_schedule_validation(): void
    {
        $admin = User::where('username', 'admin')->sole();
        $this->ping();
        $device = $this->device();
        $device->update(['schedule_check_in' => '06:00', 'schedule_check_out' => '17:00']);
        $bag = 'device-'.$device->id;
        $this->actingAs($admin);

        $both = 'Jadwal DATANG & PULANG harus diisi keduanya, atau dikosongkan keduanya.';
        $later = 'Jadwal PULANG harus lebih lambat dari jadwal DATANG.';

        $cases = [
            [['schedule_check_in' => '25:00', 'schedule_check_out' => '17:00'], ['schedule_check_in' => 'Jadwal DATANG harus berformat JJ:MM (00:00–23:59).']],
            [['schedule_check_in' => '06:00', 'schedule_check_out' => '7pm'], ['schedule_check_out' => 'Jadwal PULANG harus berformat JJ:MM (00:00–23:59).']],
            [['schedule_check_in' => '6:00', 'schedule_check_out' => '17:00'], ['schedule_check_in' => 'Jadwal DATANG harus berformat JJ:MM (00:00–23:59).']],
            [['schedule_check_in' => '17:00', 'schedule_check_out' => '06:00'], ['schedule_check_out' => $later]],
            [['schedule_check_in' => '08:00', 'schedule_check_out' => '08:00'], ['schedule_check_out' => $later]],
            [['schedule_check_in' => '06:00', 'schedule_check_out' => ''], ['schedule_check_out' => $both]],
            [['schedule_check_in' => '', 'schedule_check_out' => '17:00'], ['schedule_check_in' => $both]],
            [['schedule_check_out' => '17:00'], ['schedule_check_in' => $both]],
        ];

        foreach ($cases as [$input, $errors]) {
            $this->save($device, $input)->assertSessionHasErrorsIn($bag, $errors);
        }

        // Jadwal lama tidak berubah.
        $this->assertSame(['06:00', '17:00'], [$device->fresh()->schedule_check_in, $device->fresh()->schedule_check_out]);

        // Batas: 00:00 – 23:59 sah.
        $this->save($device, ['schedule_check_in' => '00:00', 'schedule_check_out' => '23:59'])->assertSessionHasNoErrors();
        $this->assertSame(['00:00', '23:59'], [$device->fresh()->schedule_check_in, $device->fresh()->schedule_check_out]);
    }

    // ----- Sebelum migrasi -----

    public function test_api_and_devices_page_still_work_before_tap_schedule_migration(): void
    {
        // Batalkan migrasi jadwal di dalam transaksi tes (DDL PostgreSQL ikut di-rollback setelah tes).
        (require database_path('migrations/2026_10_14_000001_add_tap_schedule_to_devices_table.php'))->down();
        Once::flush();
        $this->assertFalse(Device::scheduleReady());

        $this->ping()->assertOk()->assertJsonMissingPath('config.tap_schedule');
        $this->heartbeat(['tap_mode' => 'select', 'tap_schedule' => ['check_in' => '06:00', 'check_out' => '17:00'], 'uptime_s' => 60])
            ->assertOk()
            ->assertJsonMissingPath('config.tap_schedule');
        $this->assertSame(['select', 60], [$this->device()->reported_tap_mode, $this->device()->uptime_s]);

        $admin = User::where('username', 'admin')->sole();
        $this->actingAs($admin)->get(route('admin.devices.index'))->assertOk()->assertSee('Mode absen')->assertDontSee('Jadwal DATANG');
        $this->save($this->device(), ['schedule_check_in' => '06:00', 'schedule_check_out' => '17:00'])
            ->assertRedirect(route('admin.devices.index'))
            ->assertSessionHasNoErrors();
        $this->assertSame('Pintu Utama', $this->device()->name);
    }
}
