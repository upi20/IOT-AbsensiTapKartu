<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * GET /api/absensi/announcements dan config.announcements_rev (doc/spesifikasi-api.md bagian 6 & 8).
 */
class AnnouncementApiTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'rahasia-kantor-123';

    private const DEVICE = 'ABS-1A2B3C';

    /** 2026-09-30 07:45:12 +07:00 */
    private const NOW_UNIX = 1790729112;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-30 07:45:12', 'Asia/Jakarta'));
        Setting::setValue(Setting::API_KEY, self::KEY);
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

    private function revision(): string
    {
        return $this->postJson('/api/absensi/heartbeat', ['device_id' => self::DEVICE], $this->headers())
            ->assertOk()
            ->json('config.announcements_rev');
    }

    #[TestWith([null])]
    #[TestWith(['salah'])]
    public function test_returns_401_when_api_key_is_missing_or_wrong(?string $key): void
    {
        Announcement::factory()->create();

        $this->getJson('/api/absensi/announcements', $this->headers($key))
            ->assertUnauthorized()
            ->assertExactJson(['ok' => false, 'message' => 'API key salah']);

        $this->assertDatabaseCount('devices', 0);
    }

    public function test_returns_400_when_device_id_is_missing(): void
    {
        $this->getJson('/api/absensi/announcements', $this->headers(device: null))
            ->assertBadRequest()
            ->assertExactJson(['ok' => false, 'message' => 'Header X-Device-ID wajib']);
    }

    public function test_returns_only_active_announcements_in_sort_order(): void
    {
        $holiday = Announcement::factory()->create([
            'title' => 'Libur Nasional', 'description' => 'Kamis, 2 Oktober 2026 kantor tutup.', 'icon' => 'libur', 'sort_order' => 2,
        ]);
        Announcement::factory()->inactive()->create(['title' => 'Arsip', 'sort_order' => 0]);
        $meeting = Announcement::factory()->create([
            'title' => 'Rapat Guru', 'description' => 'Hari ini pukul 13.00 di aula.', 'icon' => 'rapat', 'sort_order' => 1,
        ]);
        $reminder = Announcement::factory()->create([
            'title' => 'Jaga kebersihan', 'description' => null, 'icon' => 'info', 'sort_order' => 2,
        ]);

        $this->getJson('/api/absensi/announcements', $this->headers())
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'interval' => 3,
                'idle' => 30,
                'items' => [
                    ['id' => (string) $meeting->id, 'title' => 'Rapat Guru', 'description' => 'Hari ini pukul 13.00 di aula.', 'icon' => 'rapat'],
                    ['id' => (string) $holiday->id, 'title' => 'Libur Nasional', 'description' => 'Kamis, 2 Oktober 2026 kantor tutup.', 'icon' => 'libur'],
                    ['id' => (string) $reminder->id, 'title' => 'Jaga kebersihan', 'icon' => 'info'],
                ],
            ]);
    }

    public function test_returns_at_most_10_items(): void
    {
        $announcements = Announcement::factory()
            ->count(12)
            ->sequence(fn ($sequence) => ['sort_order' => $sequence->index])
            ->create();

        $response = $this->getJson('/api/absensi/announcements', $this->headers());

        $response->assertOk()->assertJsonCount(10, 'items');
        $this->assertSame(
            $announcements->take(10)->map(fn (Announcement $announcement) => (string) $announcement->id)->all(),
            array_column($response->json('items'), 'id'),
        );
    }

    public function test_returns_empty_items_and_defaults_when_nothing_is_configured(): void
    {
        Setting::forget(Setting::SCREENSAVER_INTERVAL);
        Setting::forget(Setting::SCREENSAVER_IDLE);

        $this->getJson('/api/absensi/announcements', $this->headers())
            ->assertOk()
            ->assertExactJson(['ok' => true, 'interval' => 3, 'idle' => 30, 'items' => []]);
    }

    public function test_returns_configured_interval_and_idle(): void
    {
        Setting::setValue(Setting::SCREENSAVER_INTERVAL, '5');
        Setting::setValue(Setting::SCREENSAVER_IDLE, '120');

        $this->getJson('/api/absensi/announcements', $this->headers())
            ->assertOk()
            ->assertJson(['interval' => 5, 'idle' => 120]);
    }

    public function test_falls_back_to_defaults_when_stored_settings_are_out_of_range(): void
    {
        Setting::setValue(Setting::SCREENSAVER_INTERVAL, '1');
        Setting::setValue(Setting::SCREENSAVER_IDLE, 'abc');

        $this->getJson('/api/absensi/announcements', $this->headers())
            ->assertOk()
            ->assertJson(['interval' => 3, 'idle' => 30]);
    }

    public function test_ping_and_heartbeat_send_the_same_announcements_rev(): void
    {
        Announcement::factory()->create();

        $this->getJson('/api/absensi/ping', $this->headers())
            ->assertOk()
            ->assertJsonPath('config.announcements_rev', '1-'.self::NOW_UNIX.'-3-30');
        $this->assertSame('1-'.self::NOW_UNIX.'-3-30', $this->revision());
    }

    public function test_announcements_rev_changes_after_create_update_delete_and_settings_change(): void
    {
        $this->assertSame('0-0-3-30', $this->revision());

        $first = Announcement::factory()->create();
        $this->assertSame('1-'.self::NOW_UNIX.'-3-30', $this->revision());

        $this->travel(10)->seconds();
        $second = Announcement::factory()->create();
        $this->assertSame('2-'.(self::NOW_UNIX + 10).'-3-30', $this->revision());

        $this->travel(10)->seconds();
        $first->update(['title' => 'Judul baru']);
        $this->assertSame('2-'.(self::NOW_UNIX + 20).'-3-30', $this->revision());

        $this->travel(10)->seconds();
        $second->delete();
        $this->assertSame('1-'.(self::NOW_UNIX + 20).'-3-30', $this->revision());

        Setting::setValue(Setting::SCREENSAVER_INTERVAL, '5');
        Setting::setValue(Setting::SCREENSAVER_IDLE, '120');
        $this->assertSame('1-'.(self::NOW_UNIX + 20).'-5-120', $this->revision());
    }
}
