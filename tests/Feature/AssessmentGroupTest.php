<?php

namespace Tests\Feature;

use App\Models\{Activity, AssessmentGroup, Programme, ProgrammeSession, User};
use App\Services\TimetableImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssessmentGroupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TimetableImporter::class)->run();
    }

    private function user(string $role = 'staff', bool $enrolled = true): User
    {
        $user = User::factory()->create(['role' => $role, 'active' => true, 'permissions' => $role === 'facilitator' ? ['assessments'] : []]);
        if ($enrolled) $user->programmes()->attach(Programme::first()->id);
        return $user;
    }

    private function activity(array $extra = []): array
    {
        return [...['title' => 'Team exercise', 'kind' => 'quiz', 'instructions' => 'Work carefully', 'due_at' => now()->addDay()->toDateTimeString(), 'attempts' => 1, 'assignment_mode' => 'all'], ...$extra];
    }

    public function test_admin_and_facilitator_can_manage_groups_and_assign_every_activity_kind_with_stable_membership(): void
    {
        $admin = $this->user('admin'); $facilitator = $this->user('facilitator');
        $first = $this->user(); $second = $this->user();
        $this->actingAs($facilitator)->post('/assessment-groups', ['name' => 'Infrastructure team', 'members' => [$first->id]])->assertSessionHasNoErrors()->assertRedirect('/assessment-groups');
        $group = AssessmentGroup::firstOrFail();
        $this->assertStringStartsWith('infrastructure-team-', $group->slug);
        $this->get('/assessment-groups')->assertOk()->assertSee('Infrastructure team');
        $this->get('/assessment-groups/'.$group->slug.'/edit')->assertOk()->assertSee('Edit Infrastructure team');
        foreach (['quiz', 'assignment', 'presentation'] as $kind) {
            $this->post('/activities', $this->activity(['kind' => $kind, 'assignment_mode' => 'group', 'assessment_group_id' => $group->id]))->assertSessionHasNoErrors()->assertRedirect();
            $activity = Activity::latest('id')->firstOrFail();
            $this->assertSame([$first->id], $activity->participants->modelKeys());
            $this->assertEquals($group->id, $activity->assessment_group_id);
        }
        $this->actingAs($admin)->post('/assessment-groups/'.$group->slug, ['name' => 'Infrastructure team', 'members' => [$second->id]])->assertSessionHasNoErrors()->assertRedirect();
        foreach (Activity::all() as $activity) $this->assertSame([$first->id], $activity->participants->modelKeys());
        $this->post('/activities', $this->activity(['assignment_mode' => 'group', 'assessment_group_id' => $group->id]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame([$second->id], Activity::latest('id')->first()->participants->modelKeys());
    }

    public function test_groups_reject_staff_access_non_staff_unenrolled_members_duplicates_and_foreign_programmes(): void
    {
        $staff = $this->user(); $admin = $this->user('admin'); $outsider = $this->user('staff', false);
        $this->actingAs($staff)->get('/assessment-groups')->assertForbidden();
        $this->post('/assessment-groups', ['name' => 'Forbidden', 'members' => [$staff->id]])->assertForbidden();
        $this->actingAs($admin);
        foreach ([[], [$admin->id], [$outsider->id], [$staff->id, $staff->id]] as $members) $this->post('/assessment-groups', ['name' => 'Invalid', 'members' => $members])->assertSessionHasErrors();
        $this->post('/assessment-groups', ['name' => 'Valid', 'members' => [$staff->id]])->assertRedirect();
        $this->post('/assessment-groups', ['name' => 'Valid', 'members' => [$staff->id]])->assertSessionHasErrors('name');
        $other = Programme::create(['code' => 'OTHER', 'title' => 'Other programme', 'starts_on' => today(), 'ends_on' => today()->addDay(), 'timezone' => 'Africa/Lagos']);
        $group = AssessmentGroup::create(['programme_id' => $other->id, 'created_by' => $admin->id, 'name' => 'Private group']);
        $this->get('/assessment-groups/'.$group->slug.'/edit')->assertNotFound();
        $this->post('/assessment-groups/'.$group->slug, ['name' => 'Tampered', 'members' => [$staff->id]])->assertNotFound();
        $this->post('/activities', $this->activity(['assignment_mode' => 'group', 'assessment_group_id' => $group->id]))->assertNotFound();
        $this->post('/activities', $this->activity(['assignment_mode' => 'group']))->assertSessionHasErrors('assessment_group_id');
        $admin->forceFill(['role' => 'facilitator', 'permissions' => []])->save();
        $this->get('/assessment-groups')->assertForbidden();
    }

    public function test_sessions_require_their_selected_week_and_creator_ui_has_no_individual_participant_picker(): void
    {
        $admin = $this->user('admin'); $this->user();
        $session = ProgrammeSession::firstOrFail(); $week = $session->day->programme_week_id;
        $this->actingAs($admin)->post('/activities', $this->activity(['session_id' => $session->id]))->assertSessionHasErrors('programme_week_id');
        $otherWeek = Programme::first()->weeks()->where('id', '!=', $week)->firstOrFail();
        $this->post('/activities', $this->activity(['session_id' => $session->id, 'programme_week_id' => $otherWeek->id]))->assertUnprocessable();
        $this->post('/activities', $this->activity(['session_id' => $session->id, 'programme_week_id' => $week]))->assertSessionHasNoErrors()->assertRedirect();
        $html = $this->get('/activities')->assertOk()->assertSee('Use AI Speech to Text')->assertSee('Manage staff groups')->assertDontSee('Selected participants')->assertDontSee('name="participants[]"', false)->getContent();
        $dom = new \DOMDocument; @$dom->loadHTML($html); $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//select[@name="session_id" and @disabled]')->length);
        $this->assertEquals($session->id, Activity::first()->session_id);
    }

    public function test_top_bar_displays_only_the_first_name_while_the_account_menu_keeps_full_identity(): void
    {
        $user = $this->user('admin'); $user->name = 'Amina Bello Hassan'; $user->save();
        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();
        $dom = new \DOMDocument; @$dom->loadHTML($html); $xpath = new \DOMXPath($dom);
        $this->assertSame('Amina', $xpath->query('//span[@class="portal-user-label"]/strong')->item(0)->textContent);
        $this->assertSame('Amina Bello Hassan', $xpath->query('//div[@class="portal-account-identity"]/strong')->item(0)->textContent);
    }
}
