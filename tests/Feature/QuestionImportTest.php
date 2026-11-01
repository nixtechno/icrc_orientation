<?php

namespace Tests\Feature;

use App\Models\{Activity, Programme, Question, User};
use App\Services\{QuestionImport, TimetableImporter};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class QuestionImportTest extends TestCase
{
    use RefreshDatabase;

    private Activity $quiz;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp(); app(TimetableImporter::class)->run();
        $this->admin = User::factory()->create(['role' => 'admin', 'active' => true]); $this->admin->programmes()->attach(Programme::first()->id);
        $this->quiz = Activity::create(['programme_id' => Programme::first()->id, 'kind' => 'quiz', 'title' => 'Week one check', 'instructions' => 'Answer all', 'due_at' => now()->addDays(3), 'attempts' => 1, 'published' => false]);
    }

    /** A filled-in copy of the real template. */
    private function workbook(array $rows): UploadedFile
    {
        $book = app(QuestionImport::class)->template();
        $book->getSheetByName('Questions')->fromArray($rows, null, 'A2', true);
        $path = tempnam(sys_get_temp_dir(), 'questions').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'questions.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_the_template_downloads_with_headers_a_type_list_and_instructions(): void
    {
        $response = $this->actingAs($this->admin)->get('/assessments/question-template')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'template').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $book = IOFactory::load($path);
        $this->assertSame(['Questions', 'How to fill this in'], $book->getSheetNames());
        $this->assertSame(QuestionImport::COLUMNS, $book->getSheet(0)->rangeToArray('A1:J1')[0]);
        $this->assertStringContainsString('Multiple answers', $book->getSheet(0)->getCell('A2')->getDataValidation()->getFormula1());

        $staff = User::factory()->create(['role' => 'staff', 'active' => true]); $staff->programmes()->attach(Programme::first()->id);
        $this->actingAs($staff)->get('/assessments/question-template')->assertForbidden();
    }

    public function test_a_clean_file_is_previewed_then_added_with_letters_and_text_answers_resolved(): void
    {
        $url = '/activities/'.$this->quiz->slug;
        $file = $this->workbook([
            ['Multiple choice', 'Capital of Nigeria?', 'Lagos', 'Abuja', 'Kano', '', '', '', 'b', 2],
            ['Multiple answers', 'Which are PPP models?', 'BOT', 'Sole proprietorship', 'Concession', '', '', '', 'A; concession', 3],
            ['True/False', 'ICRC regulates concessions.', '', '', '', '', '', '', 'true', ''],
            ['Short answer', 'Name one benefit of PPPs.', '', '', '', '', '', '', '', 5],
            ['', '', '', '', '', '', '', '', '', ''],
        ]);
        $this->actingAs($this->admin)->post($url.'/questions/import', ['file' => $file])->assertRedirect($url.'#question-import')->assertSessionHas('success', '4 questions are ready. Check them below, then add them.');
        $this->assertSame(0, Question::count());
        $this->get($url)->assertSee('4 questions ready to add')->assertSee('Capital of Nigeria?')->assertSee('BOT, Concession')->assertSee('Add these 4 questions');

        $this->post($url.'/questions/import/confirm')->assertRedirect($url)->assertSessionHas('success', '4 questions added from Excel.');
        $questions = Question::orderBy('id')->get();
        $this->assertSame(['multiple_choice', 'multiple_answers', 'true_false', 'short_answer'], $questions->pluck('type')->all());
        $this->assertSame(['Lagos', 'Abuja', 'Kano'], $questions[0]->options);
        $this->assertSame('Abuja', $questions[0]->correct_answer);
        $this->assertSame(['BOT', 'Concession'], $questions[1]->correctAnswers());
        $this->assertSame(['True', 'False', 'True', 1], [...$questions[2]->options, $questions[2]->correct_answer, $questions[2]->points]);
        $this->assertNull($questions[3]->correct_answer);
        $this->assertSame([2, 3, 1, 5], $questions->pluck('points')->all());
        $this->post($url.'/questions/import/confirm')->assertStatus(422);
        $this->assertSame(4, Question::count());
    }

    public function test_problem_rows_are_listed_by_row_number_and_nothing_is_added(): void
    {
        $url = '/activities/'.$this->quiz->slug;
        $file = $this->workbook([
            ['Multiple choice', 'Fine question', 'Yes', 'No', '', '', '', '', 'A', 1],
            ['Multiple choice', 'Wrong answer', 'Yes', 'No', '', '', '', '', 'D', 1],
            ['Quiz', 'Bad type', '', '', '', '', '', '', '', 1],
            ['Multiple answers', 'Only one right', 'A1', 'B1', '', '', '', '', 'A', 1],
            ['True/False', 'Missing answer', '', '', '', '', '', '', '', 200],
        ]);
        $this->actingAs($this->admin)->from($url)->post($url.'/questions/import', ['file' => $file])->assertRedirect($url)
            ->assertSessionHasErrors(['file' => 'Row 3: The correct answer "D" does not match any option. Use a letter (A–F) or the exact option text.']);
        $errors = session('errors')->get('file');
        $this->assertCount(4, $errors);
        $this->assertStringStartsWith('Row 4: Unknown type "Quiz"', $errors[1]);
        $this->assertStringStartsWith('Row 5: Multiple answers needs at least two', $errors[2]);
        $this->assertStringStartsWith('Row 6: Points must be', $errors[3]);
        $this->assertSame(0, Question::count());
        $this->get($url)->assertSee('No questions were added')->assertDontSee('ready to add');
    }

    public function test_cancel_clears_the_preview_and_published_assessments_are_locked(): void
    {
        $url = '/activities/'.$this->quiz->slug;
        $this->actingAs($this->admin)->post($url.'/questions/import', ['file' => $this->workbook([['Short answer', 'Why?', '', '', '', '', '', '', '', 1]])]);
        $this->post($url.'/questions/import/cancel')->assertRedirect($url);
        $this->post($url.'/questions/import/confirm')->assertStatus(422);
        $this->assertSame(0, Question::count());

        $this->post($url.'/questions/import', ['file' => UploadedFile::fake()->create('questions.csv', 1, 'text/csv')])->assertSessionHasErrors('file');
        $this->quiz->update(['published' => true]);
        $this->post($url.'/questions/import', ['file' => $this->workbook([['Short answer', 'Why?', '', '', '', '', '', '', '', 1]])])->assertStatus(422);
    }
}
