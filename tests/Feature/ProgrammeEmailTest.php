<?php

namespace Tests\Feature;

use App\Models\{Activity, Programme, ProgrammeSession, SessionQuestion, User};
use App\Notifications\ProgrammeUpdate;
use App\Services\TimetableImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProgrammeEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); app(TimetableImporter::class)->run(); Notification::fake();
    }

    private function user(string $role = 'staff', array $extra = []): User
    {
        $user = User::factory()->create(['role' => $role, 'active' => true, ...$extra]);
        $user->programmes()->attach(Programme::first()->id);
        return $user;
    }

    private function sent(User $user, string $subject): int
    {
        return Notification::sent($user, ProgrammeUpdate::class)->filter(fn ($n) => $n->title === $subject)->count();
    }

    public function test_assigned_staff_are_emailed_once_when_work_is_published_and_when_added_later(): void
    {
        $admin = $this->user('admin'); $amina = $this->user(); $grace = $this->user(); $inactive = $this->user('staff', ['active' => false]); $outsider = $this->user();
        $activity = Activity::create(['programme_id' => Programme::first()->id, 'kind' => 'presentation', 'title' => 'Week one presentation', 'instructions' => 'Present', 'due_at' => now()->addDays(5), 'attempts' => 1, 'published' => false, 'assignment_mode' => 'selected']);
        $activity->participants()->sync([$amina->id, $inactive->id]);
        $url = '/activities/'.$activity->slug;

        // Drafts send nothing, even when people are assigned.
        $this->actingAs($admin)->post($url.'/assignment', ['assignment_mode' => 'selected', 'participants' => [$amina->id, $inactive->id]])->assertSessionHasNoErrors();
        Notification::assertNothingSent();

        $this->post($url.'/publish')->assertSessionHas('success', 'Publication updated. 1 assigned staff was emailed.');
        $this->assertSame(1, $this->sent($amina, 'New presentation: Week one presentation'));
        Notification::assertNotSentTo([$inactive, $outsider, $grace, $admin], ProgrammeUpdate::class);
        $mail = Notification::sent($amina, ProgrammeUpdate::class)->first()->toMail($amina);
        $this->assertSame('New presentation: Week one presentation', $mail->subject);
        $this->assertStringContainsString('It is due', implode(' ', $mail->introLines));
        $this->assertSame(url($url), $mail->actionUrl);

        // Unpublishing and publishing again does not repeat the email; adding someone emails only them.
        $this->post($url.'/publish'); $this->post($url.'/publish');
        $this->post($url.'/assignment', ['assignment_mode' => 'selected', 'participants' => [$amina->id, $grace->id]])->assertSessionHas('success', fn ($m) => str_contains($m, '1 newly assigned person was emailed.'));
        $this->assertSame(1, $this->sent($amina, 'New presentation: Week one presentation'));
        $this->assertSame(1, $this->sent($grace, 'New presentation: Week one presentation'));
    }

    public function test_materials_email_only_when_ticked_and_published_and_announcements_always_email(): void
    {
        $admin = $this->user('admin'); $staff = $this->user();
        $this->actingAs($admin)->post('/resources', ['title' => 'Quiet upload', 'url' => 'https://example.com/a', 'published' => 1])->assertSessionHasNoErrors();
        $this->post('/resources', ['title' => 'Draft with email', 'url' => 'https://example.com/b', 'email_staff' => 1])->assertSessionHasNoErrors();
        Notification::assertNothingSent();
        $this->post('/resources', ['title' => 'Staff handbook', 'url' => 'https://example.com/c', 'published' => 1, 'email_staff' => 1, 'week' => 2])->assertSessionHas('success', 'Resource saved. 1 staff were emailed.');
        $this->assertSame(1, $this->sent($staff, 'New learning material: Staff handbook'));
        Notification::assertNotSentTo($admin, ProgrammeUpdate::class);

        $this->post('/announcements', ['title' => 'Venue change', 'body' => 'We move to Hall B.', 'published_at' => now()->toDateTimeString()])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->sent($staff, 'Venue change'));
        $this->assertSame(['mail'], Notification::sent($staff, ProgrammeUpdate::class)->first()->via($staff));
    }

    public function test_timetable_changes_and_session_questions_do_not_email(): void
    {
        $staff = $this->user(); $session = ProgrammeSession::where('status', 'published')->firstOrFail();
        $this->actingAs($staff)->post('/qa/sessions/'.$session->getRouteKey().'/questions', ['body' => 'When is lunch?']);
        $this->assertSame(1, SessionQuestion::count());
        Notification::assertNothingSent();
    }
}
