<?php

namespace Tests\Feature;

use App\Models\{Badge, Facilitator, Programme, User};
use App\Services\{BadgeService, TimetableImporter};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BadgeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); app(TimetableImporter::class)->run();
    }

    private function user(string $role = 'staff'): User
    {
        $user = User::factory()->create(['role' => $role, 'active' => true]);
        $user->programmes()->attach(Programme::first()->id);
        return $user;
    }

    private function badge(User $user): Badge
    {
        $this->post('/badges/generate', ['subjects' => ['u:'.$user->id]])->assertRedirect();
        return Badge::where('user_id', $user->id)->firstOrFail();
    }

    public function test_three_profile_types_have_folded_editors_private_qr_and_unique_stable_collection_codes(): void
    {
        $admin = $this->user('admin'); $this->actingAs($admin);
        foreach ([$admin,$this->user('facilitator'),$this->user()] as $user) {
            $count = User::count(); $badge = $this->badge($user);
            $this->assertEquals($count, User::count()); $this->assertSame($user->role, $badge->category);
            $html = $this->get('/badges/'.$badge->slug.'/edit')->assertOk()->assertSee('Edit badge')->assertSee('Print badge')->assertSee('Confirm collection')->assertSee('badge-back')->assertSee($badge->collection_code)->getContent();
            $this->assertSame(2, substr_count($html, 'alt="Badge QR code"'));
            $this->assertNull($badge->fresh()->printed_at);
            $item = app(BadgeService::class)->data(collect([$badge]))['items']->first();
            $this->assertSame(url('/badges/'.$badge->slug.'/verify'), $item['verifyUrl']);
            $this->get('/badges/'.$badge->slug.'/verify')->assertOk()->assertSee('Verify identity');
            $code = $badge->collection_code; $this->badge($user);
            $this->assertSame($code, $badge->fresh()->collection_code);
        }
        $this->assertSame(3, Badge::distinct()->count('collection_code'));
        $this->get('/badges?category=admin')->assertOk()->assertSee('Admin');
        $this->get('/manage/staff/'.$admin->slug)->assertOk()->assertSee('Edit / print badge');
    }

    public function test_editor_updates_real_profile_and_badge_but_never_changes_login_role_or_permissions(): void
    {
        $admin = $this->user('admin'); $facilitator = $this->user('facilitator');
        $directory = Facilitator::create(['programme_id' => Programme::first()->id, 'user_id' => $facilitator->id, 'name' => $facilitator->name]);
        $this->actingAs($admin); $badge = $this->badge($facilitator);
        $badge->update(['printed_at' => now(), 'collected_at' => now(), 'materials_collected_at' => now()]);
        $code = $badge->collection_code; $permissions = $facilitator->permissions;
        $this->post('/badges/'.$badge->slug, ['first_name' => 'Amina', 'last_name' => 'Bello', 'designation' => 'Lead Trainer', 'organisation' => 'Training Institute', 'category' => 'admin', 'role' => 'admin'])->assertSessionHasNoErrors()->assertRedirect();
        $facilitator->refresh(); $badge->refresh();
        $this->assertSame('Amina Bello', $facilitator->name);
        $this->assertSame('Amina Bello', $directory->fresh()->name);
        $this->assertSame('Lead Trainer', $facilitator->designation);
        $this->assertSame('Training Institute', $facilitator->profile['organisation']);
        $this->assertSame('facilitator', $facilitator->role); $this->assertSame($permissions, $facilitator->permissions);
        $this->assertSame('facilitator', $badge->category); $this->assertSame('Amina', $badge->snapshot['first_name']);
        $this->assertNull($badge->printed_at); $this->assertNull($badge->collected_at); $this->assertNotNull($badge->materials_collected_at);
        $this->assertSame($code, $badge->collection_code);
        $this->assertDatabaseHas('audit_logs', ['action' => 'badge.profile_updated', 'subject' => (string) $badge->id]);
    }

    public function test_print_and_collection_confirmation_are_explicit_independent_and_idempotent(): void
    {
        $this->actingAs($this->user('admin')); $badge = $this->badge($this->user()); $url = '/badges/'.$badge->slug.'/collection';
        $this->post($url, ['badge_collected' => 1])->assertSessionHasErrors('badge_collected');
        $this->assertNull($badge->fresh()->collected_at);
        $this->post($url, ['materials_collected' => 1])->assertRedirect(); $badge->refresh();
        $materials = $badge->materials_collected_at->toDateTimeString(); $this->assertNull($badge->printed_at);
        $this->post($url, ['badge_printed' => 1, 'badge_collected' => 1, 'materials_collected' => 1])->assertRedirect();
        $badge->refresh(); $collected = $badge->collected_at->toDateTimeString(); $this->travel(5)->minutes();
        $this->post($url, ['badge_printed' => 1, 'badge_collected' => 1, 'materials_collected' => 1])->assertRedirect();
        $this->assertSame($collected, $badge->fresh()->collected_at->toDateTimeString());
        $this->assertSame($materials, $badge->fresh()->materials_collected_at->toDateTimeString());
        $this->get('/badges?q='.$badge->collection_code)->assertOk()->assertSee($badge->snapshot['name']);
    }

    public function test_badge_workflow_is_admin_only_scoped_and_print_sizes_are_validated(): void
    {
        $admin = $this->user('admin'); $staff = $this->user();
        $this->actingAs($admin); $badge = $this->badge($staff);
        $this->getJson('/badges/'.$badge->slug.'/edit?name_size=200')->assertUnprocessable();
        $this->get('/badges/'.$badge->slug.'/edit?name_size=7&title_size=3&organisation_size=3&layout=single')->assertOk()->assertDontSee('class="badge-face badge-back"', false);
        foreach ([$staff,$this->user('facilitator')] as $user) {
            $this->actingAs($user)->get('/badges/'.$badge->slug.'/edit')->assertForbidden();
            $this->get('/badges/'.$badge->slug.'/verify')->assertForbidden();
            $this->post('/badges/'.$badge->slug.'/collection', ['badge_printed' => 1])->assertForbidden();
            $this->post('/badges/'.$badge->slug, ['first_name' => 'Tampered'])->assertForbidden();
        }
        $this->actingAs($admin); $other = Programme::create(['code' => 'OTHER-BADGE', 'title' => 'Other', 'timezone' => 'Africa/Lagos', 'starts_on' => today(), 'ends_on' => today()->addDay()]);
        $badge->update(['programme_id' => $other->id]);
        $this->get('/badges/'.$badge->slug.'/verify')->assertNotFound();
        $this->post('/badges/'.$badge->slug.'/collection')->assertNotFound();
    }

    public function test_standalone_facilitator_profile_details_are_saved_from_the_badge_editor(): void
    {
        $this->actingAs($this->user('admin'));
        $person = Facilitator::create(['programme_id' => Programme::first()->id, 'name' => 'Example Trainer']);
        $this->post('/badges/generate', ['single_subject' => 'f:'.$person->id])->assertRedirect();
        $badge = Badge::where('facilitator_id', $person->id)->firstOrFail();
        $this->post('/badges/'.$badge->slug, ['first_name' => 'Grace', 'last_name' => 'Okafor', 'designation' => 'Director', 'organisation' => 'Example Institute'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('Grace Okafor', $person->fresh()->name);
        $this->assertSame('Director', $person->fresh()->job_title);
        $this->assertSame('Example Institute', $badge->fresh()->snapshot['organisation']);
    }
}
