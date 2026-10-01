<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\FirmwareRelease;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Number;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Update firmware jarak jauh (OTA): unggah .bin di panel, jadwalkan per alat / semua alat,
 * config.firmware_update di /ping & /heartbeat, dan unduhan GET /api/absensi/firmware/{id}.
 */
class FirmwareUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'rahasia-kantor-123';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-30 10:00:00', 'Asia/Jakarta'));
        Storage::fake('local');
        Setting::setValue(Setting::API_KEY, self::KEY);
        $this->admin = User::where('username', 'admin')->sole();
    }

    /** Isi .bin palsu: byte ajaib 0xE9 + teks versi di tengahnya. */
    private function binary(string $version = '1.5.0', string $magic = "\xE9"): string
    {
        return $magic."\x03\x02\x20".str_repeat("\x00", 64).'ABSENSI '.$version."\x00".str_repeat("\xFF", 128);
    }

    private function upload(string $version = '1.5.0', ?string $bytes = null, string $name = 'absensi.bin'): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('admin.firmware.store'), [
            'version' => $version,
            'firmware' => UploadedFile::fake()->createWithContent($name, $bytes ?? $this->binary($version)),
            'notes' => 'Perbaikan WiFi',
        ]);
    }

    private function headers(string $device = 'ABS-0001'): array
    {
        return ['X-API-Key' => self::KEY, 'X-Device-ID' => $device];
    }

    private function device(string $code = 'ABS-0001', array $attributes = []): Device
    {
        $device = Device::create(['code' => $code, 'name' => $code]);
        $device->forceFill($attributes)->save();

        return $device;
    }

    public function test_upload_stores_file_with_size_and_md5(): void
    {
        $this->upload()->assertRedirect(route('admin.firmware.index'))->assertSessionHasNoErrors();

        $release = FirmwareRelease::sole();
        $this->assertSame('1.5.0', $release->version);
        $this->assertSame('firmware/1.5.0.bin', $release->path);
        $this->assertSame(strlen($this->binary()), $release->size);
        $this->assertSame(md5($this->binary()), $release->md5);
        $this->assertSame('Perbaikan WiFi', $release->notes);
        Storage::disk('local')->assertExists('firmware/1.5.0.bin');

        $this->get(route('admin.firmware.index'))
            ->assertOk()
            ->assertSeeInOrder(['1.5.0', 'Perbaikan WiFi', Number::fileSize($release->size, 1), substr($release->md5, 0, 12), '30/09/2026 10:00', 'Terapkan ke semua alat'])
            ->assertSee('README bagian Update firmware jarak jauh');
    }

    public function test_upload_validation(): void
    {
        $this->upload('1.5')->assertSessionHasErrorsIn('firmware', ['version' => 'Versi harus berformat angka.angka.angka, mis. 1.5.0.']);
        $this->upload('v1.5.0')->assertSessionHasErrorsIn('firmware', ['version']);
        $this->upload('1.5.0', $this->binary('1.5.0', "\x00"))
            ->assertSessionHasErrorsIn('firmware', ['firmware' => 'File bukan firmware ESP32 (byte pertama harus 0xE9). Pilih file .bin aplikasi, bukan bootloader/partisi/merged.']);
        $this->upload('1.5.0', $this->binary('1.4.0'))
            ->assertSessionHasErrorsIn('firmware', ['firmware' => 'Versi 1.5.0 tidak ditemukan di dalam file. Pastikan VERSI_FIRMWARE di config.h sama.']);
        $this->upload('1.5.0', name: 'absensi.hex')->assertSessionHasErrorsIn('firmware', ['firmware' => 'File harus berekstensi .bin.']);

        $tooBig = $this->binary().str_repeat("\x00", FirmwareRelease::MAX_BYTES);
        $this->upload('1.5.0', $tooBig)->assertSessionHasErrorsIn('firmware', ['firmware']);

        $exact = substr($this->binary().str_repeat("\x00", FirmwareRelease::MAX_BYTES), 0, FirmwareRelease::MAX_BYTES);
        $this->upload('1.5.0', $exact)->assertSessionHasNoErrors();

        $this->upload('1.5.0')->assertSessionHasErrorsIn('firmware', ['version' => 'Versi 1.5.0 sudah pernah diunggah. Naikkan nomor versi firmware.']);
        $this->assertSame(1, FirmwareRelease::count());
    }

    public function test_config_firmware_update_is_sent_only_when_needed(): void
    {
        $this->upload();
        $release = FirmwareRelease::sole();
        $device = $this->device('ABS-0001', ['firmware' => '1.4.0']);
        $this->device('ABS-0002', ['firmware' => '1.4.0']);

        // Tanpa target: tidak ada kunci firmware_update.
        $this->getJson('/api/absensi/ping', $this->headers())->assertOk()->assertJsonMissingPath('config.firmware_update');

        $this->actingAs($this->admin)->put(route('admin.devices.update', $device), ['name' => 'Pintu', 'pin' => '', 'restart_at' => '03:00', 'firmware_release_id' => $release->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($release->id, $device->fresh()->firmware_release_id);

        // URL memakai skema & host yang dipakai alat (Base URL alat). Skema https yang dipaksa oleh request
        // sebelumnya (lewat host publik) dilepas dulu; di server sungguhan setiap request diproses tersendiri.
        URL::forceScheme(null);
        $this->getJson('http://192.168.1.10:8133/api/absensi/ping', $this->headers())
            ->assertOk()
            ->assertJsonPath('config.firmware_update', [
                'version' => '1.5.0',
                'url' => 'http://192.168.1.10:8133/api/absensi/firmware/'.$release->id,
                'size' => $release->size,
                'md5' => $release->md5,
            ]);
        $this->postJson('/api/absensi/heartbeat', ['firmware' => '1.4.0', 'raw' => ['uptime_s' => 10]], $this->headers())
            ->assertJsonPath('config.firmware_update.version', '1.5.0');
        $this->getJson('/api/absensi/ping', $this->headers('ABS-0002'))->assertJsonMissingPath('config.firmware_update');

        // Update gagal (alat kembali ke 1.4.0 dan melapor ota_failed): tidak ditawarkan lagi.
        $this->postJson('/api/absensi/heartbeat', ['firmware' => '1.4.0', 'raw' => ['uptime_s' => 5, 'ota_failed' => '1.5.0']], $this->headers())
            ->assertOk()
            ->assertJsonMissingPath('config.firmware_update');
        $this->assertSame(['Gagal, kembali ke 1.4.0', 'danger'], $device->fresh()->firmwareUpdateState());

        // Sudah terpasang: tidak ditawarkan lagi.
        $this->postJson('/api/absensi/heartbeat', ['firmware' => '1.5.0', 'raw' => ['uptime_s' => 3]], $this->headers())
            ->assertOk()
            ->assertJsonMissingPath('config.firmware_update');
        $this->assertSame(['Sudah terpasang', 'success'], $device->fresh()->firmwareUpdateState());

        // Target dikosongkan lewat halaman Alat.
        $this->put(route('admin.devices.update', $device), ['name' => 'Pintu', 'firmware_release_id' => ''])->assertSessionHasNoErrors();
        $this->assertNull($device->fresh()->firmware_release_id);
        $this->put(route('admin.devices.update', $device), ['name' => 'Pintu', 'firmware_release_id' => 999])
            ->assertSessionHasErrorsIn('device-'.$device->id, ['firmware_release_id']);
    }

    public function test_download_requires_api_key_and_target(): void
    {
        $this->upload();
        $release = FirmwareRelease::sole();
        $this->device('ABS-0001', ['firmware' => '1.4.0', 'firmware_release_id' => $release->id]);
        $this->device('ABS-0002', ['firmware' => '1.4.0']);
        $url = '/api/absensi/firmware/'.$release->id;

        $this->getJson($url, ['X-Device-ID' => 'ABS-0001'])->assertStatus(401);
        $this->getJson($url, $this->headers('ABS-0002'))
            ->assertNotFound()
            ->assertExactJson(['ok' => false, 'message' => 'Firmware tidak tersedia untuk alat ini']);
        $this->getJson('/api/absensi/firmware/999', $this->headers())->assertNotFound()->assertJson(['ok' => false]);

        $response = $this->get($url, $this->headers())->assertOk();
        $response->assertHeader('Content-Type', 'application/octet-stream');
        $response->assertHeader('Content-Length', (string) $release->size);
        $response->assertHeader('x-MD5', $release->md5);
        $this->assertSame($this->binary(), file_get_contents($response->baseResponse->getFile()->getPathname()));
    }

    public function test_apply_to_all_devices_and_delete_release(): void
    {
        $this->upload();
        $release = FirmwareRelease::sole();
        $first = $this->device('ABS-0001', ['firmware' => '1.4.0']);
        $second = $this->device('ABS-0002', ['firmware' => '1.5.0']);
        [$legacy] = Device::register('Alat lama');

        $this->post(route('admin.firmware.apply-all', $release))
            ->assertRedirect(route('admin.firmware.index'))
            ->assertSessionHas('success', 'Firmware 1.5.0 dijadwalkan untuk 2 alat. Alat mengunduhnya pada heartbeat berikutnya (maks. 1 menit).');
        $this->assertSame($release->id, $first->fresh()->firmware_release_id);
        $this->assertNull($legacy->fresh()->firmware_release_id); // API lama tidak bisa update jarak jauh

        $this->get(route('admin.firmware.index'))->assertSeeInOrder(['1.5.0', '2']);
        $this->get(route('admin.devices.index'))
            ->assertOk()
            ->assertSeeInOrder(['ABS-0001', 'Menunggu alat mengunduh', 'Update firmware ke', '<option value="'.$release->id.'" selected>1.5.0</option>'], false)
            ->assertSeeInOrder(['ABS-0002', 'Sudah terpasang'], false);

        $this->delete(route('admin.firmware.destroy', $release))->assertRedirect(route('admin.firmware.index'));
        $this->assertDatabaseCount('firmware_releases', 0);
        Storage::disk('local')->assertMissing('firmware/1.5.0.bin');
        $this->assertNull($first->fresh()->firmware_release_id);
        $this->assertNull($second->fresh()->firmware_release_id);
    }
}
