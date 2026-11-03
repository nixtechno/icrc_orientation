<?php

namespace Tests\Feature;

use App\Models\{Facilitator, Programme, ProgrammeDay, ProgrammeSession, TrainingFeedback, User};
use App\Services\TimetableImporter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrainingFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private ProgrammeDay $day;

    protected function setUp(): void
    {
        parent::setUp(); app(TimetableImporter::class)->run();
        TrainingFeedback::$ready = null;
        $programme = Programme::first();
        $this->travelTo(Carbon::parse('2026-11-03 09:00', $programme->timezone));
        // Today is a programme day, with a facilitated session.
        $this->day = ProgrammeDay::whereHas('week', fn ($q) => $q->where('programme_id', $programme->id))->orderBy('date')->firstOrFail();
        ProgrammeDay::whereHas('week', fn ($q) => $q->where('programme_id', $programme->id))->whereDate('date', '2026-11-03')->whereKeyNot($this->day->id)->update(['date' => '2030-01-01']);
        $this->day->update(['date' => '2026-11-03', 'cancelled' => false]);
        $session = ProgrammeSession::where('programme_day_id', $this->day->id)->first() ?? ProgrammeSession::first()->replicate()->fill(['programme_day_id' => $this->day->id]);
        $session->programme_day_id = $this->day->id; $session->save();
        Facilitator::create(['programme_id' => $programme->id, 'name' => 'Dr Ada Obi'])->sessions()->attach($session->id);
        $this->staff = User::factory()->create(['role' => 'staff', 'active' => true]); $this->staff->programmes()->attach($programme->id);
    }

    private function ratings(array $overrides = []): array
    {
        return ['overall_rating' => 4, 'facilitator_rating' => 5, 'relevance_rating' => 4, 'understanding_rating' => 3, 'infrastructure_rating' => 2, 'food_rating' => 3, ...$overrides];
    }

    private function at(string $time): void
    {
        $this->travelTo(Carbon::parse('2026-11-03 '.$time, Programme::first()->timezone));
    }

    public function test_the_form_is_on_every_portal_page_and_opens_at_the_feedback_time(): void
    {
        $page = $this->actingAs($this->staff)->get('/dashboard')->assertOk()->assertSee('data-feedback-dialog', false)
            ->assertSee('data-opens-at="'.Carbon::parse('2026-11-03 16:30', Programme::first()->timezone)->toIso8601String().'"', false)
            ->assertDontSee('data-feedback-close', false)->assertDontSee('data-feedback-later', false)->assertDontSee('data-feedback-reopen', false)
            ->assertSeeInOrder(['Daily training feedback', 'Tuesday 3 November 2026', 'Your feedback is very important to us.', 'Estimated completion time: 2 minutes', 'Overall session', 'Instructor', 'Dr Ada Obi', 'Relevance to your job', 'Understanding', 'ICT and facilities', 'Food and refreshments', 'What can we do to improve your training experience?']);

        // Too early: nothing is accepted yet.
        $this->post('/training-feedback', $this->ratings())->assertStatus(422);
        $this->assertSame(0, TrainingFeedback::count());

        $this->at('16:31');
        $this->from('/dashboard')->post('/training-feedback', [...$this->ratings(), 'comment' => '  More breaks, please. '])
            ->assertRedirect('/dashboard')->assertSessionHas('success');
        $feedback = TrainingFeedback::sole();
        $this->assertSame([$this->day->id, 4, 5, 4, 3, 2, 3, 'More breaks, please.'], [$feedback->programme_day_id, $feedback->overall_rating, $feedback->facilitator_rating, $feedback->relevance_rating, $feedback->understanding_rating, $feedback->infrastructure_rating, $feedback->food_rating, $feedback->comment]);

        // Answered: not asked again today, and a second answer is refused.
        $this->get('/dashboard')->assertDontSee('data-feedback-dialog', false);
        $this->post('/training-feedback', $this->ratings(['food_rating' => 1]))->assertStatus(422);
        $this->assertSame(3, $feedback->fresh()->food_rating);

        // The next programme day asks again.
        ProgrammeDay::whereHas('week', fn ($q) => $q->where('programme_id', Programme::first()->id))->where('id', '!=', $this->day->id)->orderBy('date')->firstOrFail()->update(['date' => '2026-11-04', 'cancelled' => false]);
        $this->travelTo(Carbon::parse('2026-11-04 17:00', Programme::first()->timezone));
        $this->get('/dashboard')->assertSee('data-feedback-dialog', false);
    }

    public function test_only_staff_on_training_days_are_asked(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]); $admin->programmes()->attach(Programme::first()->id);
        $this->at('17:00');
        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertDontSee('data-feedback-dialog', false);
        $this->post('/training-feedback', $this->ratings())->assertStatus(422);

        $this->day->update(['cancelled' => true]);
        $this->actingAs($this->staff)->get('/dashboard')->assertOk()->assertDontSee('data-feedback-dialog', false);
        $this->post('/training-feedback', $this->ratings())->assertStatus(422);
    }

    public function test_every_rating_is_required_and_between_one_and_five(): void
    {
        $this->at('17:00');
        $this->actingAs($this->staff)->post('/training-feedback', $this->ratings(['relevance_rating' => 6, 'food_rating' => null]))
            ->assertSessionHasErrors(['relevance_rating', 'food_rating']);
        $this->assertSame(0, TrainingFeedback::count());
    }

    public function test_admins_set_the_time_in_data_tools(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]); $admin->programmes()->attach(Programme::first()->id);
        $this->actingAs($admin)->get('/settings?tab=tools')->assertOk()->assertSee('Daily training feedback time')->assertSee('value="16:30"', false);
        $this->post('/settings/tools/feedback-time', ['time' => '25:00'])->assertSessionHasErrors('time');
        $this->post('/settings/tools/feedback-time', ['time' => '15:15'])->assertSessionHas('success', 'Daily training feedback now opens at 15:15.');
        $this->assertSame('15:15', Programme::first()->settings['feedback_time']);

        $this->actingAs($this->staff)->post('/settings/tools/feedback-time', ['time' => '09:00'])->assertForbidden();
        $this->get('/dashboard')->assertSee('data-opens-at="'.Carbon::parse('2026-11-03 15:15', Programme::first()->timezone)->toIso8601String().'"', false);
        $this->at('15:20');
        $this->post('/training-feedback', $this->ratings())->assertSessionHasNoErrors();
    }

    public function test_admins_can_clear_the_cache_from_data_tools(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]); $admin->programmes()->attach(Programme::first()->id);
        $this->actingAs($this->staff)->post('/settings/tools/cache')->assertForbidden();
        $this->actingAs($admin)->get('/settings?tab=tools')->assertSee('Clear the cache');
        $this->post('/settings/tools/cache')->assertRedirect('/settings?tab=tools')->assertSessionHas('success');
    }

    public function test_managers_see_everyone_with_their_ratings_and_suggestions_one_day_per_page(): void
    {
        $other = User::factory()->create(['role' => 'staff', 'active' => true, 'name' => 'Grace Okafor']); $other->programmes()->attach(Programme::first()->id);
        TrainingFeedback::create(['programme_id' => Programme::first()->id, 'programme_day_id' => $this->day->id, 'user_id' => $this->staff->id, ...$this->ratings(), 'comment' => 'Slides were clear.']);
        TrainingFeedback::create(['programme_id' => Programme::first()->id, 'programme_day_id' => $this->day->id, 'user_id' => $other->id, ...$this->ratings(['facilitator_rating' => 4])]);

        $this->actingAs($this->staff)->get('/training-feedback')->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]); $admin->programmes()->attach(Programme::first()->id);
        $this->actingAs($admin)->get('/reports')->assertSee('href="/training-feedback"', false);
        $page = $this->get('/training-feedback')->assertOk()->assertSee('Tuesday 3 November 2026')->assertSee('2 responses')
            ->assertSeeInOrder(['Overall session', '4.0', 'Instructor', '4.5', 'Food and refreshments', '3.0'])->assertSee('Slides were clear.')->getContent();
        // Everyone is listed by name, with each rating and their suggestion.
        $this->assertMatchesRegularExpression('/Grace Okafor.*?is-4">4<.*?—.*?'.preg_quote($this->staff->name, '/').'.*?is-5">5<.*?Slides were clear\./s', substr($page, strpos($page, 'feedback-table')));

        // Each programme day is its own page.
        $later = ProgrammeDay::whereHas('week', fn ($q) => $q->where('programme_id', Programme::first()->id))->whereKeyNot($this->day->id)->orderBy('date')->firstOrFail();
        $later->update(['date' => '2026-11-04']);
        TrainingFeedback::create(['programme_id' => Programme::first()->id, 'programme_day_id' => $later->id, 'user_id' => $other->id, ...$this->ratings(), 'comment' => 'Lunch was late.']);
        $this->get('/training-feedback')->assertSee('Wednesday 4 November 2026')->assertSee('Lunch was late.')->assertDontSee('Slides were clear.')->assertSee('/training-feedback?day='.$this->day->id, false);
        $this->get('/training-feedback?day='.$this->day->id)->assertSee('Slides were clear.')->assertDontSee('Lunch was late.');
    }
}
