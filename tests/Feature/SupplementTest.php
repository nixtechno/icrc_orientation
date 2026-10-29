<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\ProgrammeSession;
use App\Models\TimetableProposal;
use App\Models\User;
use App\Services\SupplementImporter;
use App\Services\TimetableImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SupplementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TimetableImporter::class)->run();
        app(SupplementImporter::class)->run();
        Notification::fake();
    }

    private function account(string $role = 'admin', array $permissions = []): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role, 'active' => true, 'permissions' => $permissions])->save();
        $user->programmes()->attach(Programme::first()->id);

        return $user;
    }

    private function proposal(string $title): TimetableProposal
    {
        return TimetableProposal::where('proposed->title', 'like', $title.'%')->firstOrFail();
    }

    public function test_import_preserves_published_timetable_and_is_idempotent(): void
    {
        $this->assertSame(187, ProgrammeSession::count());
        $this->assertNull(ProgrammeSession::where('source_key', '2026-d1-5')->first()->title);
        $this->assertSame(0, app(SupplementImporter::class)->run());
        $this->assertSame(12, TimetableProposal::where('proposed->title', 'like', 'Session %')->count());
        $proposal = $this->proposal('Session 10:');
        $this->assertSame('105 minutes', $proposal->source['cells'][1]);
        $this->assertSame('30 minutes', $proposal->session->source['cells'][1]);
        $split = $this->proposal('Session 12:');
        $this->assertSame(60, $split->proposed['actual_minutes']);
        $this->assertStringContainsString('120 minutes', $split->warnings[0]);
        $added = $this->proposal('Presentations and Exercises');
        $this->assertNull($added->session_id);
        $this->assertSame(60, $added->proposed['actual_minutes']);
        $this->assertSame('', $added->source['cells'][1]);
    }

    public function test_schedule_permission_is_required_for_review_and_changes(): void
    {
        $proposal = $this->proposal('Session 1:');
        foreach ([$this->account('staff'), $this->account('facilitator', ['attendance'])] as $user) {
            $this->actingAs($user)->get('/manage/supplement')->assertForbidden();
            $this->post('/manage/supplement/'.$proposal->id, ['decision' => 'accept', 'review_note' => 'Test'])->assertForbidden();
        }
        $this->actingAs($this->account('facilitator', ['schedule']))->get('/manage/supplement')->assertOk()->assertSee('Resolve the Thursday afternoon split');
    }

    public function test_acceptance_retains_original_source_and_audits_the_change(): void
    {
        $this->actingAs($this->account());
        $p = $this->proposal('Session 1:');
        $source = $p->session->source;
        $this->post('/manage/supplement/'.$p->id, ['decision' => 'accept', 'review_note' => 'Confirmed against supplied supplement'])->assertRedirect();
        $session = $p->session->fresh();
        $this->assertSame($p->proposed['title'], $session->title);
        $this->assertSame($source['cells'], $session->source['cells']);
        $this->assertSame('ICRC_Induction_Programme_Michael_Uwaechie.docx', $session->source['supplements'][0]['filename']);
        $this->assertFalse($session->attendance_required);
        $this->assertDatabaseHas('audit_logs', ['action' => 'supplement.apply']);
        $this->post('/manage/supplement/'.$p->id, ['decision' => 'accept', 'review_note' => 'Again'])->assertStatus(409);
    }

    public function test_overlapping_addition_is_blocked_until_the_split_is_applied(): void
    {
        $this->actingAs($this->account());
        $split = $this->proposal('Session 12:');
        $new = $this->proposal('Presentations and Exercises');
        $values = ['decision' => 'accept', 'review_note' => 'Confirmed afternoon split; duration inconsistency retained'];
        $this->post('/manage/supplement/'.$new->id, $values)->assertStatus(422);
        $this->post('/manage/supplement/'.$split->id, $values)->assertRedirect();
        $this->post('/manage/supplement/'.$new->id, $values)->assertRedirect();
        $this->assertSame(188, ProgrammeSession::count());
        $this->assertSame('15:00', $split->session->fresh()->ends_at->format('H:i'));
        $this->assertSame(60, $new->fresh()->session->actual_minutes);
        $this->assertSame(0, app(SupplementImporter::class)->run());
        $this->assertSame(188, ProgrammeSession::count());
    }

    public function test_facilitator_edits_are_never_silently_overwritten(): void
    {
        $this->actingAs($this->account());
        $p = $this->proposal('Session 2:');
        $p->session->update(['title' => 'Facilitator confirmed title']);
        $this->post('/manage/supplement/'.$p->id, ['decision' => 'accept', 'review_note' => 'Apply'])->assertStatus(409);
        $this->assertSame('Facilitator confirmed title', $p->session->fresh()->title);
        $this->post('/manage/supplement/'.$p->id, ['decision' => 'reject', 'review_note' => 'Retain facilitator title'])->assertRedirect();
        $this->assertSame('rejected', $p->fresh()->status);
    }
}
