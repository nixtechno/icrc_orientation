<?php

namespace Tests\Feature;

use App\Models\{Activity, Programme, Submission, User};
use App\Services\TimetableImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PresentationSubmitTest extends TestCase
{
    use RefreshDatabase;

    private const PPTX = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

    protected function setUp(): void
    {
        parent::setUp();app(TimetableImporter::class)->run();Storage::fake('local');
    }

    private function user(string $role='staff', string $name='Staff Person'): User
    {
        $user=User::factory()->create(['role'=>$role,'active'=>true,'name'=>$name,'permissions'=>$role==='facilitator'?['assessments']:[]]);
        $user->programmes()->attach(Programme::first()->id);return $user;
    }

    private function presentation(array $participants, array $extra=[]): Activity
    {
        $activity=Activity::create(['programme_id'=>Programme::first()->id,'kind'=>'presentation','title'=>'Week one presentation','instructions'=>'Present your findings','due_at'=>now()->addDays(5),'attempts'=>1,'published'=>true,...$extra]);
        $activity->participants()->sync(collect($participants)->pluck('id'));return $activity;
    }

    public function test_staff_submit_a_powerpoint_or_text_directly_from_the_presentation_page(): void
    {
        $amina=$this->user('staff','Amina Bello');$grace=$this->user('staff','Grace Okafor');$activity=$this->presentation([$amina,$grace]);$url='/activities/'.$activity->slug;
        $this->actingAs($amina)->get($url)->assertOk()->assertSee('Submit your presentation')->assertSee('name="file"',false)->assertSee('name="body"',false)->assertSee('.pptx',false)->assertDontSee('Start / continue an attempt');
        $this->get('/activities?kind=presentation')->assertOk()->assertSee('Not submitted');
        $this->from($url)->post($url.'/presentation',['body'=>''])->assertRedirect($url)->assertSessionHasErrors('body');
        $this->from($url)->post($url.'/presentation',['file'=>UploadedFile::fake()->create('notes.exe',10,'application/x-msdownload')])->assertSessionHasErrors('file');
        $this->from($url)->post($url.'/presentation',['file'=>UploadedFile::fake()->create('huge.pptx',51201,self::PPTX)])->assertSessionHasErrors('file');
        $this->assertSame(0,Submission::count());

        $this->post($url.'/presentation',['file'=>UploadedFile::fake()->create('my-deck.pptx',2048,self::PPTX),'body'=>'Speaker notes'])->assertRedirect($url)->assertSessionHasNoErrors();
        $submission=Submission::sole();$first=$submission->path;
        $this->assertSame(['my-deck.pptx','Speaker notes',1],[$submission->filename,$submission->body,$submission->attempt]);$this->assertNotNull($submission->submitted_at);Storage::disk('local')->assertExists($first);
        $this->get($url)->assertSee('Replace your submission')->assertSee('my-deck.pptx');
        $this->get('/activities?kind=presentation')->assertSee('Submitted');

        // Leaving the file empty keeps the current file; a new file replaces it.
        $this->post($url.'/presentation',['body'=>'Updated notes'])->assertSessionHasNoErrors();
        $this->assertSame([$first,'Updated notes'],[$submission->fresh()->path,$submission->fresh()->body]);
        $this->post($url.'/presentation',['file'=>UploadedFile::fake()->create('final-deck.ppt',100,'application/vnd.ms-powerpoint')])->assertSessionHasNoErrors();
        $this->assertSame(['final-deck.ppt',null,1],[$submission->fresh()->filename,$submission->fresh()->body,Submission::count()]);
        Storage::disk('local')->assertMissing($first);

        $this->actingAs($grace)->post($url.'/presentation',['body'=>'My presentation does not use slides.'])->assertSessionHasNoErrors();
        $this->assertNull(Submission::where('user_id',$grace->id)->sole()->path);
    }

    public function test_reviewed_late_or_unassigned_presentations_cannot_be_submitted(): void
    {
        $staff=$this->user();$outsider=$this->user('staff','Outsider');$admin=$this->user('admin');$activity=$this->presentation([$staff]);$url='/activities/'.$activity->slug;
        $this->actingAs($outsider)->post($url.'/presentation',['body'=>'Hello'])->assertForbidden();
        $this->actingAs($admin)->post($url.'/presentation',['body'=>'Hello'])->assertForbidden();
        $this->actingAs($staff)->post($url.'/presentation',['body'=>'First version'])->assertSessionHasNoErrors();
        Submission::sole()->update(['score'=>15]);
        $this->get($url)->assertSee('has been reviewed')->assertDontSee('name="file"',false);
        $this->post($url.'/presentation',['body'=>'Changed'])->assertStatus(422);
        $this->assertSame('First version',Submission::sole()->body);

        $late=$this->presentation([$staff],['title'=>'Closed presentation','due_at'=>now()->subHour()]);
        $this->get('/activities/'.$late->slug)->assertSee('submissions are closed')->assertDontSee('name="file"',false);
        $this->post('/activities/'.$late->slug.'/presentation',['body'=>'Too late'])->assertStatus(422);
    }

    public function test_admins_and_facilitators_see_who_has_and_has_not_submitted(): void
    {
        $amina=$this->user('staff','Amina Bello');$grace=$this->user('staff','Grace Okafor');$activity=$this->presentation([$amina,$grace]);
        $this->actingAs($amina)->post('/activities/'.$activity->slug.'/presentation',['body'=>'Written presentation'])->assertSessionHasNoErrors();
        foreach(['admin','facilitator'] as $role){
            $this->actingAs($this->user($role))->get('/activities/'.$activity->slug)->assertOk()->assertSee('Submission progress')->assertSeeInOrder(['1 of 2','Not yet submitted (1)','Grace Okafor'])->assertSee('Written presentation')->assertSee('Submitted presentations')->assertSee('Written text only');
            $this->get('/activities?kind=presentation&view=submissions')->assertOk()->assertSee('Amina Bello')->assertSee('Written text only');
        }
    }
}
