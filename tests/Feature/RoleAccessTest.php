<?php

namespace Tests\Feature;

use App\Models\{Activity, Programme, User};
use App\Services\TimetableImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail, Notification};
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TimetableImporter::class)->run(); Mail::fake(); Notification::fake();
    }

    private function account($role, $permissions = []): User
    {
        $user = User::factory()->create(['role' => $role, 'active' => true, 'permissions' => $permissions]);
        $user->programmes()->attach(Programme::first()->id);
        return $user;
    }

    public function test_facilitator_can_create_all_teaching_activity_types_announcements_and_resources(): void
    {
        $staff = $this->account('staff');
        $facilitator = $this->account('facilitator', User::FACILITATOR_DEFAULT_PERMISSIONS);
        $this->actingAs($facilitator)->get('/dashboard')->assertOk()->assertSee('Facilitator Dashboard');
        foreach (['quiz', 'assignment', 'presentation'] as $kind) {
            $this->get('/activities?kind='.$kind)->assertOk()->assertSee('Create an activity');
            $this->post('/activities', ['kind' => $kind, 'title' => 'Facilitator '.$kind, 'instructions' => 'Teaching exercise', 'due_at' => now()->addDays(3)->toDateTimeString(), 'attempts' => 1, 'assignment_mode' => 'selected', 'participants' => [$staff->id]])->assertSessionHasNoErrors()->assertRedirect();
        }
        $this->assertSame(3, Activity::count());
        $this->post('/announcements', ['title' => 'Facilitator announcement', 'body' => 'Prepare for the session', 'published_at' => now()->toDateTimeString()])->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/resources', ['title' => 'Facilitator resource', 'url' => 'https://example.test/material', 'published' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('announcements', ['title' => 'Facilitator announcement']);
        $this->assertDatabaseHas('resources', ['title' => 'Facilitator resource']);
        foreach (['/settings', '/settings/registration', '/audit', '/badges', '/manage/staff', '/reports'] as $path) $this->get($path)->assertForbidden();
        $this->actingAs($staff)->post('/activities', [])->assertForbidden();
    }

    public function test_only_three_roles_can_be_created_and_admin_can_revoke_facilitator_permissions(): void
    {
        $admin = $this->account('admin');
        $this->actingAs($admin)->post('/manage/staff', ['first_name' => 'New', 'surname' => 'Facilitator', 'email' => 'new.facilitator@example.test', 'role' => 'facilitator'])->assertSessionHasNoErrors()->assertRedirect();
        $user = User::where('email', 'new.facilitator@example.test')->firstOrFail();
        $this->assertSame(User::FACILITATOR_DEFAULT_PERMISSIONS, $user->permissions);
        $this->assertFalse($user->active);
        $this->post('/manage/staff', ['first_name' => 'Invalid', 'surname' => 'Role', 'email' => 'old-role@example.test', 'role' => 'supervisor'])->assertSessionHasErrors('role');
        $this->get('/manage/staff/create')->assertDontSee('value="supervisor"', false);
        $this->get('/manage/staff/template?role=supervisor&format=csv')->assertSessionHasErrors('role');
        $this->post('/manage/staff/'.$user->id, ['first_name' => 'New', 'surname' => 'Facilitator', 'email' => $user->email, 'role' => 'facilitator', 'active' => 1, 'permissions_present' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $user->refresh();
        $this->assertSame([], $user->permissions);
        $this->assertFalse($user->canManage('assessments'));
        $this->actingAs($user)->post('/activities', [])->assertForbidden();
    }

    public function test_role_migration_preserves_existing_account_data_and_converts_pending_imports(): void
    {
        $legacy = $this->account('supervisor', ['reports']);
        $facilitator = $this->account('facilitator', ['qa_answer']);
        $password = $legacy->password;
        $legacy->forceFill(['profile' => ['first_name' => 'Preserved', 'surname' => 'Profile']])->save();
        $ciphertext = $legacy->getRawOriginal('profile');
        DB::table('participant_imports')->insert(['programme_id' => Programme::first()->id, 'user_id' => $legacy->id, 'role' => 'supervisor', 'rows' => '[]', 'issues' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $migration = require database_path('migrations/2026_10_29_100000_consolidate_facilitator_role.php');
        $migration->up();
        $legacy->refresh(); $facilitator->refresh();
        $this->assertSame('facilitator', $legacy->role);
        $this->assertSame($password, $legacy->password);
        $this->assertSame($ciphertext, $legacy->getRawOriginal('profile'));
        $this->assertContains('reports', $legacy->permissions);
        $this->assertTrue($legacy->canManage('assessments'));
        $this->assertTrue($facilitator->canManage('announcements'));
        $this->assertDatabaseHas('participant_imports', ['user_id' => $legacy->id, 'role' => 'facilitator']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.facilitator_migration', 'subject' => (string) $legacy->id]);
    }
}
