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

    public function test_released_results_show_the_score_on_the_card_and_mark_each_question(): void
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
        $this->assertStringContainsString('quiz-review-tile is-correct', $page);
        $this->assertSame(2, substr_count($page, 'quiz-review-tile is-correct'));
        $this->assertSame(1, substr_count($page, 'quiz-review-tile is-wrong'));
        $this->assertSame(1, substr_count($page, 'quiz-review-tile is-unanswered'));
        $this->assertStringContainsString('Your answer · Incorrect', $page);
        $this->assertStringContainsString('Correct answer', $page);
        $this->assertStringContainsString('ICRC was set up to regulate concessions.', $page);
        $this->assertSame(1, substr_count($page, 'class="quiz-review-explanation"'));
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
            ->assertSee('Your answers are with a facilitator for marking.')->assertSee('Faster delivery')->assertSee('quiz-review-tile is-answered', false)
            ->assertDontSee('Correct answer')->assertDontSee('Hidden until release.')->assertDontSee('is-wrong', false);
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
