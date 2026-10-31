<?php

namespace Tests\Feature;

use App\Models\{Activity, Programme, Submission, User};
use App\Services\TimetableImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PresentationSubmissionTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();app(TimetableImporter::class)->run();
    }

    private function user(string $role='staff', string $name='Staff Person', string $department='ICT'): User
    {
        $user=User::factory()->create(['role'=>$role,'active'=>true,'name'=>$name,'permissions'=>$role==='facilitator'?['assessments']:[]]);
        $user->forceFill(['department'=>$department])->save();$user->programmes()->attach(Programme::first()->id);return $user;
    }

    private function activity(string $title='Staff presentation',string $kind='presentation',?int $programme=null): Activity
    {
        return Activity::create(['programme_id'=>$programme??Programme::first()->id,'kind'=>$kind,'title'=>$title,'instructions'=>'Present your findings','due_at'=>now()->addDays(5),'attempts'=>1]);
    }

    private function submitted(Activity $activity,User $user,array $extra=[]): Submission
    {
        return Submission::create(['activity_id'=>$activity->id,'user_id'=>$user->id,'attempt'=>1,'started_at'=>now()->subDays(3),'submitted_at'=>now()->subDay(),...$extra]);
    }

    public function test_both_managers_see_all_completed_staff_presentations_only_for_the_current_programme(): void
    {
        $first=$this->user('staff','Amina Bello');$second=$this->user('staff','Grace Okafor','Training');
        $presentation=$this->activity();$a=$this->submitted($presentation,$first,['path'=>'submissions/sample.pptx','filename'=>'sample.pptx']);$b=$this->submitted($this->activity('Another presentation'),$second);
        $this->submitted($this->activity('An assignment','assignment'),$first);
        $this->submitted($presentation,$this->user('staff','Not yet submitted'),['submitted_at'=>null]);
        $other=Programme::create(['code'=>'OTHER-PRESENTATIONS','title'=>'Other','starts_on'=>today(),'ends_on'=>today()->addDay(),'timezone'=>'Africa/Lagos']);
        $this->submitted($this->activity('Private other programme','presentation',$other->id),$first);
        foreach(['admin','facilitator'] as $role){
            $this->actingAs($this->user($role))->get('/activities?kind=presentation')->assertOk()->assertSee('All submitted presentations')->assertSee('Amina Bello')->assertSee('Grace Okafor')->assertDontSee('Not yet submitted')->assertDontSee('Private other programme')->assertSee('/submissions/'.$a->slug.'/download',false)->assertSee('#submission-'.$b->slug,false)->assertViewHas('presentationTable',fn($data)=>$data['submissions']->total()===2);
        }
        $this->get('/activities/'.$presentation->slug)->assertOk()->assertSee('id="submission-'.$a->slug.'"',false);
    }

    public function test_filters_search_review_dates_department_and_presentation(): void
    {
        $admin=$this->user('admin');$first=$this->user('staff','Amina Bello');$second=$this->user('staff','Grace Okafor','Training');
        $first->forceFill(['staff_number'=>'SCODEX-123'])->save();
        $alpha=$this->activity('Alpha deck');$beta=$this->activity('Beta deck');
        $a=$this->submitted($alpha,$first,['filename'=>'alpha.pptx','score'=>0]);
        $b=$this->submitted($beta,$second,['submitted_at'=>now()->subDays(2),'score'=>20,'released_at'=>now()]);
        $c=$this->submitted($alpha,$second,['submitted_at'=>now()->subDays(2)]);
        $this->actingAs($admin);
        foreach(['q=SCODEX-123','q=alpha.pptx','department=ICT','review=graded','from='.now()->subDay()->toDateString().'&to='.now()->subDay()->toDateString()] as $query) $this->get('/activities?kind=presentation&'.$query)->assertOk()->assertViewHas('presentationTable',fn($data)=>$data['submissions']->modelKeys()===[$a->id]);
        $this->get('/activities?kind=presentation&presentation='.$beta->slug)->assertViewHas('presentationTable',fn($data)=>$data['submissions']->modelKeys()===[$b->id]);
        $this->get('/activities?kind=presentation&review=ungraded')->assertViewHas('presentationTable',fn($data)=>$data['submissions']->modelKeys()===[$c->id]);
        $this->get('/activities?kind=presentation&review=released')->assertViewHas('presentationTable',fn($data)=>$data['submissions']->modelKeys()===[$b->id]);
        $this->get('/activities?kind=presentation&q=NoSuchStaff')->assertOk()->assertSee('No submitted presentations match these filters.');
        foreach(['per_page=10000','sort=password','direction=sideways','review=invalid','from=2026-11-02&to=2026-11-01'] as $query) $this->getJson('/activities?kind=presentation&'.$query)->assertUnprocessable();
    }

    public function test_pagination_and_staff_privacy_and_preloader_branding(): void
    {
        $admin=$this->user('admin');$presentation=$this->activity();$staff=null;
        for($i=1;$i<=25;$i++){$staff=$this->user('staff',sprintf('Presenter %02d',$i));$this->submitted($presentation,$staff,['submitted_at'=>now()->subMinutes($i)]);}
        $this->actingAs($admin)->get('/activities?kind=presentation')->assertViewHas('presentationTable',fn($data)=>$data['submissions']->count()===10&&$data['submissions']->total()===25)->assertSee('Page 1 of 3');
        $this->get('/activities?kind=presentation&submissions_page=2')->assertViewHas('presentationTable',fn($data)=>$data['submissions']->count()===10&&$data['submissions']->currentPage()===2);
        $this->get('/activities?kind=presentation&per_page=25')->assertViewHas('presentationTable',fn($data)=>$data['submissions']->count()===25);
        $this->get('/activities?kind=presentation&sort=staff&direction=desc')->assertViewHas('presentationTable',fn($data)=>$data['submissions']->first()->user->name==='Presenter 25');
        $this->actingAs($staff)->get('/activities?kind=presentation')->assertOk()->assertDontSee('All submitted presentations')->assertDontSee('Presenter 01');
        $facilitator=$this->user('facilitator');$facilitator->forceFill(['permissions'=>[]])->save();
        $this->actingAs($facilitator)->get('/activities?kind=presentation')->assertOk()->assertDontSee('All submitted presentations');
        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertSee('<p class="icrc-preloader-title">ICRC SCODEX 2026</p>',false)->assertSee('Loading ICRC SCODEX 2026');
        $this->get('/')->assertOk()->assertSee('<p class="icrc-preloader-title">ICRC SCODEX 2026</p>',false);
    }
}
