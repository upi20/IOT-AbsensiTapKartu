<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceEvent;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pemantauan kesehatan alat dari heartbeat (raw.uptime_s, reset_reason, rfid_ok, queue, RAM, error,
 * ota_failed, crash), riwayat kejadian, dan peringatan di panel admin.
 */
class DeviceHealthTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'rahasia-kantor-123';

    private const DEVICE = 'ABS-1A2B3C';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-30 10:00:00', 'Asia/Jakarta'));
        Setting::setValue(Setting::API_KEY, self::KEY);
    }

    private function heartbeat(array $raw = [], string $firmware = '1.5.0')
    {
        return $this->postJson('/api/absensi/heartbeat', [
            'device_id' => self::DEVICE,
            'firmware' => $firmware,
            'time' => now()->toIso8601String(),
            'raw' => $raw + ['wifi_ssid' => 'Kantor-2.4G', 'rssi' => -52, 'ip' => '192.168.1.23'],
        ], ['X-API-Key' => self::KEY, 'X-Device-ID' => self::DEVICE])->assertOk();
    }

    private function device(): Device
    {
        return Device::where('code', self::DEVICE)->sole();
    }

    private function eventTypes(): array
    {
        return $this->device()->events()->orderBy('id')->pluck('type')->all();
    }

    public function test_heartbeat_stores_health_fields(): void
    {
        $this->heartbeat([
            'uptime_s' => 3660, 'free_heap' => 123456, 'min_free_heap' => 98765, 'reset_reason' => 'poweron',
            'rfid_ok' => true, 'queue' => 3, 'error' => 'E31', 'ota_failed' => '1.6.0',
        ]);

        $device = $this->device();
        $this->assertTrue($device->heartbeat_at->equalTo(now()));
        $this->assertSame(3660, $device->uptime_s);
        $this->assertSame(123456, $device->free_heap);
        $this->assertSame(98765, $device->min_free_heap);
        $this->assertSame('poweron', $device->reset_reason);
        $this->assertTrue($device->rfid_ok);
        $this->assertSame(3, $device->queue);
        $this->assertSame('E31', $device->error_code);
        $this->assertSame('1.6.0', $device->ota_failed);
        $this->assertSame('1 jam 1 menit', $device->uptimeForHumans());

        // Kunci yang tidak dikirim / tipenya salah tidak menimpa nilai lama; error & ota_failed hilang = tidak ada.
        $this->heartbeat(['uptime_s' => '3720', 'rfid_ok' => 'ya', 'queue' => null]);

        $device->refresh();
        $this->assertSame(3660, $device->uptime_s);
        $this->assertTrue($device->rfid_ok);
        $this->assertSame(3, $device->queue);
        $this->assertSame(123456, $device->free_heap);
        $this->assertNull($device->error_code);
        $this->assertNull($device->ota_failed);
    }

    public function test_tap_and_ping_do_not_touch_health_fields(): void
    {
        $this->heartbeat(['uptime_s' => 100, 'queue' => 2, 'error' => 'E30']);

        $headers = ['X-API-Key' => self::KEY, 'X-Device-ID' => self::DEVICE];
        $this->getJson('/api/absensi/ping', $headers)->assertOk();
        $this->postJson('/api/absensi/tap', ['rfid' => '0218893066', 'raw' => ['uptime_s' => 5, 'queue' => 0]], $headers)->assertOk();

        $device = $this->device();
        $this->assertSame(100, $device->uptime_s);
        $this->assertSame(2, $device->queue);
        $this->assertSame('E30', $device->error_code);
        $this->assertSame([], $this->eventTypes());
    }

    public function test_boot_event_when_uptime_goes_down(): void
    {
        $this->heartbeat(['uptime_s' => 500, 'reset_reason' => 'poweron']);
        $this->heartbeat(['uptime_s' => 560, 'reset_reason' => 'poweron']);
        $this->assertSame([], $this->eventTypes()); // heartbeat pertama & uptime naik: bukan restart

        $this->heartbeat(['uptime_s' => 12, 'reset_reason' => 'watchdog']);

        $event = $this->device()->events()->sole();
        $this->assertSame('boot', $event->type);
        $this->assertSame('Menyala ulang: watchdog (program macet)', $event->message);
        $this->assertEquals(['reset_reason' => 'watchdog', 'uptime_s' => 12, 'previous_uptime_s' => 560], $event->details); // jsonb: urutan kunci bebas
        $this->assertSame('watchdog', $this->device()->reset_reason);
    }

    public function test_boot_event_when_restarted_again_soon_after_previous_boot(): void
    {
        // Kejadian nyata: firmware baru menyala (uptime 6), lalu 25 detik kemudian restart lagi (uptime 8).
        // Uptime tetap naik, tapi lebih kecil dari jarak sejak heartbeat sebelumnya.
        $this->heartbeat(['uptime_s' => 6, 'reset_reason' => 'software']);
        $this->travel(25)->seconds();
        $this->heartbeat(['uptime_s' => 8, 'reset_reason' => 'poweron']);

        $this->assertSame(['boot'], $this->eventTypes());
        $this->assertSame('Menyala ulang: baru dinyalakan / tombol EN', $this->device()->events()->sole()->message);
    }

    public function test_boot_event_when_previous_uptime_unknown_but_heartbeat_seen_before(): void
    {
        $this->heartbeat([]); // firmware lama: tanpa uptime_s
        $this->heartbeat(['uptime_s' => 30, 'reset_reason' => 'brownout']);

        $this->assertSame(['boot'], $this->eventTypes());
        $this->assertSame('Menyala ulang: listrik turun (brownout)', $this->device()->events()->sole()->message);
    }

    public function test_crash_event_is_deduplicated_for_ten_minutes(): void
    {
        // Format dari alat sungguhan: backtrace = daftar PC dipisah spasi, elf = build ID firmware.
        $crash = ['task' => 'loopTask', 'pc' => '0x400d4634', 'backtrace' => '0x400d4634 0x400880ed', 'elf' => 'a1b2c3d4e5f60718'];

        $this->heartbeat(['uptime_s' => 20, 'crash' => $crash]);
        $this->travel(1)->minutes();
        $this->heartbeat(['uptime_s' => 80, 'crash' => $crash]); // dikirim ulang

        $event = $this->device()->events()->sole();
        $this->assertSame('crash', $event->type);
        $this->assertSame('Program error (crash) di task loopTask, PC 0x400d4634', $event->message);
        $this->assertEquals($crash, $event->details);

        // Crash lain langsung dicatat; crash yang sama dicatat lagi setelah 10 menit.
        $this->heartbeat(['uptime_s' => 140, 'crash' => ['pc' => '0x400d9999', 'backtrace' => '0x400d9999 0x400880ed']]);
        $this->travel(11)->minutes();
        $this->heartbeat(['uptime_s' => 800, 'crash' => $crash]);

        $this->assertSame(['crash', 'crash', 'crash'], $this->eventTypes());
    }

    public function test_firmware_change_and_ota_failed_events(): void
    {
        $this->heartbeat(['uptime_s' => 100], '1.4.0');
        $this->heartbeat(['uptime_s' => 5, 'reset_reason' => 'software'], '1.5.0');

        $this->assertSame(['firmware', 'boot'], $this->eventTypes());
        $this->assertSame('Firmware 1.4.0 -> 1.5.0', $this->device()->events()->where('type', 'firmware')->sole()->message);

        $this->heartbeat(['uptime_s' => 65, 'ota_failed' => '1.6.0']);
        $this->heartbeat(['uptime_s' => 125, 'ota_failed' => '1.6.0']); // sama: tidak dicatat lagi

        $event = $this->device()->events()->where('type', 'ota_failed')->sole();
        $this->assertSame('Update firmware ke 1.6.0 gagal, alat kembali ke 1.5.0', $event->message);
    }

    public function test_only_latest_events_are_kept(): void
    {
        $this->heartbeat(['uptime_s' => 10]);
        $device = $this->device();

        for ($i = 0; $i < DeviceEvent::KEEP + 5; $i++) {
            DeviceEvent::record($device, 'boot', "Kejadian {$i}");
        }

        $this->assertSame(DeviceEvent::KEEP, $device->events()->count());
        $this->assertSame('Kejadian 5', $device->events()->orderBy('id')->first()->message);
    }

    public function test_health_issues(): void
    {
        $this->heartbeat(['uptime_s' => 100, 'rfid_ok' => true, 'queue' => 0, 'free_heap' => 150000, 'min_free_heap' => 90000]);
        $this->assertSame([], $this->device()->healthIssues());

        foreach (['brownout', 'watchdog', 'brownout', 'software'] as $i => $reason) {
            $this->heartbeat(['uptime_s' => 5 + $i, 'reset_reason' => $reason]);
            $this->heartbeat(['uptime_s' => 50 + $i]);
        }

        $this->heartbeat([
            'uptime_s' => 70, 'rssi' => -85, 'rfid_ok' => false, 'queue' => 35, 'min_free_heap' => 15000,
            'error' => 'E30', 'ota_failed' => '1.6.0',
        ]);

        $issues = $this->device()->healthIssues();
        $this->assertSame([
            'Pembaca RFID tidak terdeteksi (E30)',
            'Sinyal WiFi lemah (-85 dBm)',
            'Antrean offline menumpuk: 35 tap belum terkirim',
            'Sering restart tidak normal: 3x dalam 24 jam (brownout 2, watchdog 1). Brownout: cek adaptor/kabel daya',
            'Update firmware ke 1.6.0 gagal, alat kembali ke 1.5.0',
            'RAM hampir habis (terendah 14.6 KB)',
        ], $issues);

        // Error lain tampil dengan artinya; restart lebih dari 24 jam lalu tidak dihitung; offline > 10 menit.
        $this->heartbeat(['uptime_s' => 80, 'rssi' => -60, 'rfid_ok' => true, 'queue' => 0, 'min_free_heap' => 90000, 'error' => 'E21']);
        $this->travel(25)->hours();

        $this->assertSame([
            'Offline sejak 30 Sep 10:00 (1 hari yang lalu)',
            'Error E21: API key salah (401)',
        ], $this->device()->healthIssues());
    }

    public function test_device_api_and_dashboard_still_work_before_monitoring_migrations(): void
    {
        // Batalkan 3 migrasi baru di dalam transaksi tes (DDL PostgreSQL ikut di-rollback setelah tes).
        foreach (['000014_create_device_events_table', '000013_add_health_columns_to_devices_table', '000012_create_firmware_releases_table'] as $name) {
            (require database_path("migrations/2026_10_01_{$name}.php"))->down();
        }
        $this->assertFalse(Device::monitoringReady());

        $headers = ['X-API-Key' => self::KEY, 'X-Device-ID' => self::DEVICE];
        $this->getJson('/api/absensi/ping', $headers)->assertOk()->assertJsonMissingPath('config.firmware_update');
        $this->heartbeat(['uptime_s' => 100, 'crash' => ['pc' => '0x400d4634']], '1.4.0');
        $this->heartbeat(['uptime_s' => 5], '1.5.0')->assertJsonMissingPath('config.firmware_update');
        $this->postJson('/api/absensi/tap', ['rfid' => '0218893066'], $headers)->assertOk()->assertJson(['status' => 'unknown']);
        $this->assertSame('1.5.0', $this->device()->firmware);

        $this->actingAs(User::where('username', 'admin')->sole())->get(route('admin.dashboard'))->assertOk()->assertDontSee('perlu diperiksa');
    }

    public function test_admin_pages_show_health_and_events(): void
    {
        $admin = User::where('username', 'admin')->sole();

        $this->heartbeat(['uptime_s' => 3700, 'reset_reason' => 'panic', 'rfid_ok' => false, 'queue' => 4, 'free_heap' => 123456, 'min_free_heap' => 98765, 'error' => 'E30']);
        $this->heartbeat(['uptime_s' => 3, 'reset_reason' => 'panic', 'rfid_ok' => false, 'error' => 'E30',
            'crash' => ['task' => 'loopTask', 'pc' => '0x400d4634', 'backtrace' => '0x400d4634 0x400880ed', 'elf' => 'a1b2c3d4']]);
        $device = $this->device();
        $device->update(['name' => 'Pintu Utama']);

        $this->actingAs($admin)->get(route('admin.devices.index'))
            ->assertOk()
            ->assertSeeInOrder(['Pintu Utama', 'Online', 'Pembaca RFID tidak terdeteksi (E30)', '3 detik', 'program error', 'Tidak terdeteksi', '4 tap', '120.6 KB', 'E30'])
            ->assertSeeInOrder(['Riwayat kejadian', 'Crash', 'Program error (crash) di task loopTask', 'Backtrace: 0x400d4634 0x400880ed', 'ELF: a1b2c3d4', 'Menyala ulang', 'Menyala ulang: program error']);

        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['1 alat perlu diperiksa:', route('admin.devices.index').'#alat-'.$device->id, 'Pintu Utama', 'Pembaca RFID tidak terdeteksi (E30)'], false);

        // Alat sehat: tidak ada peringatan di dasbor.
        $this->heartbeat(['uptime_s' => 63, 'rfid_ok' => true]);
        $this->get(route('admin.dashboard.live'))->assertOk()->assertDontSee('perlu diperiksa');
    }
}
