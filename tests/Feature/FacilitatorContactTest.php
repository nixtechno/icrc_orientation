<?php

namespace Tests\Feature;

use App\Models\{Facilitator, Programme, User};
use App\Services\{ProfileService, TimetableImporter};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Mail};
use Tests\TestCase;

class FacilitatorContactTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); app(TimetableImporter::class)->run(); Mail::fake();
    }

    private function account($role = 'facilitator', $active = true): User
    {
        $u = User::factory()->create(['role' => $role, 'active' => $active]);
        $u->programmes()->attach(Programme::first()->id);
        return $u;
    }

    private function invite(User $user): void
    {
        DB::table('invitations')->insert(['user_id' => $user->id, 'token_hash' => hash('sha256', 'fac-contact-token'), 'expires_at' => now()->addDay()]);
    }

    private function contact(): array
    {
        return ['first_name' => 'Amina', 'surname' => 'Bello', 'phone' => '08000000001', 'alternative_phone' => '08000000002', 'organisation' => 'Example Training Institute', 'department' => 'Infrastructure Training', 'designation' => 'Senior Facilitator'];
    }

    public function test_facilitator_creation_saves_only_personal_contact_details_and_keeps_staff_forms_intact(): void
    {
        $this->actingAs($this->account('admin'));
        $html = $this->get('/manage/staff/create?role=facilitator')->assertOk()->assertSee('Organization')->assertSee('Alternative phone number')->getContent();
        $dom = new \DOMDocument; @$dom->loadHTML($html); $xpath = new \DOMXPath($dom);
        $this->assertSame(2, $xpath->query('//fieldset[@data-profile-role-section="staff" and @hidden and @disabled]')->length);
        $this->post('/manage/staff', [...$this->contact(), 'email' => 'created.fac@example.test', 'role' => 'facilitator', 'emergency_name' => 'Should not be stored', 'duty_location' => 'Should not be stored'])->assertSessionHasNoErrors()->assertRedirect();
        $fac = User::where('email', 'created.fac@example.test')->firstOrFail();
        $this->assertSame('Example Training Institute', $fac->profile['organisation']);
        $this->assertSame('08000000002', $fac->profile['alternative_phone']);
        $this->assertSame('Infrastructure Training', $fac->department);
        $this->assertSame('Senior Facilitator', $fac->designation);
        $this->assertArrayNotHasKey('emergency_name', $fac->profile);
        $this->assertArrayNotHasKey('duty_location', $fac->profile);
        $this->get('/manage/staff/create?role=staff')->assertOk()->assertSee('Employment details')->assertDontSee('Orientation information')->assertSee('Emergency contact');
    }

    public function test_facilitator_activation_hides_staff_sections_locks_email_and_does_not_require_removed_fields(): void
    {
        $fac = $this->account('facilitator', false); $this->invite($fac);
        $p = Programme::first(); $p->update(['settings' => [...$p->settings, 'required_profile_fields' => ['emergency_name', 'duty_location', 'resumption_date']]]);
        $html = $this->get('/invitation/fac-contact-token')->assertOk()->assertDontSee('Employment details')->assertDontSee('Orientation information')->assertDontSee('Emergency contact')->assertSee('Alternative phone number')->getContent();
        $dom = new \DOMDocument; @$dom->loadHTML($html); $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//input[@name="email" and @readonly]')->length);
        $this->post('/invitation/fac-contact-token', [...$this->contact(), 'email' => 'forged@example.test', 'role' => 'admin', 'password' => 'FacilitatorPassword123', 'password_confirmation' => 'FacilitatorPassword123', 'privacy_acknowledged' => 1])->assertSessionHasNoErrors()->assertRedirect('/login');
        $original = $fac->email; $fac->refresh();
        $this->assertSame($original, $fac->email); $this->assertSame('facilitator', $fac->role);
        $this->assertTrue($fac->active); $this->assertSame('Example Training Institute', $fac->profile['organisation']);
    }

    public function test_facilitator_self_profile_and_linked_directory_stay_in_sync_and_invited_email_is_immutable(): void
    {
        $fac = $this->account(); $this->invite($fac); $original = $fac->email;
        $directory = Facilitator::create(['programme_id' => Programme::first()->id, 'name' => 'Amina Bello', 'user_id' => $fac->id, 'email' => $fac->email]);
        $this->actingAs($fac)->get('/profile')->assertOk()->assertDontSee('Employment details')->assertDontSee('Orientation information')->assertDontSee('Emergency contact');
        $this->post('/profile', [...$this->contact(), 'email' => 'different@example.test'])->assertSessionHasNoErrors()->assertRedirect();
        $fac->refresh(); $directory->refresh();
        $this->assertSame($original, $fac->email);
        $this->assertSame('Example Training Institute', $directory->organisation);
        $this->assertSame('Infrastructure Training', $directory->department);
        $this->assertSame('Senior Facilitator', $directory->job_title);
        $this->assertSame('08000000002', $directory->alternative_phone);
        $this->actingAs($this->account('admin'))->post('/manage/staff/'.$fac->slug, [...$this->contact(), 'email' => 'changed@example.test', 'role' => 'facilitator', 'active' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($original, $fac->fresh()->email);
    }

    public function test_directory_contact_fields_save_and_linked_invited_email_cannot_be_changed(): void
    {
        $fac = $this->account(); $this->invite($fac);
        $directory = Facilitator::create(['programme_id' => Programme::first()->id, 'name' => 'Amina Bello', 'user_id' => $fac->id, 'email' => $fac->email]);
        $this->actingAs($this->account('admin'))->post('/facilitators/'.$directory->slug, ['name' => 'Amina Bello', 'organisation' => 'Directory Organisation', 'department' => 'Advisory', 'job_title' => 'Trainer', 'phone' => '08000000001', 'alternative_phone' => '08000000003', 'email' => 'tampered@example.test', 'user_id' => $fac->id])->assertSessionHasNoErrors()->assertRedirect();
        $directory->refresh();
        $this->assertSame($fac->email, $directory->email);
        $this->assertSame('Advisory', $directory->department);
        $this->assertSame('08000000003', $directory->alternative_phone);
        $this->assertSame('Directory Organisation', $fac->fresh()->profile['organisation']);
        $this->get('/facilitators')->assertOk()->assertSee('Alternative phone number')->assertSee('Personal &amp; contact details', false);
    }

    public function test_facilitator_import_template_includes_contact_fields_and_persists_them(): void
    {
        $this->actingAs($this->account('admin'));
        $template = $this->get('/manage/staff/template?role=facilitator&format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('alternative_phone,organisation,department,designation', $template);
        $this->assertStringNotContainsString('resumption_date', $template);
        $row = "Amina,,Bello,,import.fac@example.test,+2348000000001,+2348000000002,Training Institute,Advisory,Trainer\n";
        $this->post('/manage/staff/import', ['role' => 'facilitator', 'file' => UploadedFile::fake()->createWithContent('facilitators.csv', $template.$row)])->assertOk();
        $id = DB::table('participant_imports')->max('id');
        $this->post('/manage/staff/import/confirm', ['import_id' => $id])->assertRedirect();
        $fac = User::where('email', 'import.fac@example.test')->firstOrFail();
        $this->assertSame('Training Institute', $fac->profile['organisation']);
        $this->assertSame('+2348000000002', $fac->profile['alternative_phone']);
        $this->assertSame('+2348000000001', $fac->phone);
        $this->assertSame('Advisory', $fac->department);
        $this->assertSame('Trainer', $fac->designation);
        $staffTemplate = $this->get('/manage/staff/template?role=staff&format=csv')->assertOk()->streamedContent();
        $this->assertSame(ProfileService::IMPORT_COLUMNS, str_getcsv(trim($staffTemplate)));
        $this->assertStringNotContainsString('programme_information', $staffTemplate);
        $this->assertStringNotContainsString('accommodation', $staffTemplate);
    }
}
