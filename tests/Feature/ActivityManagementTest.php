<?php

namespace Tests\Feature;

use App\Models\{Activity, AssessmentGroup, Programme, Question, Submission, User};
use App\Services\TimetableImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Storage};
use Tests\TestCase;

class ActivityManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();app(TimetableImporter::class)->run();Storage::fake('local');
    }

    private function user(string $role='staff', string $name='Staff Person'): User
    {
        $user=User::factory()->create(['role'=>$role,'active'=>true,'name'=>$name,'permissions'=>$role==='facilitator'?['assessments']:[]]);
        $user->programmes()->attach(Programme::first()->id);return $user;
    }

    private function create(array $extra=[]): Activity
    {
        $this->post('/activities?kind=presentation',['title'=>'Team presentation','instructions'=>'Present your findings','due_at'=>now()->addDays(3)->toDateTimeString(),...$extra])->assertSessionHasNoErrors()->assertRedirect();
        return Activity::latest('id')->firstOrFail();
    }

    public function test_presentations_can_be_assigned_to_one_person_a_group_or_everyone(): void
    {
        $amina=$this->user('staff','Amina Bello');$grace=$this->user('staff','Grace Okafor');$musa=$this->user('staff','Musa Idris');
        $this->actingAs($this->user('facilitator'));
        $this->get('/activities?kind=presentation')->assertOk()->assertSee('Specific staff')->assertSee('name="participants[]" value="'.$grace->id.'"',false);

        $single=$this->create(['assignment_mode'=>'selected','participants'=>[$grace->id]]);
        $this->assertSame([$grace->id],$single->participants->modelKeys());$this->assertSame('selected',$single->assignment_mode);
        $this->get('/activities?kind=presentation')->assertSee('Assigned to: 1 selected staff member');
        $this->get('/activities/'.$single->slug)->assertOk()->assertSee('1 selected staff member')->assertSee('Grace Okafor')->assertSee('Change who is assigned');

        $group=AssessmentGroup::create(['programme_id'=>Programme::first()->id,'created_by'=>auth()->id(),'name'=>'Team A']);$group->members()->sync([$amina->id,$musa->id]);
        $grouped=$this->create(['assignment_mode'=>'group','assessment_group_id'=>$group->id]);
        $this->assertEqualsCanonicalizing([$amina->id,$musa->id],$grouped->participants->modelKeys());
        $this->get('/activities/'.$grouped->slug)->assertSee('Group: Team A (2 staff)');

        $everyone=$this->create(['assignment_mode'=>'all']);
        $this->assertCount(3,$everyone->participants);
        $this->get('/activities/'.$everyone->slug)->assertSee('All enrolled staff (3 staff)');

        $this->post('/activities?kind=presentation',['title'=>'Nobody chosen','instructions'=>'x','due_at'=>now()->addDay()->toDateTimeString(),'assignment_mode'=>'selected'])->assertSessionHasErrors('participants');
        $outsider=User::factory()->create(['role'=>'staff','active'=>true]);
        $this->post('/activities?kind=presentation',['title'=>'Outsider','instructions'=>'x','due_at'=>now()->addDay()->toDateTimeString(),'assignment_mode'=>'selected','participants'=>[$outsider->id]])->assertStatus(422);
        $this->assertSame(3,Activity::count());
    }

    public function test_managers_change_the_assignment_and_staff_with_work_stay_assigned(): void
    {
        $amina=$this->user('staff','Amina Bello');$grace=$this->user('staff','Grace Okafor');$musa=$this->user('staff','Musa Idris');
        $this->actingAs($this->user('admin'));
        $activity=$this->create(['assignment_mode'=>'all']);$activity->update(['published'=>true]);
        Submission::create(['activity_id'=>$activity->id,'user_id'=>$amina->id,'attempt'=>1,'started_at'=>now(),'submitted_at'=>now(),'body'=>'Done']);
        $url='/activities/'.$activity->slug;

        $this->from($url)->post($url.'/assignment',['assignment_mode'=>'selected','participants'=>[$grace->id]])->assertRedirect($url)->assertSessionHas('success',fn($message)=>str_contains($message,'1 staff assigned')&&str_contains($message,'1 person who has already started or submitted stays assigned'));
        $this->assertEqualsCanonicalizing([$grace->id,$amina->id],$activity->fresh()->participants->modelKeys());
        $this->assertSame('selected',$activity->fresh()->assignment_mode);
        $this->actingAs($musa)->get($url)->assertForbidden();
        $this->actingAs($amina)->get($url)->assertOk()->assertSee('Done');

        $this->actingAs($this->user('admin','Second Admin'))->post($url.'/assignment',['assignment_mode'=>'selected','participants'=>[]])->assertSessionHasErrors('participants');
        $this->post($url.'/assignment',['assignment_mode'=>'all'])->assertSessionHasNoErrors();
        $this->assertCount(3,$activity->fresh()->participants);$this->assertSame('all',$activity->fresh()->assignment_mode);
        $this->assertTrue(DB::table('audit_logs')->where('action','activity.assignment')->where('subject',(string)$activity->id)->exists());

        $this->actingAs($grace)->post($url.'/assignment',['assignment_mode'=>'all'])->assertForbidden();
    }

    public function test_managers_edit_the_details_of_an_activity(): void
    {
        $this->user();$this->actingAs($this->user('facilitator'));
        $activity=$this->create();$url='/activities/'.$activity->slug;
        $this->get($url)->assertSee('Edit details')->assertSee('value="'.$activity->due_at->format('Y-m-d\TH:i').'"',false);
        $due=now()->addWeek()->setTime(14,30);
        $this->post($url,['title'=>'Renamed presentation','instructions'=>'New instructions','due_at'=>$due->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        $fresh=$activity->fresh();
        $this->assertSame(['Renamed presentation','New instructions',$due->format('Y-m-d H:i')],[$fresh->title,$fresh->instructions,$fresh->due_at->format('Y-m-d H:i')]);
        $this->post($url,['title'=>'','instructions'=>'x','due_at'=>'not a date'])->assertSessionHasErrors(['title','due_at']);
        $this->actingAs($this->user())->post($url,['title'=>'Hijack','instructions'=>'x','due_at'=>$due->toDateTimeString()])->assertForbidden();
        $this->assertSame('Renamed presentation',$activity->fresh()->title);
    }

    public function test_presentations_page_is_tabbed_and_cards_and_manage_page_show_progress(): void
    {
        $amina=$this->user('staff','Amina Bello');$grace=$this->user('staff','Grace Okafor');$musa=$this->user('staff','Musa Idris');
        $this->actingAs($this->user('admin'));
        $alpha=$this->create(['title'=>'Alpha deck']);$alpha->update(['published'=>true]);$beta=$this->create(['title'=>'Beta deck']);
        Submission::create(['activity_id'=>$alpha->id,'user_id'=>$amina->id,'attempt'=>1,'started_at'=>now(),'submitted_at'=>now(),'body'=>'Alpha by Amina','score'=>12]);
        Submission::create(['activity_id'=>$alpha->id,'user_id'=>$grace->id,'attempt'=>1,'started_at'=>now(),'submitted_at'=>now(),'path'=>'submissions/g.pptx','filename'=>'grace-alpha.pptx']);
        Submission::create(['activity_id'=>$beta->id,'user_id'=>$musa->id,'attempt'=>1,'started_at'=>now(),'submitted_at'=>now(),'filename'=>'musa-beta.pptx','path'=>'submissions/m.pptx']);

        // The first tab lists presentations with their progress; the submissions table waits in the second tab.
        $html=$this->get('/activities?kind=presentation')->assertOk()->assertSee('Create a Presentation')->assertDontSee('All submitted presentations')->assertViewHas('presentationTable',null)->getContent();
        $dom=new \DOMDocument;@$dom->loadHTML($html);$xpath=new \DOMXPath($dom);
        $this->assertSame('page',$xpath->query('//nav[contains(@class,"view-tabs")]/a[1]')->item(0)->getAttribute('aria-current'));
        $this->assertStringContainsString('3',$xpath->query('//nav[contains(@class,"view-tabs")]/a[2]/span')->item(0)->textContent);
        $card=fn($title)=>$xpath->query('//article[.//h3[normalize-space()="'.$title.'"]]//dl')->item(0)->textContent;
        $this->assertMatchesRegularExpression('/Submitted\s*2\s*Pending\s*1\s*Reviewed\s*1/',$card('Alpha deck'));
        $this->assertMatchesRegularExpression('/Submitted\s*1\s*Pending\s*2\s*Reviewed\s*0/',$card('Beta deck'));
        $this->assertStringContainsString('2 of 3 submitted',$html);

        $this->get('/activities?kind=presentation&view=submissions')->assertOk()->assertSee('All submitted presentations')->assertSee('More filters')->assertDontSee('Create a Presentation')
            ->assertViewHas('presentationTable',fn($data)=>$data['submissions']->total()===3);

        // Each presentation's page lists only its own submissions, with search and filters kept on that page.
        $page='/activities/'.$alpha->slug;
        $this->get($page)->assertOk()->assertSee('Submitted presentations')->assertSee('action="'.$page.'"',false)->assertSee('grace-alpha.pptx')->assertDontSee('musa-beta.pptx')->assertDontSee('name="presentation"',false)
            ->assertViewHas('presentationTable',fn($data)=>$data['submissions']->total()===2&&$data['scope']->is($alpha))->assertSeeInOrder(['Not yet submitted (1)','Musa Idris']);
        // Reviews open in dialogs from the table instead of sitting on the page as cards.
        $manage=$this->get($page)->getContent();$dom=new \DOMDocument;@$dom->loadHTML($manage);$xpath=new \DOMXPath($dom);
        $this->assertSame(2,$xpath->query('//dialog[contains(@class,"review-dialog")]//form[contains(@action,"/review")]')->length);
        $this->assertSame(0,$xpath->query('//section[starts-with(@id,"submission-")]')->length);
        $this->assertSame(2,$xpath->query('//table//a[@data-review-open and starts-with(@href,"#submission-")]')->length);
        $this->assertStringContainsString('Alpha by Amina',$xpath->query('//dialog[.//h2[normalize-space()="Amina Bello"]]')->item(0)->textContent);
        $this->get('/activities?kind=presentation&view=submissions')->assertSee('href="/activities/'.$alpha->slug.'#submission-',false);
        $this->get($page.'?q=grace')->assertViewHas('presentationTable',fn($data)=>$data['submissions']->pluck('user_id')->all()===[$grace->id]);
        $this->get($page.'?review=graded&presentation='.$beta->slug)->assertViewHas('presentationTable',fn($data)=>$data['submissions']->pluck('user_id')->all()===[$amina->id]);

        $this->actingAs($amina)->get($page)->assertOk()->assertDontSee('Submitted presentations')->assertDontSee('Not yet submitted');
        $this->get('/activities?kind=presentation&view=submissions')->assertOk()->assertDontSee('All submitted presentations')->assertDontSee('view-tabs',false);
    }

    public function test_deleting_an_activity_needs_confirmation_and_removes_its_work(): void
    {
        $staff=$this->user('staff','Amina Bello');$this->actingAs($this->user('admin'));
        $activity=$this->create();$keep=$this->create(['title'=>'Keep this one']);
        Storage::disk('local')->put('submissions/deck.pptx','slides');
        Submission::create(['activity_id'=>$activity->id,'user_id'=>$staff->id,'attempt'=>1,'started_at'=>now(),'submitted_at'=>now(),'path'=>'submissions/deck.pptx','filename'=>'deck.pptx']);
        Question::create(['activity_id'=>$activity->id,'type'=>'short_answer','prompt'=>'Why?','points'=>1]);

        $html=$this->get('/activities?kind=presentation')->assertOk()->assertSee('data-delete-dialog',false)->getContent();
        $this->assertStringContainsString('data-url="/activities/'.$activity->slug.'"',$html);$this->assertStringContainsString('data-submissions="1"',$html);
        $this->get('/activities/'.$activity->slug)->assertSee('Delete this presentation');

        $this->actingAs($staff)->delete('/activities/'.$activity->slug,['confirmed'=>1])->assertForbidden();
        $this->actingAs($this->user('facilitator'))->delete('/activities/'.$activity->slug)->assertSessionHasErrors('confirmed');
        $this->assertNotNull($activity->fresh());

        $this->delete('/activities/'.$activity->slug,['confirmed'=>1])->assertRedirect('/activities?kind=presentation')->assertSessionHas('success','“Team presentation” has been deleted.');
        $this->assertNull($activity->fresh());$this->assertNotNull($keep->fresh());
        $this->assertSame(0,Submission::where('activity_id',$activity->id)->count());$this->assertSame(0,Question::where('activity_id',$activity->id)->count());
        $this->assertSame(0,DB::table('activity_assignments')->where('activity_id',$activity->id)->count());
        Storage::disk('local')->assertMissing('submissions/deck.pptx');
        $this->assertTrue(DB::table('audit_logs')->where('action','activity.delete')->exists());

        $other=Programme::create(['code'=>'OTHER-DELETE','title'=>'Other','starts_on'=>today(),'ends_on'=>today()->addDay(),'timezone'=>'Africa/Lagos']);
        $foreign=Activity::create(['programme_id'=>$other->id,'kind'=>'presentation','title'=>'Other programme','instructions'=>'x','due_at'=>now()->addDay()]);
        $this->delete('/activities/'.$foreign->slug,['confirmed'=>1])->assertNotFound();
    }
}
