<?php

namespace Tests\Feature;

use App\Models\{Activity, DailyAttendance, Programme, ProgrammeDay, Question, Submission, User};
use App\Services\TimetableImporter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_managers_open_a_staff_members_report_card_with_their_recorded_figures(): void
    {
        app(TimetableImporter::class)->run();
        $programme = Programme::first();
        $this->travelTo(Carbon::parse('2026-11-04 18:00', $programme->timezone));
        $staff = User::factory()->create(['role' => 'staff', 'active' => true, 'name' => 'Adaeze Okonkwo', 'staff_number' => 'ICRC/2026/0417', 'department' => 'PPP Development']);
        $staff->programmes()->attach($programme->id, ['created_at' => '2026-10-20', 'updated_at' => '2026-10-20']);
        $other = User::factory()->create(['role' => 'staff', 'active' => true]); $other->programmes()->attach($programme->id);
        $day = ProgrammeDay::whereHas('week', fn ($q) => $q->where('programme_id', $programme->id))->whereDate('date', '2026-11-02')->firstOrFail();
        DailyAttendance::create(['programme_id' => $programme->id, 'programme_day_id' => $day->id, 'user_id' => $staff->id, 'status' => 'late']);

        $quiz = Activity::create(['programme_id' => $programme->id, 'kind' => 'quiz', 'title' => 'PPPs Demystified', 'instructions' => 'Answer', 'due_at' => Carbon::parse('2026-11-02 17:00', $programme->timezone), 'attempts' => 1, 'published' => true]);
        $quiz->participants()->attach([$staff->id, $other->id]);
        foreach ([1, 2] as $n) Question::create(['activity_id' => $quiz->id, 'type' => 'true_false', 'prompt' => 'Q'.$n, 'options' => ['True', 'False'], 'correct_answer' => 'True', 'points' => 1]);
        foreach ([[$staff, 2], [$other, 1]] as [$person, $score]) {
            Submission::create(['activity_id' => $quiz->id, 'user_id' => $person->id, 'attempt' => 1, 'started_at' => now(), 'submitted_at' => now(), 'score' => $score]);
        }
        $memo = Activity::create(['programme_id' => $programme->id, 'kind' => 'assignment', 'title' => 'Project screening memo', 'instructions' => 'Write', 'due_at' => now()->subDay(), 'attempts' => 1, 'published' => true]);
        $memo->participants()->attach($staff->id);
        Submission::create(['activity_id' => $memo->id, 'user_id' => $staff->id, 'attempt' => 1, 'started_at' => now(), 'submitted_at' => now(), 'score' => 17, 'feedback' => 'Strengthen the demand evidence.']);

        $url = '/manage/staff/'.$staff->getRouteKey().'/report-card';
        $this->actingAs($staff)->get($url)->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin', 'active' => true]); $admin->programmes()->attach($programme->id);
        $this->actingAs($admin)->get('/manage/staff')->assertSee('href="'.$url.'" target="_blank"', false);
        $this->get($url)->assertOk()
            ->assertSeeInOrder(['Participant Report Card', 'Adaeze Okonkwo', 'ICRC/2026/0417', 'PPP Development'])
            ->assertSeeInOrder(['Assessment average', '100%', '1 of 1 assessments taken', 'Attendance', 'Assignments &amp; presentations', '1', '/ 1'], false)
            ->assertSeeInOrder(['PPPs Demystified', '2 / 2', '1.5'])
            ->assertSeeInOrder(['Project screening memo', 'Strengthen the demand evidence.', '17'])
            ->assertSee('class="cell late"', false)->assertSee('Comments')
            ->assertDontSee('Merit')->assertDontSee('Distinction')->assertDontSee('Signature');

        $this->get('/manage/staff/'.$admin->getRouteKey().'/report-card')->assertNotFound();
    }
}
