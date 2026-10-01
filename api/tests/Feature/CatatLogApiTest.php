<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Setting;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Setiap request API alat tercatat utuh di channel `api_log`, terpisah dari laravel.log,
 * tanpa API key dan PIN, dan pencatatannya tidak boleh mengganggu request.
 */
class CatatLogApiTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'kunci-rahasia-log-7731';

    private const DEVICE = 'MAX-LOG-01';

    private string $folderLog;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue(Setting::API_KEY, self::KEY);

        $this->folderLog = storage_path('framework/testing/log-api-'.uniqid());
        File::ensureDirectoryExists($this->folderLog);

        config([
            'logging.channels.api_log.path' => $this->folderLog.'/api.log',
            'logging.channels.single.path' => $this->folderLog.'/laravel.log',
            'logging.default' => 'single',
        ]);
        app('log')->forgetChannel('api_log');
        app('log')->forgetChannel('single');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folderLog);

        parent::tearDown();
    }

    private function isiLogApi(): string
    {
        return collect(File::glob($this->folderLog.'/api*.log'))
            ->map(fn (string $berkas) => File::get($berkas))
            ->implode("\n");
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $key = self::KEY): array
    {
        return ['X-API-Key' => $key, 'X-Device-ID' => self::DEVICE, 'X-Spec-Version' => '1', 'Accept' => 'application/json'];
    }

    public function test_seluruh_parameter_tap_tercatat_di_channel_sendiri(): void
    {
        $this->postJson('/api/absensi/tap', [
            'device_id' => self::DEVICE,
            'tap_id' => '79438C-0000ABCD',
            'rfid' => '0218893066',
            'tapped_at' => '2026-10-01T07:45:12+07:00',
            'queued' => false,
            'raw' => ['uid_hex' => '0A0B0C0D', 'wifi_ssid' => 'Kantor-LOG'],
        ], $this->headers())->assertOk()->assertJson(['status' => 'unknown']);

        $log = $this->isiLogApi();

        $this->assertStringContainsString('api_request', $log);
        $this->assertStringContainsString('"url":"api/absensi/tap"', $log);
        $this->assertStringContainsString('"id":"'.self::DEVICE.'"', $log);
        $this->assertStringContainsString('"spec_version":"1"', $log);

        // Parameter tercatat apa adanya, termasuk isi "raw".
        foreach (['"tap_id":"79438C-0000ABCD"', '"rfid":"0218893066"', '"queued":false', '"wifi_ssid":"Kantor-LOG"'] as $isi) {
            $this->assertStringContainsString($isi, $log);
        }

        // Balasan server ikut tercatat, dengan kunci berbahasa Inggris.
        foreach (['"method":"POST"', '"request":{', '"response":{', '"body":{', '"error":null', '"duration_ms":'] as $kunci) {
            $this->assertStringContainsString($kunci, $log);
        }
        $this->assertStringContainsString('"status":200', $log);
        $this->assertStringContainsString('"status":"unknown"', $log);

        // API key hanya dicatat ada-tidaknya.
        $this->assertStringContainsString('"api_key":"[present, hidden]"', $log);
        $this->assertStringNotContainsString(self::KEY, $log);

        // Tidak ada yang tercecer ke laravel.log.
        $laravelLog = File::exists($this->folderLog.'/laravel.log') ? File::get($this->folderLog.'/laravel.log') : '';
        $this->assertStringNotContainsString('api_request', $laravelLog);
    }

    public function test_request_dengan_api_key_salah_tetap_tercatat(): void
    {
        $this->getJson('/api/absensi/ping', $this->headers('kunci-tebakan-9911'))->assertUnauthorized();

        $log = $this->isiLogApi();

        $this->assertStringContainsString('"url":"api/absensi/ping"', $log);
        $this->assertStringContainsString('"status":401', $log);
        $this->assertStringContainsString('"registered":null', $log);
        $this->assertStringNotContainsString('kunci-tebakan-9911', $log);
    }

    public function test_request_yang_kena_batas_throttle_tetap_tercatat(): void
    {
        RateLimiter::for('absensi-api', fn (Request $request) => Limit::perMinute(1)->by('uji-log'));

        $this->getJson('/api/absensi/ping', $this->headers())->assertOk();
        $this->getJson('/api/absensi/ping', $this->headers())->assertStatus(429);

        $this->assertStringContainsString('"status":429', $this->isiLogApi());
    }

    public function test_pin_alat_di_balasan_disembunyikan(): void
    {
        Device::create(['code' => self::DEVICE, 'name' => 'Alat Lobi', 'pin' => '9137']);

        $this->getJson('/api/absensi/ping', $this->headers())->assertOk()->assertJsonPath('config.pin', '9137');

        $log = $this->isiLogApi();

        $this->assertStringContainsString('"pin":"[hidden]"', $log);
        $this->assertStringNotContainsString('9137', $log);
        $this->assertStringContainsString('"name":"Alat Lobi"', $log);
    }

    public function test_body_yang_bukan_json_dicatat_mentah(): void
    {
        $this->call('POST', '/api/absensi/tap', [], [], [], $this->transformHeadersToServerVars($this->headers()), 'rfid=0218893066')
            ->assertStatus(400);

        $log = $this->isiLogApi();

        $this->assertStringContainsString('"raw":"rfid=0218893066"', $log);
        $this->assertStringContainsString('"status":400', $log);
    }

    public function test_api_lama_juga_tercatat_tanpa_kunci_alat(): void
    {
        Device::factory()->withKey('kunci-alat-lama-5520')->create();

        $this->getJson('/api/v1/ping', ['X-Device-Key' => 'kunci-alat-lama-5520'])->assertOk();

        $log = $this->isiLogApi();

        $this->assertStringContainsString('"url":"api/v1/ping"', $log);
        $this->assertStringNotContainsString('kunci-alat-lama-5520', $log);
    }

    public function test_log_yang_gagal_ditulis_tidak_menggagalkan_request(): void
    {
        // Channel sengaja dirusak: pembuatan logger-nya akan melempar galat.
        config(['logging.channels.api_log' => ['driver' => 'tidak-ada-driver-ini']]);
        app('log')->forgetChannel('api_log');

        $this->postJson('/api/absensi/heartbeat', ['device_id' => self::DEVICE, 'firmware' => '1.3.0'], $this->headers())
            ->assertOk();

        $this->assertSame('1.3.0', Device::where('code', self::DEVICE)->value('firmware'));
    }
}
