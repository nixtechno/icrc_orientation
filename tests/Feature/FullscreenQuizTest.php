<?php

namespace Tests\Feature;

use App\Models\{Activity, Programme, Question, Submission, User};
use App\Services\TimetableImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FullscreenQuizTest extends TestCase
{
    use RefreshDatabase;
    private User $staff;
    private Activity $quiz;
    private Question $question;

    protected function setUp(): void
    {
        parent::setUp();app(TimetableImporter::class)->run();
        $this->staff=User::factory()->create(['role'=>'staff','active'=>true]);$this->staff->programmes()->attach(Programme::first()->id);
        $this->quiz=Activity::create(['programme_id'=>Programme::first()->id,'kind'=>'quiz','title'=>'Fullscreen assessment','instructions'=>'Answer carefully','published'=>true,'due_at'=>now()->addDay(),'attempts'=>1,'time_limit'=>20]);
        $this->quiz->participants()->attach($this->staff->id);
        $this->question=Question::create(['activity_id'=>$this->quiz->id,'type'=>'multiple_choice','prompt'=>'Choose B','options'=>['A','B'],'correct_answer'=>'B','points'=>5]);
        $this->actingAs($this->staff);
    }

    private function start(): Submission
    {
        $this->postJson('/activities/'.$this->quiz->slug.'/start',['fullscreen'=>1,'policy_acknowledged'=>1,'launch_token'=>'fullscreen-launch-token-123'])->assertOk()->assertJson(['submitted'=>false]);
        return Submission::where('activity_id',$this->quiz->id)->firstOrFail();
    }

    public function test_assessment_cards_show_the_current_staff_submission_status(): void
    {
        $url='/activities?kind=quiz';
        $this->get($url)->assertOk()->assertSee('Assessment status: Not started')->assertSee('One submission only');
        $other=User::factory()->create(['role'=>'staff','active'=>true]);
        Submission::create(['activity_id'=>$this->quiz->id,'user_id'=>$other->id,'attempt'=>1,'started_at'=>now(),'submitted_at'=>now()]);
        $this->get($url)->assertOk()->assertSee('Assessment status: Not started')->assertDontSee('Assessment status: Submitted');
        $submission=$this->start();
        $this->get($url)->assertOk()->assertSee('Assessment status: In progress');
        $this->quiz->update(['due_at'=>now()->subMinute()]);
        $this->get($url)->assertOk()->assertSee('Assessment status: Closed');
        app(\App\Services\QuizAttemptService::class)->finalize($submission,'fullscreen_exit');
        $this->get($url)->assertOk()->assertSee('Assessment status: Submitted')->assertDontSee('Assessment status: Closed');
        $submission->delete();
        $this->quiz->update(['due_at'=>now()->addDay(),'available_at'=>now()->addHour()]);
        $this->get($url)->assertOk()->assertSee('Assessment status: Upcoming');
    }

    public function test_activity_controls_render_only_for_the_correct_role(): void
    {
        foreach (['quiz', 'assignment', 'presentation'] as $kind) {
            $this->quiz->update(['kind'=>$kind]);
            $this->actingAs($this->staff)->get('/activities/'.$this->quiz->slug)
                ->assertOk()->assertDontSee('@else')->assertDontSee('Unpublish activity')->assertDontSee('Assigned:');
        }
        $admin=User::factory()->create(['role'=>'admin','active'=>true]);
        $admin->programmes()->attach(Programme::first()->id);
        $this->actingAs($admin)->get('/activities/'.$this->quiz->slug)
            ->assertOk()->assertSee('Unpublish activity')->assertSee('Assigned:')->assertDontSee('@else')->assertDontSee('Start / continue an attempt');
    }

    public function test_unavailable_quizzes_show_the_reason_instead_of_opening_fullscreen(): void
    {
        $submission=$this->start();
        app(\App\Services\QuizAttemptService::class)->finalize($submission,'fullscreen_exit');
        $this->get('/activities/'.$this->quiz->slug)->assertOk()
            ->assertSee('No attempts remaining.')->assertDontSee('data-quiz-start',false);
        $this->quiz->update(['attempts'=>2]);
        $this->get('/activities/'.$this->quiz->slug)->assertOk()->assertDontSee('data-quiz-start',false);
        $this->postJson('/activities/'.$this->quiz->slug.'/start', ['fullscreen'=>1,'policy_acknowledged'=>1,'launch_token'=>'another-fullscreen-launch-token'])
            ->assertUnprocessable()->assertJsonPath('message','This assessment has already been submitted. You cannot take it again.');
        $this->post('/activities/'.$this->quiz->slug.'/start')->assertUnprocessable();
        $this->assertDatabaseCount('submissions',1);
        $submission->delete();
        $this->get('/activities/'.$this->quiz->slug)->assertOk()->assertSee('data-quiz-start',false);
        $this->quiz->update(['due_at'=>now()->subMinute()]);
        $this->get('/activities/'.$this->quiz->slug)->assertOk()
            ->assertSee('The assessment deadline has passed.')->assertDontSee('data-quiz-start',false);
        $this->quiz->update(['due_at'=>now()->addDay(),'available_at'=>now()->addHour()]);
        $this->get('/activities/'.$this->quiz->slug)->assertOk()
            ->assertSee('This assessment is not available yet.')->assertDontSee('data-quiz-start',false);
    }

    public function test_warning_acknowledgement_and_fullscreen_start_are_required_and_retries_are_idempotent(): void
    {
        $this->get('/activities/'.$this->quiz->slug)->assertOk()->assertSee('Start Assessment / Quiz')->assertSee('IMPORTANT ASSESSMENT NOTICE')->assertSee('automatically submits your current answers')->assertDontSee('@else')->assertDontSee('Unpublish activity')->assertDontSee('Assigned:');
        $this->postJson('/activities/'.$this->quiz->slug.'/start',['fullscreen'=>1,'launch_token'=>'fullscreen-launch-token-123'])->assertUnprocessable();
        $this->assertDatabaseCount('submissions',0);
        $submission=$this->start();$this->assertNotNull($submission->fullscreen_started_at);
        $this->start();$this->assertDatabaseCount('submissions',1);$this->assertNull($submission->fresh()->submitted_at);
        $this->getJson('/submissions/'.$submission->slug.'/complete')->assertOk()->assertJsonStructure(['html','draft_url','finish_url','return_url','expires_at','server_now'])->assertDontSee('correct_answer');
    }

    public function test_drafts_ignore_stale_versions_and_fullscreen_exit_submits_current_answers_exactly_once(): void
    {
        $multi=Question::create(['activity_id'=>$this->quiz->id,'type'=>'multiple_answers','prompt'=>'Choose two','options'=>['A','B','C'],'correct_answer'=>json_encode(['A','C']),'points'=>3]);
        $submission=$this->start();$base='/submissions/'.$submission->slug;
        $answers=[$this->question->id=>'B',$multi->id=>['C','A']];
        $this->postJson($base.'/draft',['answers'=>$answers,'draft_version'=>2])->assertOk()->assertJson(['submitted'=>false,'version'=>2]);
        $this->postJson($base.'/draft',['answers'=>[$this->question->id=>'A'],'draft_version'=>1])->assertOk()->assertJson(['version'=>2]);
        $this->assertSame('B',$submission->fresh()->answers[$this->question->id]);
        $this->postJson($base.'/auto-submit',['reason'=>'fullscreen_exit','answers_present'=>1,'answers'=>$answers,'draft_version'=>3])->assertOk()->assertJson(['submitted'=>true]);
        $submission->refresh();$this->assertEquals(8,$submission->score);$this->assertNotNull($submission->submitted_at);$this->assertNull($submission->released_at);$this->assertSame('fullscreen_exit',$submission->submission_reason);
        $this->postJson($base.'/auto-submit',['reason'=>'page_exit','answers_present'=>1,'answers'=>[$this->question->id=>'A'],'draft_version'=>4])->assertOk();
        $this->postJson($base.'/complete',['answers'=>[$this->question->id=>'A']])->assertOk();
        $this->assertEquals(8,$submission->fresh()->score);$this->assertSame('fullscreen_exit',$submission->fresh()->submission_reason);
        $this->assertSame(1,DB::table('audit_logs')->where('action','submission.submit')->where('subject',(string)$submission->id)->count());
    }

    public function test_a_closed_or_reloaded_fullscreen_attempt_cannot_be_resumed_even_if_the_exit_beacon_was_lost(): void
    {
        $submission=$this->start();$base='/submissions/'.$submission->slug;
        $this->postJson($base.'/draft',['answers'=>[$this->question->id=>'B'],'draft_version'=>1])->assertOk();
        $this->get($base.'/complete')->assertRedirect('/activities/'.$this->quiz->slug);
        $this->assertNotNull($submission->fresh()->submitted_at);$this->assertSame('reopened',$submission->fresh()->submission_reason);$this->assertEquals(5,$submission->fresh()->score);
        $this->getJson($base.'/complete')->assertOk()->assertJson(['submitted'=>true]);
    }

    public function test_automatic_deadline_submission_uses_only_the_last_valid_saved_draft(): void
    {
        $this->quiz->update(['time_limit'=>1]);$submission=$this->start();$base='/submissions/'.$submission->slug;
        $this->postJson($base.'/draft',['answers'=>[$this->question->id=>'A'],'draft_version'=>1])->assertOk();
        $this->travel(2)->minutes();
        $this->postJson($base.'/auto-submit',['reason'=>'deadline','answers_present'=>1,'answers'=>[$this->question->id=>'B'],'draft_version'=>2])->assertOk()->assertJson(['submitted'=>true]);
        $this->assertEquals(0,$submission->fresh()->score);$this->assertSame('A',$submission->fresh()->answers[$this->question->id]);
    }

    public function test_manual_submission_is_not_replaced_by_a_following_fullscreen_exit_and_written_work_remains_for_review(): void
    {
        $written=Question::create(['activity_id'=>$this->quiz->id,'type'=>'long_answer','prompt'=>'Explain','points'=>10]);$submission=$this->start();$base='/submissions/'.$submission->slug;
        $this->postJson($base.'/complete',['answers'=>[$this->question->id=>'B',$written->id=>'My explanation'],'draft_version'=>1])->assertOk()->assertJson(['submitted'=>true]);
        $this->assertNull($submission->fresh()->score);$this->assertSame('manual',$submission->fresh()->submission_reason);
        $this->postJson($base.'/auto-submit',['reason'=>'fullscreen_exit'])->assertOk();
        $this->assertSame('My explanation',$submission->fresh()->answers[$written->id]);$this->assertSame('manual',$submission->fresh()->submission_reason);
    }

    public function test_other_users_cannot_save_or_submit_an_attempt_and_invalid_answers_are_rejected(): void
    {
        $submission=$this->start();$base='/submissions/'.$submission->slug;
        $this->postJson($base.'/draft',['answers'=>[$this->question->id=>['A']],'draft_version'=>1])->assertUnprocessable();
        $other=User::factory()->create(['role'=>'staff','active'=>true]);$other->programmes()->attach(Programme::first()->id);
        $this->actingAs($other)->postJson($base.'/draft',['answers'=>[],'draft_version'=>1])->assertForbidden();
        $this->postJson($base.'/auto-submit',['reason'=>'fullscreen_exit'])->assertForbidden();
        $this->getJson($base.'/complete')->assertForbidden();
        $this->assertNull($submission->fresh()->submitted_at);
    }
}
