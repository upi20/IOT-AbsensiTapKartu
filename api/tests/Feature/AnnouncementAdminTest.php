<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Menu "Pengumuman" di panel admin: CRUD pengumuman + pengaturan screensaver.
 */
class AnnouncementAdminTest extends TestCase
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

    /**
     * @return array<string, string>
     */
    private function validInput(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Rapat Guru',
            'description' => 'Hari ini pukul 13.00 di aula.',
            'icon' => 'rapat',
            'sort_order' => '1',
            'is_active' => '1',
        ];
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $announcement = Announcement::factory()->create(['title' => 'Tetap']);

        $this->get(route('admin.announcements.index'))->assertRedirect(route('login'));
        $this->get(route('admin.announcements.create'))->assertRedirect(route('login'));
        $this->get(route('admin.announcements.edit', $announcement))->assertRedirect(route('login'));
        $this->post(route('admin.announcements.store'), $this->validInput())->assertRedirect(route('login'));
        $this->put(route('admin.announcements.update', $announcement), $this->validInput())->assertRedirect(route('login'));
        $this->delete(route('admin.announcements.destroy', $announcement))->assertRedirect(route('login'));
        $this->put(route('admin.announcements.screensaver'), ['interval' => '10', 'idle' => '60'])->assertRedirect(route('login'));
        $this->patch(route('admin.announcements.bulk'), ['ids' => [$announcement->id], 'action' => 'hide'])->assertRedirect(route('login'));

        $this->assertDatabaseCount('announcements', 1);
        $this->assertSame('Tetap', $announcement->fresh()->title);
        $this->assertTrue($announcement->fresh()->is_active);
        $this->assertSame(3, Setting::screensaverInterval());
    }

    public function test_index_lists_announcements_and_screensaver_settings(): void
    {
        $announcement = Announcement::factory()->create(['title' => 'Rapat Guru', 'description' => 'Di aula lantai 2', 'icon' => 'rapat']);
        Announcement::factory()->inactive()->create(['title' => 'Libur Lama', 'icon' => 'libur']);
        Setting::setValue(Setting::SCREENSAVER_IDLE, '45');

        $this->actingAs($this->admin)
            ->get(route('admin.announcements.index'))
            ->assertOk()
            ->assertSee('name="ids[]" value="'.$announcement->id.'" form="bulk-form"', false)
            ->assertSeeInOrder(['Rapat Guru', 'Di aula lantai 2', 'Rapat', 'rapat', 'Aktif'])
            ->assertSeeInOrder(['Libur Lama', 'Libur', 'libur', 'Nonaktif'])
            ->assertSee('value="45"', false)
            ->assertSee('±1 menit');
    }

    public function test_index_marks_active_announcements_beyond_the_device_limit(): void
    {
        Announcement::factory()->count(10)->create(['sort_order' => 1]);
        Announcement::factory()->create(['title' => 'Kesebelas', 'sort_order' => 2]);

        $response = $this->actingAs($this->admin)->get(route('admin.announcements.index'));

        $response->assertSeeInOrder(['Kesebelas', 'Aktif, tidak tampil']);
        $this->assertSame(1, substr_count($response->getContent(), 'Aktif, tidak tampil'));
    }

    public function test_admin_can_create_announcement(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.announcements.store'), $this->validInput(['title' => '  Rapat Guru  ', 'description' => '']))
            ->assertRedirect(route('admin.announcements.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('announcements', [
            'title' => 'Rapat Guru', 'description' => null, 'icon' => 'rapat', 'sort_order' => 1, 'is_active' => true,
        ]);
    }

    public function test_title_of_40_multibyte_characters_is_accepted(): void
    {
        $title = str_repeat('é', 40);

        $this->actingAs($this->admin)
            ->post(route('admin.announcements.store'), $this->validInput(['title' => $title]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('announcements', ['title' => $title]);
    }

    public function test_admin_can_update_announcement(): void
    {
        $announcement = Announcement::factory()->create(['title' => 'Lama', 'icon' => 'info', 'sort_order' => 5]);

        $this->actingAs($this->admin)->get(route('admin.announcements.edit', $announcement))->assertSee('Lama');

        $this->put(route('admin.announcements.update', $announcement), [
            'title' => 'Libur Nasional', 'description' => 'Kantor tutup', 'icon' => 'libur', 'sort_order' => '0', // tanpa is_active = nonaktif
        ])->assertRedirect(route('admin.announcements.index'));

        $announcement->refresh();
        $this->assertSame(
            ['Libur Nasional', 'Kantor tutup', 'libur', 0, false],
            [$announcement->title, $announcement->description, $announcement->icon, $announcement->sort_order, $announcement->is_active],
        );
    }

    public function test_admin_can_delete_announcement(): void
    {
        $announcement = Announcement::factory()->create();

        $this->actingAs($this->admin)
            ->delete(route('admin.announcements.destroy', $announcement))
            ->assertRedirect(route('admin.announcements.index'));

        $this->assertModelMissing($announcement);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string, 2: string}>
     */
    public static function invalidAnnouncements(): array
    {
        return [
            'title missing' => [['title' => ''], 'title', 'Judul wajib diisi.'],
            'title longer than 40' => [['title' => str_repeat('a', 41)], 'title', 'Judul maksimal 40 karakter.'],
            'description longer than 160' => [['description' => str_repeat('a', 161)], 'description', 'Deskripsi maksimal 160 karakter.'],
            'unknown icon' => [['icon' => 'roket'], 'icon', 'Pilih ikon dari daftar.'],
            'negative order' => [['sort_order' => '-1'], 'sort_order', 'Urutan minimal bernilai 0.'],
        ];
    }

    /**
     * @param  array<string, string>  $input
     */
    #[DataProvider('invalidAnnouncements')]
    public function test_rejects_invalid_announcement(array $input, string $field, string $message): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.announcements.store'), $this->validInput($input))
            ->assertSessionHasErrors([$field => $message]);

        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_admin_can_show_selected_announcements(): void
    {
        $selected = Announcement::factory()->inactive()->count(2)->create();
        $other = Announcement::factory()->inactive()->create();

        $this->actingAs($this->admin)
            ->patch(route('admin.announcements.bulk'), ['ids' => $selected->modelKeys(), 'action' => 'show'])
            ->assertRedirect(route('admin.announcements.index'))
            ->assertSessionHas('success', '2 pengumuman ditampilkan. Alat menerapkannya dalam ±1 menit.');

        $this->assertTrue($selected[0]->fresh()->is_active);
        $this->assertTrue($selected[1]->fresh()->is_active);
        $this->assertFalse($other->fresh()->is_active);
    }

    public function test_admin_can_hide_selected_announcements(): void
    {
        $selected = Announcement::factory()->count(3)->create();
        $other = Announcement::factory()->create();

        $this->actingAs($this->admin)
            ->patch(route('admin.announcements.bulk'), ['ids' => $selected->modelKeys(), 'action' => 'hide'])
            ->assertRedirect(route('admin.announcements.index'))
            ->assertSessionHas('success', '3 pengumuman disembunyikan. Alat menerapkannya dalam ±1 menit.');

        $this->assertSame([false, false, false], $selected->map(fn (Announcement $announcement) => $announcement->fresh()->is_active)->all());
        $this->assertTrue($other->fresh()->is_active);
    }

    public function test_admin_can_delete_selected_announcements(): void
    {
        $selected = Announcement::factory()->count(2)->create();
        $other = Announcement::factory()->create();

        $this->actingAs($this->admin)
            ->patch(route('admin.announcements.bulk'), ['ids' => $selected->modelKeys(), 'action' => 'delete'])
            ->assertRedirect(route('admin.announcements.index'))
            ->assertSessionHas('success', '2 pengumuman dihapus. Alat menerapkannya dalam ±1 menit.');

        $this->assertModelMissing($selected[0]);
        $this->assertModelMissing($selected[1]);
        $this->assertModelExists($other);
    }

    public function test_bulk_hide_changes_the_announcements_rev(): void
    {
        $announcements = Announcement::factory()->count(2)->create();
        $before = Announcement::revision();

        $this->travel(1)->minutes();
        $this->actingAs($this->admin)
            ->patch(route('admin.announcements.bulk'), ['ids' => [$announcements[0]->id], 'action' => 'hide']);

        $this->assertNotSame($before, Announcement::revision());
        $this->assertSame('2-'.now()->getTimestamp().'-3-30', Announcement::revision());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function invalidBulkActions(): array
    {
        return [
            'nothing selected' => [['ids' => []], 'ids', 'Pilih minimal satu pengumuman.'],
            'ids not a list' => [['ids' => 'semua'], 'ids', 'Pengumuman harus berupa daftar.'],
            'unknown id' => [['ids' => [999]], 'ids.0', 'Pengumuman yang dipilih tidak valid.'],
            'action missing' => [['action' => ''], 'action', 'Aksi wajib diisi.'],
            'unknown action' => [['action' => 'arsipkan'], 'action', 'Aksi yang dipilih tidak valid.'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    #[DataProvider('invalidBulkActions')]
    public function test_rejects_invalid_bulk_action(array $input, string $field, string $message): void
    {
        $announcement = Announcement::factory()->create();

        $this->actingAs($this->admin)
            ->patch(route('admin.announcements.bulk'), $input + ['ids' => [$announcement->id], 'action' => 'hide'])
            ->assertSessionHasErrorsIn('bulk', [$field => $message]);

        $this->assertTrue($announcement->fresh()->is_active);
    }

    public function test_admin_can_save_screensaver_settings(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.announcements.screensaver'), ['interval' => '10', 'idle' => '600'])
            ->assertRedirect(route('admin.announcements.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('10', Setting::getValue(Setting::SCREENSAVER_INTERVAL));
        $this->assertSame('600', Setting::getValue(Setting::SCREENSAVER_IDLE));
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string, 2: string}>
     */
    public static function invalidScreensaverSettings(): array
    {
        return [
            'interval below 2' => [['interval' => '1'], 'interval', 'Lama tiap pengumuman harus bernilai 2 sampai 60.'],
            'interval above 60' => [['interval' => '61'], 'interval', 'Lama tiap pengumuman harus bernilai 2 sampai 60.'],
            'interval not a number' => [['interval' => 'abc'], 'interval', 'Lama tiap pengumuman harus bilangan bulat.'],
            'idle below 5' => [['idle' => '4'], 'idle', 'Jeda screensaver harus bernilai 5 sampai 600.'],
            'idle above 600' => [['idle' => '601'], 'idle', 'Jeda screensaver harus bernilai 5 sampai 600.'],
            'idle missing' => [['idle' => ''], 'idle', 'Jeda screensaver wajib diisi.'],
        ];
    }

    /**
     * @param  array<string, string>  $input
     */
    #[DataProvider('invalidScreensaverSettings')]
    public function test_rejects_invalid_screensaver_settings(array $input, string $field, string $message): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.announcements.screensaver'), $input + ['interval' => '10', 'idle' => '60'])
            ->assertSessionHasErrorsIn('screensaver', [$field => $message]);

        $this->assertSame('3', Setting::getValue(Setting::SCREENSAVER_INTERVAL));
        $this->assertSame('30', Setting::getValue(Setting::SCREENSAVER_IDLE));
    }
}
