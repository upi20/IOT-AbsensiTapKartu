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
 * Daya alat dari modul UPS (firmware 1.11.2+): raw.power dari heartbeat, kejadian listrik padam/kembali & baterai
 * lemah, dan tampilannya di halaman Alat.
 */
class PowerStatusTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'rahasia-kantor-123';

    private const DEVICE = 'ABS-1A2B3C';

    private const MAINS = ['source' => 'mains', 'battery_mv' => 4105, 'battery_pct' => 92, 'battery_low' => false];

    private const BATTERY = ['source' => 'battery', 'battery_mv' => 3620, 'battery_pct' => 18, 'battery_low' => false];

    private const LOW = ['source' => 'battery', 'battery_mv' => 3380, 'battery_pct' => 5, 'battery_low' => true];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-14 07:45:12', 'Asia/Jakarta'));
        Setting::setValue(Setting::API_KEY, self::KEY);
    }

    private function heartbeat(array $raw)
    {
        return $this->postJson('/api/absensi/heartbeat', ['device_id' => self::DEVICE, 'firmware' => '1.11.2', 'raw' => $raw],
            ['X-API-Key' => self::KEY, 'X-Device-ID' => self::DEVICE, 'X-Spec-Version' => '1', 'Accept' => 'application/json']);
    }

    private function device(): Device
    {
        return Device::where('code', self::DEVICE)->sole();
    }

    /** @return array{0: ?string, 1: ?int, 2: ?int, 3: ?bool} */
    private function power(): array
    {
        $device = $this->device();

        return [$device->power_source, $device->battery_mv, $device->battery_pct, $device->battery_low];
    }

    /** @return list<array{0: string, 1: string}> */
    private function powerEvents(): array
    {
        return $this->device()->events()->whereIn('type', ['power_lost', 'power_restored', 'battery_low'])->orderBy('id')
            ->get()->map(fn ($event) => [$event->type, $event->message])->all();
    }

    // ----- raw.power dari heartbeat -----

    public function test_heartbeat_stores_power(): void
    {
        $this->heartbeat(['power' => self::MAINS])->assertOk();
        $this->assertSame(['mains', 4105, 92, false], $this->power());

        $this->heartbeat(['power' => self::BATTERY])->assertOk();
        $this->assertSame(['battery', 3620, 18, false], $this->power());

        $this->heartbeat(['power' => self::LOW])->assertOk();
        $this->assertSame(['battery', 3380, 5, true], $this->power());
    }

    public function test_null_means_no_ups_and_missing_key_keeps_last_value(): void
    {
        // Firmware lama (tanpa kunci power): belum diketahui.
        $this->heartbeat(['uptime_s' => 60])->assertOk();
        $this->assertSame([null, null, null, null], $this->power());

        // null = pemantau baterai tidak terpasang.
        $this->heartbeat(['power' => self::MAINS]);
        $this->heartbeat(['power' => null])->assertOk();
        $this->assertSame(['none', null, null, null], $this->power());

        // Kunci tidak dikirim = nilai terakhir dibiarkan.
        $this->heartbeat(['power' => self::BATTERY]);
        $this->heartbeat(['uptime_s' => 120])->assertOk();
        $this->assertSame(['battery', 3620, 18, false], $this->power());
    }

    public function test_malformed_power_is_ignored_and_values_are_clamped(): void
    {
        $this->heartbeat(['power' => self::MAINS]);

        // Bukan objek / source tidak dikenal = diabaikan, nilai lama tetap.
        foreach (['mains', 1, true, [], ['source' => 'solar', 'battery_mv' => 4000], ['battery_mv' => 3500], ['source' => null]] as $power) {
            $this->heartbeat(['power' => $power])->assertOk();
            $this->assertSame(['mains', 4105, 92, false], $this->power());
        }

        // Rentang dibatasi; angka yang bukan bilangan bulat = null; battery_low hanya true saat memakai baterai.
        $this->heartbeat(['power' => ['source' => 'battery', 'battery_mv' => 99999, 'battery_pct' => 140, 'battery_low' => 'ya']])->assertOk();
        $this->assertSame(['battery', 9999, 100, false], $this->power());

        $this->heartbeat(['power' => ['source' => 'battery', 'battery_mv' => -5, 'battery_pct' => -3, 'battery_low' => true]])->assertOk();
        $this->assertSame(['battery', 0, 0, true], $this->power());

        $this->heartbeat(['power' => ['source' => 'mains', 'battery_mv' => '4100', 'battery_pct' => 50.5, 'battery_low' => true]])->assertOk();
        $this->assertSame(['mains', null, null, false], $this->power());
    }

    // ----- Kejadian -----

    public function test_power_events_on_transitions_without_duplicates(): void
    {
        $this->heartbeat(['power' => self::MAINS]);
        $this->heartbeat(['power' => self::MAINS]);
        $this->assertSame([], $this->powerEvents());

        // Listrik padam, lalu heartbeat berikutnya masih padam: dicatat sekali.
        $this->heartbeat(['power' => self::BATTERY]);
        $this->heartbeat(['power' => self::BATTERY]);
        // Baterai mulai lemah: dicatat sekali.
        $this->heartbeat(['power' => self::LOW]);
        $this->heartbeat(['power' => self::LOW]);
        // Listrik kembali.
        $this->heartbeat(['power' => self::MAINS]);
        $this->heartbeat(['power' => self::MAINS]);

        $this->assertSame([
            ['power_lost', 'Listrik padam, alat memakai baterai (18%, 3,62 V)'],
            ['battery_low', 'Baterai lemah (5%, 3,38 V), nyalakan listrik'],
            ['power_restored', 'Listrik kembali, baterai dicas (92%, 4,11 V)'],
        ], $this->powerEvents());

        $event = $this->device()->events()->where('type', 'power_lost')->sole();
        $this->assertSame(['source' => 'battery', 'battery_mv' => 3620, 'battery_pct' => 18], $event->details);
        $this->assertSame(['Listrik padam', 'warning'], $event->label());
    }

    public function test_power_events_from_unknown_state_and_low_battery_at_once(): void
    {
        // Laporan pertama langsung baterai lemah: padam & lemah dicatat; dari belum diketahui ke listrik PLN tidak dicatat.
        $this->heartbeat(['power' => self::LOW]);
        $this->assertSame(['power_lost', 'battery_low'], array_column($this->powerEvents(), 0));

        // Tanpa UPS -> listrik PLN, atau kunci tidak dikirim: tidak ada kejadian.
        $this->heartbeat(['power' => null]);
        $this->heartbeat(['power' => self::MAINS]);
        $this->heartbeat([]);
        $this->assertCount(2, $this->powerEvents());

        // Padam lagi: dicatat lagi.
        $this->heartbeat(['power' => self::BATTERY]);
        $this->assertSame(['power_lost', 'battery_low', 'power_lost'], array_column($this->powerEvents(), 0));
    }

    // ----- Halaman Alat & dasbor -----

    public function test_admin_pages_show_power_status(): void
    {
        $admin = User::where('username', 'admin')->sole();
        $this->heartbeat(['uptime_s' => 60]);
        $this->actingAs($admin);

        $this->get(route('admin.devices.index'))->assertOk()->assertSeeInOrder(['Daya', 'belum dilaporkan (firmware 1.11.2+)']);

        $this->heartbeat(['power' => null]);
        $this->get(route('admin.devices.index'))->assertSeeInOrder(['Daya', 'Tanpa UPS']);

        $this->heartbeat(['power' => self::MAINS]);
        $this->get(route('admin.devices.index'))
            ->assertSeeInOrder(['Daya', 'Listrik PLN · baterai 92%', '4,11 V, baterai sedang dicas'])
            ->assertDontSee('1 peringatan');

        $this->heartbeat(['power' => self::BATTERY]);
        $this->get(route('admin.devices.index'))
            ->assertSeeInOrder(['1 peringatan', 'Listrik padam, alat memakai baterai (18%, 3,62 V)', 'Daya', '<span class="badge warning">Baterai 18% (3,62 V)</span>', 'listrik padam', 'Riwayat kejadian', 'Listrik padam'], false);
        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['1 alat perlu diperiksa:', 'Listrik padam, alat memakai baterai (18%, 3,62 V)']);

        $this->heartbeat(['power' => self::LOW]);
        $this->get(route('admin.devices.index'))
            ->assertSeeInOrder(['Baterai lemah (5%, 3,38 V): listrik padam, nyalakan listrik sebelum alat mati', 'Daya', '<span class="badge danger">Baterai lemah</span>', '5%, 3,38 V · listrik padam, nyalakan listrik'], false);

        // Listrik kembali: peringatan hilang.
        $this->heartbeat(['power' => self::MAINS]);
        $this->assertSame([], $this->device()->healthIssues());
        $this->get(route('admin.devices.index'))->assertSeeInOrder(['Riwayat kejadian', 'Listrik kembali', 'Listrik kembali, baterai dicas (92%, 4,11 V)']);
    }

    // ----- Sebelum migrasi -----

    public function test_api_and_devices_page_still_work_before_power_migration(): void
    {
        // Batalkan migrasi daya di dalam transaksi tes (DDL PostgreSQL ikut di-rollback setelah tes).
        (require database_path('migrations/2026_10_14_000002_add_power_columns_to_devices_table.php'))->down();
        Once::flush();
        $this->assertFalse(Device::powerReady());

        $this->heartbeat(['power' => self::LOW, 'uptime_s' => 60])->assertOk();
        $this->heartbeat(['power' => null, 'uptime_s' => 120])->assertOk();
        $this->assertSame(120, $this->device()->uptime_s);
        $this->assertSame([], $this->powerEvents());
        $this->assertSame([], $this->device()->healthIssues());

        $this->actingAs(User::where('username', 'admin')->sole())->get(route('admin.devices.index'))
            ->assertOk()
            ->assertSee('Menyala selama')
            ->assertDontSee('<dt>Daya</dt>', false);
    }
}
