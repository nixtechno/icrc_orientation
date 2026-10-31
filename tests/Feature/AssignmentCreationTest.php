<?php

namespace Tests\Feature;

use App\Models\{Activity, Programme, Submission, User};
use App\Services\TimetableImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignmentCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); app(TimetableImporter::class)->run();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'active' => true, 'permissions' => $role==='facilitator'?['assessments']:[]]);
        $user->programmes()->attach(Programme::first()->id);
        return $user;
    }

    public function test_admin_and_facilitator_forms_are_section_specific_and_assignments_omit_scheduling_fields(): void
    {
        foreach (['admin','facilitator'] as $role) {
            $this->actingAs($this->user($role));
            foreach (['assignment'=>'Create an Assignment','quiz'=>'Create an Assessment','presentation'=>'Create a Presentation'] as $kind=>$heading) {
                $html = $this->get('/activities?kind='.$kind)->assertOk()->assertSee($heading)->assertDontSee('Create an activity')->getContent();
                $dom = new \DOMDocument; @$dom->loadHTML($html); $xpath = new \DOMXPath($dom);
                $this->assertSame(0, $xpath->query('//select[@name="kind"]')->length);
                $this->assertSame($kind, $xpath->query('//input[@name="kind"]')->item(0)->getAttribute('value'));
                $this->assertSame(1, $xpath->query('//input[@name="due_at" and @required]')->length);
                foreach (['available_at','attempts','time_limit'] as $field) $this->assertSame($kind==='quiz'?1:0, $xpath->query('//input[@name="'.$field.'"]')->length);
            }
        }
    }

    public function test_existing_assignment_without_a_deadline_can_still_be_published_started_and_submitted(): void
    {
        $admin=$this->user('admin');$staff=$this->user('staff');
        $this->actingAs($admin)->post('/activities?kind=assignment', ['kind'=>'quiz','title'=>'Open assignment','instructions'=>'Submit your reflections','assignment_mode'=>'all','due_at'=>now()->addDay()->toDateTimeString()])->assertSessionHasNoErrors()->assertRedirect();
        $assignment=Activity::where('title','Open assignment')->firstOrFail();
        $assignment->update(['due_at'=>null]);
        $this->assertSame('assignment',$assignment->kind);$this->assertNull($assignment->due_at);$this->assertNull($assignment->available_at);$this->assertNull($assignment->time_limit);$this->assertEquals(1,$assignment->attempts);
        $this->get('/activities?kind=assignment')->assertOk()->assertSee('Open assignment')->assertSee('No deadline');
        $this->get('/activities?kind=quiz')->assertOk()->assertDontSee('Open assignment');
        $this->post('/activities/'.$assignment->slug.'/publish')->assertRedirect();
        $this->travel(90)->days();
        $this->actingAs($staff)->get('/activities/'.$assignment->slug)->assertOk()->assertSee('No deadline')->assertSee('Back to assignments');
        $this->post('/activities/'.$assignment->slug.'/start')->assertRedirect();
        $submission=Submission::where('activity_id',$assignment->id)->firstOrFail();
        $this->get('/submissions/'.$submission->slug.'/complete')->assertOk()->assertSee('No deadline.');
        $this->post('/submissions/'.$submission->slug.'/complete',['body'=>'My completed assignment'])->assertRedirect();
        $this->assertNotNull($submission->fresh()->submitted_at);
        $this->get('/activities/'.$assignment->slug)->assertOk()->assertSee('My completed assignment');
        $this->actingAs($admin)->get('/reports/export?report=assessments&format=csv')->assertOk();
    }

    public function test_assessments_require_attempts_and_presentations_require_only_the_due_date(): void
    {
        $this->actingAs($this->user('admin'));$this->user('staff');
        $this->post('/activities?kind=quiz',['title'=>'Needs dates','instructions'=>'Complete this','assignment_mode'=>'all'])->assertSessionHasErrors(['due_at','attempts']);
        $this->post('/activities?kind=presentation',['title'=>'Needs dates','instructions'=>'Complete this','assignment_mode'=>'all'])->assertSessionHasErrors('due_at')->assertSessionDoesntHaveErrors('attempts');
        $this->post('/activities?kind=presentation',['title'=>'Presentation draft','instructions'=>'Present the work','assignment_mode'=>'all','due_at'=>now()->addDay()->toDateTimeString()])->assertSessionHasNoErrors()->assertRedirect();
        $presentation=Activity::where('title','Presentation draft')->firstOrFail();
        $this->assertNull($presentation->available_at);$this->assertNull($presentation->time_limit);$this->assertEquals(1,$presentation->attempts);
        $this->getJson('/activities?kind=unknown')->assertUnprocessable();
    }

    public function test_new_assignments_require_a_future_due_date_without_the_other_removed_controls(): void
    {
        $this->actingAs($this->user('facilitator'));$this->user('staff');
        $data=['title'=>'Assignment with a deadline','instructions'=>'Complete the work','assignment_mode'=>'all'];
        $this->post('/activities?kind=assignment',$data)->assertSessionHasErrors('due_at');
        $this->post('/activities?kind=assignment',[...$data,'due_at'=>now()->subDay()->toDateTimeString()])->assertSessionHasErrors('due_at');
        $this->post('/activities?kind=assignment',[...$data,'due_at'=>now()->addDay()->toDateTimeString()])->assertSessionHasNoErrors()->assertRedirect();
        $assignment=Activity::where('title',$data['title'])->firstOrFail();
        $this->assertNotNull($assignment->due_at);$this->assertNull($assignment->available_at);$this->assertNull($assignment->time_limit);$this->assertEquals(1,$assignment->attempts);
    }
}
