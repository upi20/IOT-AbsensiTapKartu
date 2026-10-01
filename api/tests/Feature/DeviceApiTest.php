<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\AttendanceType;
use App\Models\Attendance;
use App\Models\Device;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DeviceApiTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-device-key-1234567890';

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-30 07:45:00', 'Asia/Jakarta'));
        $this->device = Device::factory()->withKey(self::KEY)->create(['name' => 'ESP32 Test']);
    }

    private function headers(?string $key = self::KEY): array
    {
        return $key === null ? ['Accept' => 'application/json'] : ['X-Device-Key' => $key, 'Accept' => 'application/json'];
    }

    private function tap(string $uid)
    {
        return $this->postJson('/api/v1/tap', ['uid' => $uid], $this->headers());
    }

    public function test_missing_key_is_unauthorized(): void
    {
        $this->getJson('/api/v1/ping', $this->headers(null))
            ->assertStatus(401)
            ->assertExactJson(['ok' => false, 'status' => 'unauthorized', 'message' => 'Perangkat tidak dikenal']);
    }

    public function test_invalid_key_is_unauthorized(): void
    {
        $this->postJson('/api/v1/tap', ['uid' => 'A1B2C3D4'], $this->headers('wrong'))
            ->assertStatus(401)
            ->assertJson(['ok' => false, 'status' => 'unauthorized']);

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_ping_returns_device_and_time_and_updates_last_seen(): void
    {
        $this->assertNull($this->device->last_seen_at);

        $this->getJson('/api/v1/ping', $this->headers())
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'status' => 'pong',
                'device' => 'ESP32 Test',
                'time' => '2026-09-30T07:45:00+07:00',
                'unix' => Carbon::parse('2026-09-30 07:45:00', 'Asia/Jakarta')->getTimestamp(),
            ]);

        $this->assertNotNull($this->device->fresh()->last_seen_at);
    }

    public function test_unknown_card_is_recorded(): void
    {
        $this->tap('de:ad:be:ef')
            ->assertStatus(404)
            ->assertExactJson([
                'ok' => false,
                'status' => 'unknown_card',
                'message' => 'Kartu tidak terdaftar',
                'uid' => 'DEADBEEF',
            ]);

        $this->assertDatabaseHas('attendances', [
            'member_id' => null,
            'device_id' => $this->device->id,
            'card_uid' => 'DEADBEEF',
            'type' => null,
            'status' => 'unknown_card',
        ]);
    }

    public function test_first_tap_is_check_in(): void
    {
        $member = Member::factory()->create(['name' => 'Budi Santoso', 'card_uid' => 'A1B2C3D4']);

        $this->tap('A1B2C3D4')
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'status' => 'check_in',
                'name' => 'Budi Santoso',
                'message' => 'Selamat datang',
                'time' => '07:45',
            ]);

        $this->assertDatabaseHas('attendances', [
            'member_id' => $member->id,
            'type' => 'check_in',
            'status' => 'success',
        ]);
    }

    public function test_next_taps_are_check_out_and_last_one_counts(): void
    {
        $member = Member::factory()->create(['name' => 'Budi Santoso', 'card_uid' => 'A1B2C3D4']);

        $this->tap('A1B2C3D4')->assertJson(['status' => 'check_in']);

        $this->travelTo(Carbon::parse('2026-09-30 15:30:00', 'Asia/Jakarta'));
        $this->tap('A1B2C3D4')
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'status' => 'check_out',
                'name' => 'Budi Santoso',
                'message' => 'Sampai jumpa',
                'time' => '15:30',
            ]);

        $this->travelTo(Carbon::parse('2026-09-30 16:10:00', 'Asia/Jakarta'));
        $this->tap('A1B2C3D4')->assertJson(['status' => 'check_out', 'time' => '16:10']);

        $types = Attendance::where('member_id', $member->id)->orderBy('id')->pluck('type')->all();
        $this->assertSame([AttendanceType::CheckIn, AttendanceType::CheckOut, AttendanceType::CheckOut], $types);
    }

    public function test_new_day_starts_with_check_in_again(): void
    {
        Member::factory()->create(['card_uid' => 'A1B2C3D4']);

        $this->travelTo(Carbon::parse('2026-09-30 23:58:00', 'Asia/Jakarta'));
        $this->tap('A1B2C3D4')->assertJson(['status' => 'check_in']);

        $this->travelTo(Carbon::parse('2026-10-01 07:00:00', 'Asia/Jakarta'));
        $this->tap('A1B2C3D4')->assertJson(['status' => 'check_in']);
    }

    public function test_duplicate_within_window(): void
    {
        config(['absensi.duplicate_window_seconds' => 60]);
        $member = Member::factory()->create(['name' => 'Budi Santoso', 'card_uid' => 'A1B2C3D4']);

        $this->tap('A1B2C3D4')->assertJson(['status' => 'check_in']);

        $this->travel(30)->seconds();
        $this->tap('A1B2C3D4')
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'status' => 'duplicate',
                'name' => 'Budi Santoso',
                'message' => 'Sudah tercatat',
                'time' => '07:45',
            ]);

        // Window dihitung dari tap SUKSES terakhir, bukan dari tap duplikat.
        $this->travel(31)->seconds();
        $this->tap('A1B2C3D4')->assertJson(['status' => 'check_out']);

        $this->assertSame(2, Attendance::where('member_id', $member->id)->where('status', AttendanceStatus::Success)->count());
        $this->assertSame(1, Attendance::where('member_id', $member->id)->where('status', AttendanceStatus::Duplicate)->count());
    }

    public function test_duplicate_window_is_configurable(): void
    {
        config(['absensi.duplicate_window_seconds' => 5]);
        Member::factory()->create(['card_uid' => 'A1B2C3D4']);

        $this->tap('A1B2C3D4')->assertJson(['status' => 'check_in']);
        $this->travel(10)->seconds();
        $this->tap('A1B2C3D4')->assertJson(['status' => 'check_out']);
    }

    public function test_inactive_member(): void
    {
        $member = Member::factory()->inactive()->create(['name' => 'Siti Aminah', 'card_uid' => '11223344']);

        $this->tap('11223344')
            ->assertStatus(403)
            ->assertExactJson([
                'ok' => false,
                'status' => 'inactive',
                'name' => 'Siti Aminah',
                'message' => 'Kartu nonaktif',
            ]);

        $this->assertDatabaseHas('attendances', [
            'member_id' => $member->id,
            'type' => null,
            'status' => 'inactive',
        ]);
    }

    public function test_uid_is_normalized(): void
    {
        Member::factory()->create(['card_uid' => 'a1:b2:c3:d4']); // mutator juga menormalisasi
        $this->assertDatabaseHas('members', ['card_uid' => 'A1B2C3D4']);

        $this->tap(' a1-b2 c3:d4 ')->assertOk()->assertJson(['status' => 'check_in']);

        $this->assertDatabaseHas('attendances', ['card_uid' => 'A1B2C3D4', 'status' => 'success']);
    }

    public function test_invalid_uid_returns_422(): void
    {
        foreach (['', 'XYZ', '12', 'GGGGGGGG'] as $uid) {
            $this->tap($uid)
                ->assertStatus(422)
                ->assertExactJson(['ok' => false, 'status' => 'invalid', 'message' => 'UID tidak valid']);
        }

        $this->postJson('/api/v1/tap', [], $this->headers())
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'status' => 'invalid']);

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_today_lists_successful_taps_only(): void
    {
        Member::factory()->create(['name' => 'Budi Santoso', 'card_uid' => 'A1B2C3D4']);

        $this->tap('A1B2C3D4');          // check_in 07:45
        $this->tap('A1B2C3D4');          // duplicate
        $this->tap('DEADBEEF');          // unknown
        $this->travelTo(Carbon::parse('2026-09-30 15:00:00', 'Asia/Jakarta'));
        $this->tap('A1B2C3D4');          // check_out 15:00

        $this->getJson('/api/v1/attendances/today', $this->headers())
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'status' => 'ok',
                'date' => '2026-09-30',
                'count' => 2,
                'items' => [
                    ['name' => 'Budi Santoso', 'type' => 'check_out', 'time' => '15:00'],
                    ['name' => 'Budi Santoso', 'type' => 'check_in', 'time' => '07:45'],
                ],
            ]);
    }

    public function test_unknown_endpoint_returns_flat_json(): void
    {
        $this->getJson('/api/v1/nope', $this->headers())
            ->assertStatus(404)
            ->assertExactJson(['ok' => false, 'status' => 'not_found', 'message' => 'Endpoint tidak ada']);
    }

    public function test_all_messages_fit_lcd(): void
    {
        foreach (['Perangkat tidak dikenal', 'Kartu tidak terdaftar', 'Kartu nonaktif', 'Sudah tercatat', 'Selamat datang', 'Sampai jumpa', 'UID tidak valid'] as $msg) {
            $this->assertLessThanOrEqual(32, mb_strlen($msg));
        }
    }
}
