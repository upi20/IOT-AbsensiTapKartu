<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Device;
use App\Models\Member;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;
use Tests\Unit\MemberPhotoTest;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-30 10:00:00', 'Asia/Jakarta'));

        // Akun "admin" dibuat oleh migrasi tanpa password; di test diberi password.
        $this->admin = User::where('username', 'admin')->sole();
        $this->admin->forceFill(['password' => 'rahasia-123'])->save();
    }

    private function device(string $code = 'ABS-1A2B3C'): Device
    {
        return Device::create(['code' => $code, 'name' => $code]);
    }

    private function tapRow(?Member $member, string $card, string $status, ?string $type, string $time, ?Device $device = null): Attendance
    {
        return Attendance::create([
            'member_id' => $member?->id,
            'device_id' => ($device ?? $this->device('ABS-'.uniqid()))->id,
            'card_uid' => $card,
            'status' => $status,
            'type' => $type,
            'tapped_at' => Carbon::parse($time, 'Asia/Jakarta'),
        ]);
    }

    // ----- Login -----

    public function test_root_and_panel_redirect_guests_to_login(): void
    {
        $this->get('/')->assertRedirect('/admin');
        $this->get('/admin')->assertRedirect(route('login'));
        $this->get('/admin/anggota')->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk()->assertSee('Username atau email');
    }

    public function test_login_with_username_or_email_and_logout(): void
    {
        $this->post(route('login.attempt'), ['login' => 'admin', 'password' => 'rahasia-123'])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($this->admin);

        $this->post(route('admin.logout'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->post(route('login.attempt'), ['login' => 'ADMIN@example.com', 'password' => 'rahasia-123'])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_wrong_password_and_account_without_password_cannot_log_in(): void
    {
        $this->post(route('login.attempt'), ['login' => 'admin', 'password' => 'salah-sekali'])
            ->assertSessionHasErrors('login');

        User::create(['name' => 'Tanpa Password', 'username' => 'kosong', 'email' => 'kosong@contoh.test', 'password' => null]);
        $this->post(route('login.attempt'), ['login' => 'kosong', 'password' => ''])->assertSessionHasErrors();
        $this->post(route('login.attempt'), ['login' => 'kosong', 'password' => 'apa-saja-123'])->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_login_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.attempt'), ['login' => 'admin', 'password' => 'salah-'.$i]);
        }

        $this->post(route('login.attempt'), ['login' => 'admin', 'password' => 'rahasia-123'])
            ->assertSessionHasErrors(['login' => 'Terlalu banyak percobaan login. Coba lagi dalam 60 detik.']);
        $this->assertGuest();
    }

    // ----- Dasbor -----

    public function test_dashboard_shows_today_counts_taps_and_devices(): void
    {
        $budi = Member::factory()->create(['name' => 'Budi Santoso']);
        $siti = Member::factory()->create(['name' => 'Siti Aminah']);
        Member::factory()->create(['name' => 'Belum Datang']);
        $device = $this->device();
        $device->forceFill(['last_seen_at' => now()->subMinute(), 'name' => 'Pintu Utama'])->save();

        $this->tapRow($budi, $budi->card_uid, 'success', 'check_in', '2026-09-30 07:00', $device);
        $this->tapRow($budi, $budi->card_uid, 'success', 'check_out', '2026-09-30 09:00', $device);
        $this->tapRow($siti, $siti->card_uid, 'success', 'check_in', '2026-09-30 07:30', $device);
        $this->tapRow(null, '0000012345', 'unknown_card', null, '2026-09-30 08:00', $device);

        $this->actingAs($this->admin)->get('/admin')
            ->assertOk()
            ->assertSee('data-live="'.route('admin.dashboard.live').'"', false)
            ->assertSeeInOrder(['Masuk', '2', 'Pulang', '1', 'Belum hadir', '1'])
            ->assertSee('Budi Santoso')
            ->assertSee('0000012345')
            ->assertSee('Pintu Utama');

        $this->actingAs($this->admin)->get(route('admin.dashboard.live'))
            ->assertOk()
            ->assertSee('Tap terakhir')
            ->assertDontSee('<html', false);
    }

    // ----- Anggota -----

    public function test_members_crud(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('admin.members.store'), [
            'name' => ' Budi Santoso ', 'identifier' => '1001', 'card_uid' => '0218893066', 'is_active' => '1',
        ])->assertRedirect(route('admin.members.index'));

        $member = Member::where('card_uid', '0218893066')->sole();
        $this->assertSame('Budi Santoso', $member->name);
        $this->assertTrue($member->is_active);

        $this->get(route('admin.members.index', ['q' => '17116']))->assertOk()->assertSee('Budi Santoso');
        $this->get(route('admin.members.index', ['q' => 'tidak-ada']))->assertOk()->assertDontSee('Budi Santoso');
        $this->get(route('admin.members.edit', $member))->assertOk()->assertSee('0218893066');

        // Nomor kartu sudah dipakai / tidak valid
        $this->post(route('admin.members.store'), ['name' => 'Lain', 'card_uid' => '0218893066'])->assertSessionHasErrors('card_uid');
        $this->post(route('admin.members.store'), ['name' => 'Lain', 'card_uid' => '12ab'])->assertSessionHasErrors('card_uid');

        $this->put(route('admin.members.update', $member), [
            'name' => 'Budi S.', 'identifier' => '', 'card_uid' => '0011223344', // tanpa is_active = nonaktif
        ])->assertRedirect(route('admin.members.index'));

        $member->refresh();
        $this->assertSame(['Budi S.', null, '0011223344', false], [$member->name, $member->identifier, $member->card_uid, $member->is_active]);

        $this->delete(route('admin.members.destroy', $member))->assertRedirect(route('admin.members.index'));
        $this->assertDatabaseCount('members', 0);
    }

    #[RequiresPhpExtension('gd')]
    public function test_member_photo_upload_is_processed_and_used_by_api(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin);

        $photo = UploadedFile::fake()->createWithContent('budi.jpg', MemberPhotoTest::noisyImage(1000, 1000, progressive: true));

        $this->post(route('admin.members.store'), [
            'name' => 'Budi Santoso', 'card_uid' => '0218893066', 'is_active' => '1', 'photo' => $photo,
        ])->assertRedirect(route('admin.members.index'));

        $member = Member::sole();
        $this->assertNotNull($member->photo_path);
        Storage::disk('public')->assertExists($member->photo_path);

        $jpeg = Storage::disk('public')->get($member->photo_path);
        [$width, $height] = getimagesizefromstring($jpeg);
        $this->assertSame([160, 160], [$width, $height]);
        $this->assertLessThanOrEqual(30 * 1024, strlen($jpeg));
        $this->assertTrue(MemberPhotoTest::isBaseline($jpeg));

        // Dipakai di respons /tap
        Setting::setValue(Setting::API_KEY, 'kunci-tes');
        $this->postJson('/api/absensi/tap', ['tap_id' => 'T1', 'rfid' => '0218893066', 'queued' => false, 'tapped_at' => null],
            ['X-API-Key' => 'kunci-tes', 'X-Device-ID' => 'ABS-1A2B3C'])
            ->assertJsonPath('photo_url', asset('storage/'.$member->photo_path));

        // Ganti foto: file lama dihapus. Hapus foto: kolom dikosongkan.
        $oldPath = $member->photo_path;
        $this->put(route('admin.members.update', $member), [
            'name' => 'Budi Santoso', 'card_uid' => '0218893066', 'is_active' => '1',
            'photo' => UploadedFile::fake()->createWithContent('baru.png', self::png()),
        ])->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($oldPath);
        $newPath = $member->fresh()->photo_path;
        Storage::disk('public')->assertExists($newPath);

        $this->put(route('admin.members.update', $member), [
            'name' => 'Budi Santoso', 'card_uid' => '0218893066', 'is_active' => '1', 'remove_photo' => '1',
        ]);
        $this->assertNull($member->fresh()->photo_path);
        Storage::disk('public')->assertMissing($newPath);

        // Bukan gambar
        $this->put(route('admin.members.update', $member), [
            'name' => 'Budi Santoso', 'card_uid' => '0218893066', 'photo' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('photo');
    }

    // ----- Kehadiran -----

    public function test_attendance_page_lists_taps_of_the_chosen_date_and_filter(): void
    {
        $budi = Member::factory()->create(['name' => 'Budi Santoso', 'card_uid' => '0218893066']);
        $siti = Member::factory()->create(['name' => 'Siti Aminah', 'card_uid' => '0012345678']);
        $this->tapRow($budi, '0218893066', 'success', 'check_in', '2026-09-30 07:05');
        $this->tapRow($siti, '0012345678', 'success', 'check_in', '2026-09-30 07:15');
        $this->tapRow($budi, '0218893066', 'success', 'check_in', '2026-09-29 07:00');

        $this->actingAs($this->admin)->get(route('admin.attendances.index'))
            ->assertOk()
            ->assertSee('Budi Santoso')
            ->assertSee('Siti Aminah')
            ->assertDontSee('07:00:00');

        $this->get(route('admin.attendances.index', ['tanggal' => '2026-09-30', 'q' => 'budi']))
            ->assertOk()
            ->assertSee('07:05:00')
            ->assertDontSee('Siti Aminah');

        $this->get(route('admin.attendances.index', ['tanggal' => '30-09-2026']))
            ->assertSessionHasErrors('tanggal');
    }

    public function test_admin_can_delete_one_tap(): void
    {
        $budi = Member::factory()->create(['card_uid' => '0218893066']);
        $tap = $this->tapRow($budi, '0218893066', 'success', 'check_in', '2026-09-30 07:05');
        $other = $this->tapRow($budi, '0218893066', 'success', 'check_out', '2026-09-30 16:00');

        $this->actingAs($this->admin)->delete(route('admin.attendances.destroy', $tap))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertModelMissing($tap);
        $this->assertModelExists($other);
    }

    public function test_delete_day_removes_only_that_date_and_respects_the_filter(): void
    {
        $budi = Member::factory()->create(['name' => 'Budi Santoso', 'card_uid' => '0218893066']);
        $siti = Member::factory()->create(['name' => 'Siti Aminah', 'card_uid' => '0012345678']);
        $budiToday = $this->tapRow($budi, '0218893066', 'success', 'check_in', '2026-09-30 07:05');
        $sitiToday = $this->tapRow($siti, '0012345678', 'success', 'check_in', '2026-09-30 07:15');
        $budiYesterday = $this->tapRow($budi, '0218893066', 'success', 'check_in', '2026-09-29 07:00');

        $this->actingAs($this->admin)->delete(route('admin.attendances.destroy-day'), ['tanggal' => '2026-09-30', 'q' => 'Budi'])
            ->assertRedirect(route('admin.attendances.index', ['tanggal' => '2026-09-30', 'q' => 'Budi']));
        $this->assertModelMissing($budiToday);
        $this->assertModelExists($sitiToday);
        $this->assertModelExists($budiYesterday);

        $this->delete(route('admin.attendances.destroy-day'), ['tanggal' => '2026-09-30']);
        $this->assertModelMissing($sitiToday);
        $this->assertModelExists($budiYesterday);
    }

    public function test_guests_cannot_see_or_delete_attendance(): void
    {
        $tap = $this->tapRow(null, '1111111111', 'unknown_card', null, '2026-09-30 08:00');

        $this->get(route('admin.attendances.index'))->assertRedirect(route('login'));
        $this->delete(route('admin.attendances.destroy', $tap))->assertRedirect(route('login'));
        $this->delete(route('admin.attendances.destroy-day'), ['tanggal' => '2026-09-30'])->assertRedirect(route('login'));
        $this->assertModelExists($tap);
    }

    // ----- Kartu belum terdaftar -----

    public function test_unknown_cards_page_lists_only_unregistered_cards(): void
    {
        $registered = Member::factory()->create(['card_uid' => '2222222222']);
        $this->tapRow(null, '1111111111', 'unknown_card', null, '2026-09-30 08:00');
        $this->tapRow(null, '2222222222', 'unknown_card', null, '2026-09-30 08:05'); // kini sudah didaftarkan

        $this->actingAs($this->admin)->get(route('admin.unknown-cards'))
            ->assertOk()
            ->assertSee('1111111111')
            ->assertSee(route('admin.members.create', ['kartu' => '1111111111']), false)
            ->assertDontSee('2222222222');

        $this->get(route('admin.members.create', ['kartu' => '1111111111']))
            ->assertOk()
            ->assertSee('value="1111111111"', false);
        $this->assertNotNull($registered);
    }

    // ----- Rekap -----

    public function test_recap_and_csv_export(): void
    {
        $budi = Member::factory()->create(['name' => 'Budi Santoso', 'identifier' => '1001', 'card_uid' => '0218893066']);
        Member::factory()->create(['name' => 'Siti Aminah']);
        $this->tapRow($budi, '0218893066', 'success', 'check_in', '2026-09-29 07:10');
        $this->tapRow($budi, '0218893066', 'success', 'check_out', '2026-09-29 15:00');
        $this->tapRow($budi, '0218893066', 'success', 'check_out', '2026-09-29 16:30');
        $this->tapRow($budi, '0218893066', 'success', 'check_in', '2026-09-30 07:05');

        $this->actingAs($this->admin)->get(route('admin.reports.index', ['dari' => '2026-09-29', 'sampai' => '2026-09-30']))
            ->assertOk()
            ->assertSeeInOrder(['Budi Santoso', '07:10:00', '16:30:00'])
            ->assertSee('Tidak hadir');

        $csv = $this->get(route('admin.reports.export', ['dari' => '2026-09-29', 'sampai' => '2026-09-30']))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $this->assertStringContainsString('Tanggal,Nama,NIS/NIP,"Nomor Kartu"', $csv);
        $this->assertStringContainsString('2026-09-29,"Budi Santoso",1001,0218893066,07:10:00,16:30:00,Hadir', $csv);
        $this->assertStringContainsString('2026-09-30,"Budi Santoso",1001,0218893066,07:05:00,,"Tanpa tap pulang"', $csv);
    }

    // ----- Alat -----

    public function test_pin_is_set_per_device_and_sent_only_to_that_device(): void
    {
        $door = $this->device('ABS-0001');
        $this->device('ABS-0002');
        $headers = fn (string $code) => ['X-API-Key' => Setting::apiKey(), 'X-Device-ID' => $code];

        $this->actingAs($this->admin)->put(route('admin.devices.update', $door), ['name' => 'Pintu Utama', 'pin' => '4321'])
            ->assertRedirect(route('admin.devices.index'));
        $this->assertSame('4321', $door->fresh()->pin);

        $this->postJson('/api/absensi/heartbeat', ['device_id' => 'ABS-0001', 'firmware' => '1.1.0'], $headers('ABS-0001'))->assertJsonPath('config.pin', '4321');
        $this->getJson('/api/absensi/ping', $headers('ABS-0002'))->assertJsonMissingPath('config.pin');

        $this->put(route('admin.devices.update', $door), ['name' => 'Pintu Utama', 'pin' => ''])->assertSessionHasNoErrors();
        $this->assertNull($door->fresh()->pin);
        $this->getJson('/api/absensi/ping', $headers('ABS-0001'))->assertJsonMissingPath('config.pin');

        $this->put(route('admin.devices.update', $door), ['name' => 'Pintu Utama', 'pin' => '12'])->assertSessionHasErrorsIn('device-'.$door->id, ['pin']);
        $this->put(route('admin.devices.update', $door), ['name' => 'Pintu Utama', 'pin' => '12ab'])->assertSessionHasErrorsIn('device-'.$door->id, ['pin']);
    }

    public function test_restart_time_is_set_per_device_and_sent_in_config(): void
    {
        $door = $this->device('ABS-0001');
        $other = $this->device('ABS-0002');
        $headers = fn (string $code) => ['X-API-Key' => Setting::apiKey(), 'X-Device-ID' => $code];
        $errorBag = 'device-'.$door->id;

        // Bawaan 03:00, termasuk alat yang dibuat otomatis saat pertama kali menghubungi server.
        $this->assertSame('03:00', $door->fresh()->restart_at);
        $this->postJson('/api/absensi/heartbeat', ['device_id' => 'ABS-0001'], $headers('ABS-0001'))->assertJsonPath('config.restart_at', '03:00');
        $this->getJson('/api/absensi/ping', $headers('ABS-NEW'))->assertJsonPath('config.restart_at', '03:00');
        $this->assertSame('03:00', Device::where('code', 'ABS-NEW')->sole()->restart_at);

        $this->actingAs($this->admin)->get(route('admin.devices.index'))
            ->assertOk()
            ->assertSee('value="03:00"', false)
            ->assertSee('Restart harian pukul');

        $this->put(route('admin.devices.update', $door), ['name' => 'Pintu Utama', 'pin' => '', 'restart_at' => '22:15'])
            ->assertRedirect(route('admin.devices.index'))
            ->assertSessionHasNoErrors();
        $this->assertSame('22:15', $door->fresh()->restart_at);
        $this->getJson('/api/absensi/ping', $headers('ABS-0001'))->assertJsonPath('config.restart_at', '22:15');
        $this->getJson('/api/absensi/ping', $headers('ABS-0002'))->assertJsonPath('config.restart_at', '03:00');

        // Dikosongkan = tidak restart otomatis: disimpan null, dikirim "".
        $this->put(route('admin.devices.update', $door), ['name' => 'Pintu Utama', 'pin' => '', 'restart_at' => ''])->assertSessionHasNoErrors();
        $this->assertNull($door->fresh()->restart_at);
        $this->postJson('/api/absensi/heartbeat', ['device_id' => 'ABS-0001'], $headers('ABS-0001'))
            ->assertJsonPath('config.restart_at', '');
        $this->get(route('admin.devices.index'))->assertSee('Tidak restart otomatis');
        $this->assertSame('03:00', $other->fresh()->restart_at);

        foreach (['25:00', '3pm', '03:7', '3:00', '03:00:00', '12:60'] as $invalid) {
            $this->put(route('admin.devices.update', $door), ['name' => 'Pintu Utama', 'restart_at' => $invalid])
                ->assertSessionHasErrorsIn($errorBag, ['restart_at']);
        }
        $this->assertNull($door->fresh()->restart_at);
        $this->assertSame('03:00', $other->fresh()->restart_at);
    }

    public function test_devices_page_shows_status_and_name_is_editable(): void
    {
        $device = $this->device('ABS-1A2B3C');
        $device->forceFill(['last_seen_at' => now()->subSeconds(30), 'firmware' => '1.1.0', 'ip' => '192.168.1.23', 'rssi' => -52, 'wifi_ssid' => 'Kantor-2.4G'])->save();
        $this->device('ABS-OFFLINE')->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();

        $this->actingAs($this->admin)->get(route('admin.devices.index'))
            ->assertOk()
            ->assertSeeInOrder(['ABS-1A2B3C', 'Aktif', '1.1.0', '192.168.1.23', '-52 dBm', 'Kantor-2.4G'])
            ->assertSeeInOrder(['ABS-OFFLINE', 'Tidak aktif']);

        $this->put(route('admin.devices.update', $device), ['name' => 'Pintu Utama'])->assertRedirect(route('admin.devices.index'));
        $this->assertSame('Pintu Utama', $device->fresh()->name);
        $this->assertSame('ABS-1A2B3C', $device->fresh()->code);

        $this->put(route('admin.devices.update', $device), ['name' => ''])->assertSessionHasErrorsIn('device-'.$device->id, ['name']);
    }

    public function test_device_validation_error_is_shown_in_the_row_of_that_device(): void
    {
        $first = $this->device('ABS-0001');
        $first->forceFill(['last_seen_at' => now(), 'pin' => '1111'])->save();
        $second = $this->device('ABS-0002');
        $second->forceFill(['last_seen_at' => now()->subMinute(), 'pin' => '2222'])->save();

        $this->actingAs($this->admin)
            ->from(route('admin.devices.index'))
            ->followingRedirects()
            ->put(route('admin.devices.update', $first), ['name' => 'Gerbang', 'pin' => '12'])
            ->assertOk()
            ->assertSeeInOrder(['ABS-0001', 'value="Gerbang"', 'value="12"', 'PIN harus 4 sampai 8 digit angka.', 'ABS-0002', 'value="ABS-0002"', 'value="2222"'], false);

        $this->assertSame('1111', $first->fresh()->pin);
    }

    // ----- Pengaturan -----

    public function test_settings_api_key_pin_and_title(): void
    {
        $this->actingAs($this->admin);
        $oldKey = Setting::apiKey();
        $this->assertSame(24, strlen($oldKey));

        $this->get(route('admin.settings'))->assertOk()->assertSee($oldKey)->assertSee(url('/api/absensi'));

        $this->post(route('admin.settings.api-key'))->assertRedirect(route('admin.settings'));
        $newKey = Setting::apiKey();
        $this->assertNotSame($oldKey, $newKey);
        $this->assertSame(24, strlen($newKey));

        $this->put(route('admin.settings.title'), ['title' => 'SMK Contoh'])->assertSessionHasNoErrors();
        $this->getJson('/api/absensi/ping', ['X-API-Key' => $newKey, 'X-Device-ID' => 'ABS-1'])
            ->assertJson(['message' => 'Terhubung ke SMK Contoh', 'config' => ['title' => 'SMK Contoh']])
            ->assertJsonMissingPath('config.pin');
        $this->getJson('/api/absensi/ping', ['X-API-Key' => $oldKey, 'X-Device-ID' => 'ABS-1'])->assertStatus(401);

        $this->put(route('admin.settings.title'), ['title' => str_repeat('x', 31)])
            ->assertSessionHasErrorsIn('title', ['title']);
    }

    public function test_screen_dim_settings_are_sent_to_devices(): void
    {
        $this->actingAs($this->admin);
        $headers = ['X-API-Key' => Setting::apiKey(), 'X-Device-ID' => 'ABS-1'];

        $this->get(route('admin.settings'))->assertOk()->assertSee('Layar alat');
        $this->getJson('/api/absensi/ping', $headers)
            ->assertJsonPath('config.dim_after', 60)
            ->assertJsonPath('config.dim_level', 20);

        $this->put(route('admin.settings.screen'), ['dim_after' => '120', 'dim_level' => '0'])
            ->assertRedirect(route('admin.settings'))
            ->assertSessionHasNoErrors();
        $this->postJson('/api/absensi/heartbeat', ['device_id' => 'ABS-1'], $headers)
            ->assertJsonPath('config.dim_after', 120)
            ->assertJsonPath('config.dim_level', 0);

        $this->put(route('admin.settings.screen'), ['dim_after' => '0', 'dim_level' => '100'])
            ->assertSessionHasNoErrors();
        $this->getJson('/api/absensi/ping', $headers)
            ->assertJsonPath('config.dim_after', 0)
            ->assertJsonPath('config.dim_level', 100);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string, 2: string}>
     */
    public static function invalidScreenSettings(): array
    {
        $after = 'Redup setelah harus 0 (tidak pernah redup) atau 10 sampai 3600.';

        return [
            'dim_after 1-9' => [['dim_after' => '5'], 'dim_after', $after],
            'dim_after 9' => [['dim_after' => '9'], 'dim_after', $after],
            'dim_after above 3600' => [['dim_after' => '3601'], 'dim_after', 'Redup setelah harus bernilai 0 sampai 3600.'],
            'dim_after negative' => [['dim_after' => '-1'], 'dim_after', 'Redup setelah harus bernilai 0 sampai 3600.'],
            'dim_after not a number' => [['dim_after' => 'abc'], 'dim_after', 'Redup setelah harus bilangan bulat.'],
            'dim_level above 100' => [['dim_level' => '101'], 'dim_level', 'Kecerahan saat redup harus bernilai 0 sampai 100.'],
            'dim_level negative' => [['dim_level' => '-1'], 'dim_level', 'Kecerahan saat redup harus bernilai 0 sampai 100.'],
            'dim_level missing' => [['dim_level' => ''], 'dim_level', 'Kecerahan saat redup wajib diisi.'],
        ];
    }

    /**
     * @param  array<string, string>  $input
     */
    #[DataProvider('invalidScreenSettings')]
    public function test_rejects_invalid_screen_settings(array $input, string $field, string $message): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.screen'), $input + ['dim_after' => '120', 'dim_level' => '50'])
            ->assertSessionHasErrorsIn('screen', [$field => $message]);

        $this->assertNull(Setting::getValue(Setting::DIM_AFTER));
        $this->assertNull(Setting::getValue(Setting::DIM_LEVEL));
    }

    public function test_invalid_stored_dim_values_fall_back_to_defaults(): void
    {
        Setting::setValue(Setting::DIM_AFTER, '5');
        Setting::setValue(Setting::DIM_LEVEL, '150');

        $this->getJson('/api/absensi/ping', ['X-API-Key' => Setting::apiKey(), 'X-Device-ID' => 'ABS-1'])
            ->assertJsonPath('config.dim_after', 60)
            ->assertJsonPath('config.dim_level', 20);
    }

    public function test_change_own_password(): void
    {
        $this->actingAs($this->admin);

        $this->put(route('admin.settings.password'), [
            'current_password' => 'salah', 'password' => 'password-baru', 'password_confirmation' => 'password-baru',
        ])->assertSessionHasErrorsIn('password', 'current_password');

        $this->put(route('admin.settings.password'), [
            'current_password' => 'rahasia-123', 'password' => 'password-baru', 'password_confirmation' => 'password-baru',
        ])->assertSessionHasNoErrors();

        $this->post(route('admin.logout'));
        $this->post(route('login.attempt'), ['login' => 'admin', 'password' => 'password-baru'])->assertRedirect(route('admin.dashboard'));
    }

    private static function png(): string
    {
        $image = imagecreatetruecolor(400, 200);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
