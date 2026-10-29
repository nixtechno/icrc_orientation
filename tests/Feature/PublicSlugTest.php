<?php

namespace Tests\Feature;

use App\Models\{Activity, Announcement, Facilitator, Programme, ProgrammeDay, ProgrammeSession, Resource, Submission, User};
use App\Services\TimetableImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Storage};
use Tests\TestCase;

class PublicSlugTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TimetableImporter::class)->run();
    }

    private function account(string $role = 'staff'): User
    {
        $user = User::factory()->create(['name' => 'Amina Bello', 'role' => $role, 'active' => true]);
        $user->programmes()->attach(Programme::first()->id);
        return $user;
    }

    public function test_activity_links_are_readable_unique_and_stable_when_titles_change(): void
    {
        $p = Programme::first();
        $values = ['programme_id' => $p->id, 'title' => 'PPP Fundamentals', 'kind' => 'quiz', 'instructions' => 'Answer', 'due_at' => now()->addDay()];
        $first = Activity::create($values); $second = Activity::create($values);
        $this->assertStringStartsWith('ppp-fundamentals-', $first->getRouteKey());
        $this->assertNotSame($first->slug, $second->slug);
        $slug = $first->slug; $first->update(['title' => 'A revised title']);
        $this->assertSame($slug, $first->fresh()->slug);
        $this->actingAs($this->account('admin'))->get('/activities/'.$slug)->assertOk();
        $this->get('/activities')->assertSee('/activities/'.$slug, false)->assertDontSee('href="/activities/'.$first->id.'"', false);
        $this->get('/activities/not-a-real-slug')->assertNotFound();
    }

    public function test_legacy_browser_links_redirect_only_after_permission_checks(): void
    {
        $activity = Activity::create(['programme_id' => Programme::first()->id, 'title' => 'Private assessment', 'kind' => 'quiz', 'instructions' => 'Private', 'due_at' => now()->addDay()]);
        $this->actingAs($this->account('admin'))->withHeaders(['Sec-Fetch-Mode' => 'navigate'])->get('/activities/'.$activity->id.'?view=review')->assertRedirect('/activities/'.$activity->slug.'?view=review')->assertStatus(301);
        $this->actingAs($this->account())->get('/activities/'.$activity->id)->assertForbidden();
        $this->get('/activities/'.$activity->slug)->assertForbidden();
    }

    public function test_staff_profiles_sessions_and_attempts_work_with_slug_routes_and_actions(): void
    {
        $admin = $this->account('admin'); $staff = $this->account();
        $this->actingAs($admin)->get('/manage/staff/'.$staff->slug)->assertOk();
        $this->get('/manage/staff')->assertSee('/manage/staff/'.$staff->slug, false);
        $session = ProgrammeSession::firstOrFail();
        $this->get('/manage/sessions/'.$session->slug)->assertOk();
        $this->get('/qa/sessions/'.$session->slug)->assertOk();
        $this->get('/timetable')->assertSee('/qa/sessions/'.$session->slug, false);
        $activity = Activity::create(['programme_id' => Programme::first()->id, 'title' => 'Assignment', 'kind' => 'assignment', 'instructions' => 'Write', 'due_at' => now()->addDay(), 'published' => true]);
        $activity->participants()->attach($staff->id);
        $this->actingAs($staff)->post('/activities/'.$activity->slug.'/start')->assertRedirect();
        $attempt = $activity->submissions()->firstOrFail();
        $this->assertStringStartsWith('attempt-', $attempt->slug);
        $this->get('/submissions/'.$attempt->slug.'/complete')->assertOk();
        $this->post('/submissions/'.$attempt->slug.'/complete', ['body' => 'Submitted using a slug'])->assertRedirect('/activities/'.$activity->slug);
        $this->assertSame('Submitted using a slug', $attempt->fresh()->body);
        $this->actingAs($this->account())->get('/submissions/'.$attempt->slug.'/complete')->assertForbidden();
    }

    public function test_resource_downloads_publication_and_announcements_accept_slugs(): void
    {
        Storage::fake('local'); Storage::disk('local')->put('slug-test.pdf', 'test file');
        $admin = $this->account('admin'); $staff = $this->account();
        $resource = Resource::create(['programme_id' => Programme::first()->id, 'uploaded_by' => $admin->id, 'title' => 'PPP guide', 'path' => 'slug-test.pdf', 'filename' => 'guide.pdf', 'published' => false]);
        $this->actingAs($staff)->get('/resources/'.$resource->slug.'/download')->assertForbidden();
        $this->actingAs($admin)->post('/resources/'.$resource->slug.'/publication')->assertRedirect();
        $this->actingAs($staff)->get('/resources/'.$resource->slug.'/download')->assertOk()->assertDownload('guide.pdf');
        $announcement = Announcement::create(['programme_id' => Programme::first()->id, 'title' => 'Session update', 'body' => 'Details', 'published_at' => now()]);
        $this->actingAs($admin)->post('/announcements/'.$announcement->slug.'/archive')->assertRedirect();
        $this->assertTrue((bool) $announcement->fresh()->archived);
    }

    public function test_cloning_a_record_generates_a_new_slug_instead_of_reusing_it(): void
    {
        $day = ProgrammeDay::firstOrFail();
        $copy = $day->replicate(); $copy->number = 999; $copy->date = '2027-01-01'; $copy->save();
        $this->assertNotSame($day->slug, $copy->slug);
    }

    public function test_attendance_and_reports_accept_public_staff_references(): void
    {
        $admin = $this->account('admin'); $staff = $this->account();
        $this->actingAs($admin)->get('/attendance?staff='.$staff->slug)->assertOk();
        $response = $this->get('/reports/export?report=staff&staff='.$staff->slug)->assertOk();
        $this->assertStringContainsString($staff->email, $response->streamedContent());
        $this->actingAs($this->account())->get('/attendance?staff='.$staff->slug)->assertForbidden();
    }
}
