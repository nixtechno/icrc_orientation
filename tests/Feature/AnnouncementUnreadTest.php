<?php

namespace Tests\Feature;

use App\Models\{Announcement, Programme, User};
use App\Services\TimetableImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementUnreadTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'staff', array $extra = []): User
    {
        $user = User::factory()->create(['role' => $role, 'active' => true, ...$extra]);
        $user->programmes()->attach(Programme::first()->id);
        return $user;
    }

    private function announce(string $title, array $extra = []): Announcement
    {
        return Announcement::create(['programme_id' => Programme::first()->id, 'title' => $title, 'body' => 'Details', 'published_at' => now()->subMinute(), ...$extra]);
    }

    public function test_the_announcements_link_counts_unread_announcements_until_the_page_is_opened(): void
    {
        app(TimetableImporter::class)->run();
        $staff = $this->user('staff', ['department' => 'ICT']);
        $this->announce('For everyone'); $this->announce('For ICT', ['department' => 'ICT']);
        $this->announce('For finance', ['department' => 'Finance']); $this->announce('Draft', ['published_at' => null]);
        $this->announce('Scheduled', ['published_at' => now()->addDay()]); $this->announce('Archived', ['archived' => true]);

        $this->actingAs($staff)->get('/dashboard')->assertOk()->assertSee('<b class="portal-nav-count" aria-label="2 unread">2</b>', false)
            ->assertDontSee('id="notifications"', false)->assertDontSee('portal-notifications', false)->assertDontSee('Mark all as read');

        $this->get('/announcements')->assertOk()->assertSee('For ICT')->assertDontSee('For finance')->assertDontSee('portal-nav-count', false);
        $this->get('/dashboard')->assertDontSee('portal-nav-count', false);

        $this->travel(1)->minutes();
        $this->announce('Fresh news', ['published_at' => now()]);
        $this->get('/dashboard')->assertSee('aria-label="1 unread">1</b>', false);

        // People who write announcements do not get the count.
        $this->actingAs($this->user('admin'))->get('/dashboard')->assertOk()->assertDontSee('portal-nav-count', false);
    }
}
