<?php

namespace Tests\Feature;

use App\Models\Announcement;
use Database\Seeders\AnnouncementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_one_valid_announcement_per_icon_and_can_run_twice(): void
    {
        $this->seed(AnnouncementSeeder::class);
        $this->seed(AnnouncementSeeder::class);

        $announcements = Announcement::all();

        $this->assertCount(count(Announcement::ICONS), $announcements);
        $this->assertEqualsCanonicalizing(array_keys(Announcement::ICONS), $announcements->pluck('icon')->all());
        $this->assertTrue($announcements->every(fn (Announcement $a) => $a->is_active
            && mb_strlen($a->title) <= Announcement::TITLE_MAX
            && mb_strlen($a->description) <= Announcement::DESCRIPTION_MAX));
    }
}
