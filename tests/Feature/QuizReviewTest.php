<?php

namespace Tests\Feature;

use App\Models\{Activity, Programme, Question, Submission, User};
use App\Services\{QuestionImport, QuizAttemptService, TimetableImporter};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class QuizReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp(); app(TimetableImporter::class)->run();
        Question::$explanations = null;
        $this->staff = User::factory()->create(['role' => 'staff', 'active' => true]); $this->staff->programmes()->attach(Programme::first()->id);
    }

    protected function tearDown(): void
    {
        Question::$explanations = null;
        parent::tearDown();
    }

    private function quiz(array $questions, string $title = 'Day 1 Assessment'): Activity
    {
        $quiz = Activity::create(['programme_id' => Programme::first()->id, 'kind' => 'quiz', 'title' => $title, 'instructions' => 'Answer all', 'due_at' => now()->addDays(3), 'attempts' => 1, 'published' => true]);
        $quiz->participants()->attach($this->staff->id);
        foreach ($questions as $question) Question::create(['activity_id' => $quiz->id, 'points' => 1, ...$question]);

        return $quiz->load('questions');
    }

    private function submit(Activity $quiz, array $answers): Submission
    {
        $submission = Submission::create(['activity_id' => $quiz->id, 'user_id' => $this->staff->id, 'attempt' => 1, 'started_at' => now()]);

        return app(QuizAttemptService::class)->finalize($submission, 'manual', $answers);
    }

    public function test_released_results_show_the_score_but_only_the_staff_members_own_answers(): void
    {
        $quiz = $this->quiz([
            ['type' => 'multiple_choice', 'prompt' => 'Which body regulates concessions?', 'options' => ['ICRC', 'CBN', 'NCC'], 'correct_answer' => 'ICRC', 'points' => 2, 'explanation' => 'ICRC was set up to regulate concessions.'],
            ['type' => 'multiple_choice', 'prompt' => 'What does PPP stand for?', 'options' => ['Public-private partnership', 'Private purchase plan'], 'correct_answer' => 'Public-private partnership'],
            ['type' => 'multiple_answers', 'prompt' => 'Which are PPP models?', 'options' => ['BOT', 'Concession', 'Grant'], 'correct_answer' => json_encode(['BOT', 'Concession'])],
            ['type' => 'true_false', 'prompt' => 'Handback alone makes a PPP.', 'options' => ['True', 'False'], 'correct_answer' => 'False'],
        ]);
        [$first, $second, $third] = $quiz->questions;
        $this->submit($quiz, [$first->id => 'ICRC', $second->id => 'Private purchase plan', $third->id => ['Concession', 'BOT']]);

        $this->actingAs($this->staff)->get('/activities?kind=quiz')->assertOk()->assertSeeInOrder(['class="activity-card-score"', '3', '/5', '60%'], false);

        $page = $this->get('/activities/'.$quiz->slug)->assertOk()->getContent();
        $this->assertSame(3, substr_count($page, 'quiz-review-tile is-answered'));
        $this->assertSame(1, substr_count($page, 'quiz-review-tile is-unanswered'));
        $this->assertStringContainsString('Your answer', $page);
        foreach (['is-correct', 'is-wrong', 'Correct answer', 'Incorrect', 'ICRC was set up to regulate concessions.', 'quiz-review-explanation'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $page);
        }
        $this->assertStringNotContainsString('<h2>Instructions</h2>', $page);
    }

    public function test_each_attempt_gets_its_own_question_order_that_stays_the_same(): void
    {
        $quiz = $this->quiz(array_map(fn ($n) => ['type' => 'true_false', 'prompt' => 'Question '.$n, 'options' => ['True', 'False'], 'correct_answer' => 'True'], range(1, 10)));
        $other = User::factory()->create(['role' => 'staff', 'active' => true]);
        [$a, $b] = [Submission::create(['activity_id' => $quiz->id, 'user_id' => $this->staff->id, 'attempt' => 1, 'started_at' => now()]), Submission::create(['activity_id' => $quiz->id, 'user_id' => $other->id, 'attempt' => 1, 'started_at' => now()])];
        $order = fn ($s) => $s->shuffled($quiz->questions)->pluck('id')->all();

        $this->assertNotSame($order($a), $order($b));
        $this->assertSame($order($a), $order($a->fresh()));
        $this->assertEqualsCanonicalizing($quiz->questions->pluck('id')->all(), $order($a));
        $this->assertSame($order($a), array_map(fn ($item) => $item['question']->id, app(QuizAttemptService::class)->review($quiz, $a)));

        $choice = Question::create(['activity_id' => $quiz->id, 'type' => 'multiple_choice', 'prompt' => 'Pick one', 'options' => range('A', 'H'), 'correct_answer' => 'A', 'points' => 1]);
        $this->assertNotSame($a->shuffledOptions($choice), $b->shuffledOptions($choice));
        $this->assertSame($a->shuffledOptions($choice), $a->fresh()->shuffledOptions($choice));
        $this->assertEqualsCanonicalizing(range('A', 'H'), $a->shuffledOptions($choice));
        $this->assertSame(['True', 'False'], $a->shuffledOptions($quiz->questions[0]));
    }

    public function test_releasing_answers_shows_them_with_explanations_and_tells_staff(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]); $admin->programmes()->attach(Programme::first()->id);
        $quiz = $this->quiz([['type' => 'multiple_choice', 'prompt' => 'Which body regulates concessions?', 'options' => ['ICRC', 'CBN'], 'correct_answer' => 'ICRC', 'explanation' => 'ICRC regulates concessions.']]);
        $submission = Submission::create(['activity_id' => $quiz->id, 'user_id' => $this->staff->id, 'attempt' => 1, 'started_at' => now()]);
        app(QuizAttemptService::class)->finalize($submission, 'manual', [$quiz->questions[0]->id => 'CBN']);
        $submission->update(['released_at' => null]);
        $page = '/activities/'.$quiz->slug;

        $this->actingAs($this->staff)->get($page)->assertDontSee('ICRC regulates concessions.')->assertDontSee('Correct answer');
        $this->post($page.'/release-answers')->assertForbidden();

        $this->actingAs($admin)->get($page)->assertSee('Release answers');
        $this->post($page.'/release-answers')->assertSessionHas('success');
        $this->assertNotNull($quiz->fresh()->answers_released_at);
        $this->assertNotNull($submission->fresh()->released_at);
        \Illuminate\Support\Facades\Notification::assertSentTo($this->staff, \App\Notifications\ProgrammeUpdate::class, fn ($n) => str_starts_with($n->title, 'Answers released'));
        $this->assertSame('Answers released: '.$quiz->title, \App\Models\Announcement::where('activity_id', $quiz->id)->value('title'));

        $this->actingAs($this->staff)->get($page)->assertSee('ICRC regulates concessions.')->assertSee('Correct answer')->assertSee('Your answer · Incorrect');

        $this->actingAs($admin)->post($page.'/release-answers')->assertSessionHas('success');
        $this->actingAs($this->staff)->get($page)->assertDontSee('ICRC regulates concessions.');
    }

    public function test_managers_can_reset_a_persons_attempts_so_they_can_take_it_again(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]); $admin->programmes()->attach(Programme::first()->id);
        $quiz = $this->quiz([['type' => 'true_false', 'prompt' => 'Retake me', 'options' => ['True', 'False'], 'correct_answer' => 'True']]);
        $this->submit($quiz, [$quiz->questions[0]->id => 'False']);
        $reset = '/activities/'.$quiz->slug.'/reset/'.$this->staff->getRouteKey();

        $this->actingAs($this->staff)->post($reset)->assertForbidden();
        $this->actingAs($admin)->get('/activities/'.$quiz->slug)->assertSee($reset, false);
        $this->post($reset)->assertSessionHas('success');
        $this->assertSame(0, $quiz->submissions()->count());

        $this->actingAs($this->staff)->post('/activities/'.$quiz->slug.'/start')->assertRedirect();
        $this->assertSame(1, $quiz->submissions()->count());
    }

    public function test_results_awaiting_marking_hide_correct_answers_and_explanations(): void
    {
        $quiz = $this->quiz([
            ['type' => 'multiple_choice', 'prompt' => 'Which body regulates concessions?', 'options' => ['ICRC', 'CBN'], 'correct_answer' => 'ICRC', 'explanation' => 'Hidden until release.'],
            ['type' => 'short_answer', 'prompt' => 'Name one PPP benefit.', 'options' => [], 'correct_answer' => null],
        ]);
        [$choice, $written] = $quiz->questions;
        $this->submit($quiz, [$choice->id => 'CBN', $written->id => 'Faster delivery']);

        $this->actingAs($this->staff)->get('/activities?kind=quiz')->assertSee('Awaiting marking')->assertDontSee('<small>/', false);
        $this->get('/activities/'.$quiz->slug)->assertOk()
            ->assertSee('Your answers are with a facilitator for marking.')->assertSee('<h2>Instructions</h2>', false)->assertSee('Faster delivery')->assertSee('quiz-review-tile is-answered', false)
            ->assertDontSee('Correct answer')->assertDontSee('Hidden until release.')->assertDontSee('is-wrong', false);
    }

    public function test_reviewers_cannot_change_automatic_marks_and_only_award_written_marks(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]); $admin->programmes()->attach(Programme::first()->id);
        $auto = $this->quiz([['type' => 'true_false', 'prompt' => 'Auto only', 'options' => ['True', 'False'], 'correct_answer' => 'True', 'points' => 2]], 'Auto');
        $autoSubmission = $this->submit($auto, [$auto->questions[0]->id => 'False']);
        $this->actingAs($admin)->get('/activities/'.$auto->slug)->assertSee('Marked automatically: 0 / 2')->assertDontSee('name="score"', false)->assertDontSee('name="written_score"', false);
        $this->post('/submissions/'.$autoSubmission->slug.'/review', ['score' => 2, 'feedback' => 'Good effort', 'release' => 1])->assertSessionHasNoErrors();
        $this->assertSame([0.0, 'Good effort'], [(float) $autoSubmission->fresh()->score, $autoSubmission->fresh()->feedback]);

        $mixed = $this->quiz([
            ['type' => 'true_false', 'prompt' => 'Auto part', 'options' => ['True', 'False'], 'correct_answer' => 'True', 'points' => 2],
            ['type' => 'long_answer', 'prompt' => 'Written part', 'options' => [], 'correct_answer' => null, 'points' => 5],
        ], 'Mixed');
        $mixedSubmission = $this->submit($mixed, [$mixed->questions[0]->id => 'True', $mixed->questions[1]->id => 'An answer']);
        $this->get('/activities/'.$mixed->slug)->assertSee('Marked automatically: 2 / 2')->assertSee('Marks for written answers (out of 5)');
        $url = '/submissions/'.$mixedSubmission->slug.'/review';
        $this->post($url, ['written_score' => 6, 'release' => 1])->assertSessionHasErrors('written_score');
        $this->post($url, ['score' => 100, 'written_score' => 3.5, 'release' => 1])->assertSessionHasNoErrors();
        $this->assertSame(5.5, (float) $mixedSubmission->fresh()->score);
        $this->get('/activities/'.$mixed->slug)->assertSee('value="3.5"', false);
    }

    public function test_explanations_are_optional_and_nothing_breaks_before_the_migration(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]); $admin->programmes()->attach(Programme::first()->id);
        $draft = Activity::create(['programme_id' => Programme::first()->id, 'kind' => 'quiz', 'title' => 'Draft', 'instructions' => 'Answer', 'due_at' => now()->addDay(), 'attempts' => 1, 'published' => false]);
        $url = '/activities/'.$draft->slug;
        $this->actingAs($admin)->get($url)->assertSee('Explanation (optional)');
        $this->post($url.'/questions', ['type' => 'true_false', 'prompt' => 'With explanation', 'correct_answer' => 'True', 'points' => 1, 'explanation' => 'Because.'])->assertSessionHasNoErrors();
        $this->post($url.'/questions', ['type' => 'true_false', 'prompt' => 'Without explanation', 'correct_answer' => 'False', 'points' => 1])->assertSessionHasNoErrors();
        $this->assertSame(['Because.', null], Question::orderBy('id')->pluck('explanation')->all());

        // The Excel explanation column is read, and is optional.
        $book = app(QuestionImport::class)->template();
        $book->getSheetByName('Questions')->fromArray([['True/False', 'Imported', '', '', '', '', '', '', 'True', 1, 'From Excel.']], null, 'A2', true);
        $path = tempnam(sys_get_temp_dir(), 'questions').'.xlsx'; (new Xlsx($book))->save($path);
        $this->post($url.'/questions/import', ['file' => new UploadedFile($path, 'q.xlsx', null, null, true)])->assertSessionHasNoErrors();
        $this->post($url.'/questions/import/confirm')->assertSessionHasNoErrors();
        $this->assertSame('From Excel.', Question::latest('id')->first()->explanation);

        // Before the migration (as the column check reports it): forms hide the field, saving leaves it out, and pages still render.
        Question::$explanations = false;
        $this->get($url)->assertOk()->assertDontSee('Explanation (optional)')->assertDontSee('Because.');
        $this->post($url.'/questions', ['type' => 'true_false', 'prompt' => 'Before migration', 'correct_answer' => 'True', 'points' => 1, 'explanation' => 'Ignored.'])->assertSessionHasNoErrors();
        $this->post($url.'/questions/import', ['file' => new UploadedFile($path, 'q.xlsx', null, null, true)])->assertSessionHasNoErrors();
        $this->post($url.'/questions/import/confirm')->assertSessionHasNoErrors();
        $this->assertSame(5, Question::where('activity_id', $draft->id)->count());
        $this->assertSame([null, null], Question::where('activity_id', $draft->id)->latest('id')->take(2)->pluck('explanation')->all());

        $quiz = $this->quiz([['type' => 'true_false', 'prompt' => 'Old schema question', 'options' => ['True', 'False'], 'correct_answer' => 'True']], 'Old schema');
        $this->submit($quiz, [$quiz->questions[0]->id => 'True']);
        $this->actingAs($this->staff)->get('/activities/'.$quiz->slug)->assertOk()->assertSee('Old schema question')->assertDontSee('class="quiz-review-explanation"', false);
    }
}
