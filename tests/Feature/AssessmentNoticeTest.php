<?php

namespace Tests\Feature;

use App\Models\{Activity, Announcement, Programme, Question, Submission, User};
use App\Services\{QuizAttemptService, TimetableImporter};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AssessmentNoticeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $amina;
    private User $grace;
    private Activity $quiz;

    protected function setUp(): void
    {
        parent::setUp(); app(TimetableImporter::class)->run(); Notification::fake();
        Announcement::$activityLinks = null;
        $programme = Programme::first();
        [$this->admin, $this->amina, $this->grace] = [User::factory()->create(['role' => 'admin', 'active' => true]), User::factory()->create(['role' => 'staff', 'active' => true]), User::factory()->create(['role' => 'staff', 'active' => true])];
        foreach ([$this->admin, $this->amina, $this->grace] as $user) $user->programmes()->attach($programme->id);
        $this->quiz = Activity::create(['programme_id' => $programme->id, 'kind' => 'quiz', 'title' => 'Day 1 Assessment', 'instructions' => 'Answer all', 'due_at' => now()->addDays(3), 'attempts' => 1, 'published' => false, 'assignment_mode' => 'selected']);
        Question::create(['activity_id' => $this->quiz->id, 'type' => 'true_false', 'prompt' => 'True?', 'options' => ['True', 'False'], 'correct_answer' => 'True', 'points' => 1]);
        $this->quiz->participants()->attach($this->amina->id);
    }

    protected function tearDown(): void
    {
        Announcement::$activityLinks = null;
        parent::tearDown();
    }

    public function test_publishing_announces_to_assigned_staff_and_the_menu_counts_what_is_unread_and_unsubmitted(): void
    {
        $url = '/activities/'.$this->quiz->slug;
        $this->actingAs($this->amina)->get('/dashboard')->assertDontSee('not yet submitted')->assertDontSee('unread');

        $this->actingAs($this->admin)->post($url.'/publish')->assertSessionHasNoErrors();
        $this->assertSame(1, Announcement::where('activity_id', $this->quiz->id)->count());
        $this->get('/announcements')->assertSee('New assessment: Day 1 Assessment')->assertSee('Shown to the staff assigned to this assessment');

        // Only the assigned person sees it, with a link and a count on each menu item.
        $this->actingAs($this->amina)->get('/dashboard')->assertSee('aria-label="1 unread"', false)->assertSee('aria-label="1 not yet submitted"', false);
        $this->get('/announcements')->assertSee('New assessment: Day 1 Assessment')->assertSee('href="'.$url.'"', false);
        $this->get('/dashboard')->assertDontSee('aria-label="1 unread"', false)->assertSee('aria-label="1 not yet submitted"', false);
        $this->actingAs($this->grace)->get('/announcements')->assertDontSee('Day 1 Assessment');
        $this->get('/dashboard')->assertDontSee('unread"', false)->assertDontSee('not yet submitted');

        // Submitting clears the assessment count.
        $submission = Submission::create(['activity_id' => $this->quiz->id, 'user_id' => $this->amina->id, 'attempt' => 1, 'started_at' => now()]);
        app(QuizAttemptService::class)->finalize($submission, 'manual', []);
        $this->actingAs($this->amina)->get('/dashboard')->assertDontSee('not yet submitted');

        // Unpublishing archives the announcement; publishing again brings the same one back.
        $this->actingAs($this->admin)->post($url.'/publish');
        $this->actingAs($this->amina)->get('/announcements')->assertDontSee('Day 1 Assessment');
        $this->actingAs($this->admin)->post($url.'/publish');
        $this->assertSame(1, Announcement::where('activity_id', $this->quiz->id)->where('archived', false)->count());

        // Renaming published work updates its announcement; deleting the work removes it.
        $this->post($url, ['title' => 'Day 1 Check', 'instructions' => 'Answer all', 'due_at' => now()->addDays(4)->format('Y-m-d H:i')])->assertSessionHasNoErrors();
        $this->assertSame('New assessment: Day 1 Check', Announcement::where('activity_id', $this->quiz->id)->value('title'));
        $this->delete($url, ['confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame(0, Announcement::count());
    }

    public function test_upcoming_and_closed_assessments_are_not_counted(): void
    {
        $this->quiz->update(['published' => true, 'available_at' => now()->addDay(), 'due_at' => now()->addDays(3)]);
        $this->actingAs($this->amina)->get('/dashboard')->assertDontSee('not yet submitted');
        $this->quiz->update(['available_at' => null, 'due_at' => now()->subMinute()]);
        $this->get('/dashboard')->assertDontSee('not yet submitted');
    }

    public function test_before_the_migration_publishing_still_works_without_an_announcement(): void
    {
        Announcement::$activityLinks = false;
        $this->actingAs($this->admin)->post('/activities/'.$this->quiz->slug.'/publish')->assertSessionHasNoErrors();
        $this->assertTrue($this->quiz->fresh()->published);
        $this->assertSame(0, Announcement::count());
        $this->get('/announcements')->assertOk();
        $this->actingAs($this->amina)->get('/announcements')->assertOk()->assertDontSee('Day 1 Assessment');
        $this->get('/dashboard')->assertSee('aria-label="1 not yet submitted"', false);
    }
}
