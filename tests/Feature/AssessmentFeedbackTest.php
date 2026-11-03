<?php

namespace Tests\Feature;

use App\Models\{Activity, AssessmentFeedback, Facilitator, Programme, ProgrammeSession, Question, Submission, User};
use App\Services\{QuizAttemptService, TimetableImporter};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssessmentFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Activity $quiz;

    protected function setUp(): void
    {
        parent::setUp(); app(TimetableImporter::class)->run();
        AssessmentFeedback::$ready = null;
        $this->staff = User::factory()->create(['role' => 'staff', 'active' => true]); $this->staff->programmes()->attach(Programme::first()->id);
        $session = ProgrammeSession::first();
        $facilitator = Facilitator::create(['programme_id' => Programme::first()->id, 'name' => 'Dr Ada Obi']);
        $facilitator->sessions()->attach($session->id);
        $this->quiz = Activity::create(['programme_id' => Programme::first()->id, 'kind' => 'quiz', 'title' => 'Day 1 Assessment', 'instructions' => 'Answer all', 'due_at' => now()->addDays(3), 'attempts' => 1, 'published' => true, 'session_id' => $session->id]);
        $this->quiz->participants()->attach($this->staff->id);
        Question::create(['activity_id' => $this->quiz->id, 'type' => 'true_false', 'prompt' => 'PPPs share risk.', 'options' => ['True', 'False'], 'correct_answer' => 'True', 'points' => 1]);
    }

    private function submit(): void
    {
        $submission = Submission::create(['activity_id' => $this->quiz->id, 'user_id' => $this->staff->id, 'attempt' => 1, 'started_at' => now()]);
        app(QuizAttemptService::class)->finalize($submission->load('activity.questions'), 'manual', [$this->quiz->questions()->value('id') => 'True']);
    }

    private function ratings(array $overrides = []): array
    {
        return ['facilitator_rating' => 5, 'content_rating' => 4, 'infrastructure_rating' => 2, 'portal_rating' => 3, ...$overrides];
    }

    public function test_the_feedback_dialog_appears_only_after_submitting_and_names_the_facilitator(): void
    {
        $this->actingAs($this->staff)->get('/activities/'.$this->quiz->slug)->assertOk()->assertDontSee('data-feedback-dialog', false);
        $this->post('/activities/'.$this->quiz->slug.'/feedback', $this->ratings())->assertStatus(422);

        $this->submit();
        $this->get('/activities/'.$this->quiz->slug)->assertOk()->assertSee('data-feedback-dialog', false)
            ->assertSeeInOrder(['Assessment submitted', 'Thank you for completing', 'Your score', '1<small> / 1</small>', 'Facilitator', 'Dr Ada Obi', 'Content of material', 'IT infrastructure', 'Portal usage', 'anything else you would like to tell the organisers'])->assertDontSee('data-feedback-later', false);
    }

    public function test_staff_give_feedback_once_and_are_not_asked_again(): void
    {
        $this->submit();
        $this->actingAs($this->staff)->post('/activities/'.$this->quiz->slug.'/feedback', [...$this->ratings(), 'comment' => '  Projector kept cutting out. '])
            ->assertRedirect('/activities/'.$this->quiz->slug)->assertSessionHas('success', 'Thank you. Your feedback has been recorded.');

        $feedback = AssessmentFeedback::sole();
        $this->assertSame([5, 4, 2, 3], [$feedback->facilitator_rating, $feedback->content_rating, $feedback->infrastructure_rating, $feedback->portal_rating]);
        $this->assertSame('Projector kept cutting out.', $feedback->comment);

        $this->post('/activities/'.$this->quiz->slug.'/feedback', $this->ratings(['portal_rating' => 1]))->assertSessionHas('success', 'You have already given feedback on this assessment.');
        $this->assertSame(3, $feedback->fresh()->portal_rating);
        $this->get('/activities/'.$this->quiz->slug)->assertDontSee('data-feedback-dialog', false);
    }

    public function test_every_rating_is_required_and_between_one_and_five(): void
    {
        $this->submit();
        $this->actingAs($this->staff)->post('/activities/'.$this->quiz->slug.'/feedback', $this->ratings(['content_rating' => 6, 'portal_rating' => null]))
            ->assertSessionHasErrors(['content_rating', 'portal_rating']);
        $this->assertSame(0, AssessmentFeedback::count());
    }

    public function test_managers_see_averages_and_comments_without_names(): void
    {
        $this->submit();
        $this->actingAs($this->staff)->post('/activities/'.$this->quiz->slug.'/feedback', [...$this->ratings(), 'comment' => 'Slides were clear.']);
        $other = User::factory()->create(['role' => 'staff', 'active' => true, 'name' => 'Grace Okafor']);
        AssessmentFeedback::create(['activity_id' => $this->quiz->id, 'user_id' => $other->id, ...$this->ratings(['facilitator_rating' => 4])]);

        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $page = $this->actingAs($admin)->get('/activities/'.$this->quiz->slug)->assertOk()
            ->assertSee('Staff feedback')->assertSee('2 responses')->assertSeeInOrder(['Facilitator', '4.5', 'Content of material', '4.0'])
            ->assertSee('Slides were clear.')->getContent();
        $this->assertStringNotContainsString('Grace Okafor', substr($page, strpos($page, 'Staff feedback')));
    }

    public function test_managers_cannot_submit_feedback(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->actingAs($admin)->post('/activities/'.$this->quiz->slug.'/feedback', $this->ratings())->assertForbidden();
    }
}
