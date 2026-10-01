<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Device;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ArtisanCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_create_stores_only_hash(): void
    {
        $this->artisan('absensi:device-create', ['name' => 'ESP32 Lab', '--location' => 'Lab'])->assertSuccessful();

        $device = Device::where('name', 'ESP32 Lab')->firstOrFail();
        $this->assertSame(64, strlen($device->api_key));
        $this->assertSame('Lab', $device->location);
    }

    public function test_member_create_normalizes_and_rejects_duplicates(): void
    {
        $this->artisan('absensi:member-create', ['name' => 'Budi', 'card_uid' => 'a1:b2:c3:d4', '--identifier' => '1001'])
            ->assertSuccessful();
        $this->assertDatabaseHas('members', ['card_uid' => 'A1B2C3D4', 'identifier' => '1001']);

        $this->artisan('absensi:member-create', ['name' => 'Lain', 'card_uid' => 'A1B2C3D4'])->assertFailed();
    }

    public function test_listing_and_report_commands_run(): void
    {
        $device = Device::factory()->create();
        $member = Member::factory()->create(['name' => 'Budi', 'card_uid' => 'A1B2C3D4']);
        Attendance::create(['member_id' => $member->id, 'device_id' => $device->id, 'card_uid' => 'A1B2C3D4', 'type' => 'check_in', 'status' => 'success', 'tapped_at' => now()]);
        Attendance::create(['member_id' => null, 'device_id' => $device->id, 'card_uid' => 'DEADBEEF', 'type' => null, 'status' => 'unknown_card', 'tapped_at' => now()]);

        $this->artisan('absensi:member-list')->expectsOutputToContain('A1B2C3D4')->assertSuccessful();
        $this->artisan('absensi:unknown-cards')->expectsOutputToContain('DEADBEEF')->assertSuccessful();
        $this->artisan('absensi:report')->expectsOutputToContain('Budi')->assertSuccessful();
        $this->artisan('absensi:member-deactivate', ['card_uid' => 'a1b2c3d4'])->assertSuccessful();
        $this->assertFalse($member->fresh()->is_active);
    }

    public function test_initial_admin_exists_without_password(): void
    {
        $admin = User::where('username', 'admin')->sole();

        $this->assertSame('admin@example.com', $admin->email);
        $this->assertFalse($admin->hasPassword());
    }

    public function test_admin_password_sets_hashed_password(): void
    {
        $this->artisan('absensi:admin-password', ['username' => 'admin'])
            ->expectsQuestion('Password baru untuk admin (min. 8 karakter)', 'rahasia-123')
            ->expectsQuestion('Ulangi password baru', 'rahasia-123')
            ->assertSuccessful();

        $admin = User::where('username', 'admin')->sole();
        $this->assertTrue(Hash::check('rahasia-123', $admin->password));

        $this->post(route('login.attempt'), ['login' => 'admin', 'password' => 'rahasia-123'])
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_admin_password_rejects_short_or_mismatched_password(): void
    {
        $this->artisan('absensi:admin-password', ['username' => 'admin'])
            ->expectsQuestion('Password baru untuk admin (min. 8 karakter)', 'pendek')
            ->expectsQuestion('Ulangi password baru', 'pendek')
            ->assertFailed();

        $this->artisan('absensi:admin-password', ['username' => 'admin'])
            ->expectsQuestion('Password baru untuk admin (min. 8 karakter)', 'rahasia-123')
            ->expectsQuestion('Ulangi password baru', 'rahasia-124')
            ->assertFailed();

        $this->artisan('absensi:admin-password', ['username' => 'tidak-ada'])->assertFailed();

        $this->assertFalse(User::where('username', 'admin')->sole()->hasPassword());
    }

    public function test_admin_create_makes_account_without_password(): void
    {
        $this->artisan('absensi:admin-create', ['username' => 'Operator', 'email' => 'op@contoh.test'])->assertSuccessful();

        $this->assertFalse(User::where('username', 'operator')->sole()->hasPassword());
        $this->artisan('absensi:admin-create', ['username' => 'operator', 'email' => 'lain@contoh.test'])->assertFailed();
    }
}
