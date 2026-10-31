<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Badge;
use App\Models\DailyAttendance;
use App\Models\Facilitator;
use App\Models\Programme;
use App\Models\ProgrammeDay;
use App\Models\ProgrammeSession;
use App\Models\SessionAnswer;
use App\Models\SessionQuestion;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\ProfileService;
use App\Services\TimetableImporter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ManagementUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TimetableImporter::class)->run();
        Mail::fake();
        Notification::fake();
    }

    private function user(string $role = 'staff', array $permissions = []): User
    {
        $u = User::factory()->create();
        $u->forceFill(['role' => $role, 'active' => true, 'permissions' => $permissions])->save();
        $u->programmes()->attach(Programme::first()->id);

        return $u;
    }

    private function day(int $number = 1): ProgrammeDay
    {
        $d = ProgrammeDay::where('number', $number)->firstOrFail();
        $d->update(['attendance_required' => true, 'checkin_opens' => '08:00:00', 'checkin_closes' => '10:00:00', 'late_after' => '08:30:00']);

        return $d;
    }

    private function activation(): array
    {
        return ['first_name' => 'Participant', 'surname' => 'Example', 'phone' => '08000000000', 'password' => 'ExamplePassword123', 'password_confirmation' => 'ExamplePassword123', 'privacy_acknowledged' => 1];
    }

    private function invitation(User $u, string $token = 'test-invite'): void
    {
        DB::table('invitations')->insert(['user_id' => $u->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour()]);
    }

    public function test_administrator_can_open_every_new_management_area(): void
    {
        $this->actingAs($this->user('admin'));
        foreach (['/dashboard', '/manage/staff', '/manage/staff/create', '/settings/registration', '/facilitators', '/attendance', '/attendance/live', '/attendance/legacy', '/qa', '/badges', '/reports', '/audit'] as $url) {
            $this->get($url)->assertOk();
        }$this->actingAs($this->user());
        foreach (['/manage/staff', '/settings/registration', '/facilitators', '/attendance/live', '/attendance/legacy', '/badges'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_manual_and_quick_invitation_support_staff_and_facilitators(): void
    {
        $this->actingAs($this->user('admin'));
        foreach (['staff', 'facilitator'] as $role) {
            $this->post('/manage/staff', ['first_name' => ucfirst($role), 'surname' => 'Example', 'email' => $role.'@example.test', 'role' => $role, 'department' => 'ICT'])->assertRedirect('/manage/staff');
            $u = User::where('email', $role.'@example.test')->firstOrFail();
            $this->assertFalse($u->active);
            $this->assertSame($role, $u->role);
            $this->post('/manage/staff/'.$u->id.'/invite')->assertRedirect();
            $this->get('/manage/staff')->assertSee('Sent');
        }$this->post('/manage/staff', ['invitation_only' => 1, 'name' => 'Invited Facilitator', 'email' => 'invited@example.test', 'role' => 'facilitator'])->assertRedirect('/manage/staff');
        $this->assertDatabaseHas('users', ['email' => 'invited@example.test', 'role' => 'facilitator', 'active' => false]);
        $this->assertDatabaseCount('invitations', 3);
    }

    public function test_recipient_cannot_change_assigned_role_or_confirmed_official_fields(): void
    {
        $u = $this->user('staff');
        $u->forceFill(['active' => false, 'department' => 'Confirmed department'])->save();
        $this->invitation($u);
        $this->post('/invitation/test-invite', [...$this->activation(), 'role' => 'admin', 'department' => 'Forged department', 'permissions' => ['staff']])->assertRedirect('/login');
        $this->assertSame('staff', $u->fresh()->role);
        $this->assertSame('Confirmed department', $u->fresh()->department);
        $this->assertNotNull($u->fresh()->privacy_acknowledged_at);
        $this->assertSame('Participant', $u->fresh()->profile['first_name']);
        $raw = DB::table('users')->where('id', $u->id)->value('profile');
        $this->assertStringNotContainsString('Participant', $raw);
        $this->post('/invitation/test-invite', $this->activation())->assertGone();
    }

    public function test_revoked_and_expired_invitations_cannot_activate(): void
    {
        $u = $this->user();
        $u->forceFill(['active' => false])->save();
        $this->invitation($u);
        DB::table('invitations')->where('user_id', $u->id)->update(['revoked_at' => now()]);
        $this->post('/invitation/test-invite', $this->activation())->assertGone();
        $this->get('/invitation/test-invite')->assertNotFound();
        $this->invitation($u, 'expired');
        DB::table('invitations')->where('token_hash', hash('sha256', 'expired'))->update(['expires_at' => now()->subSecond()]);
        $this->post('/invitation/expired', $this->activation())->assertGone();
    }

    public function test_required_profile_fields_and_privacy_acknowledgement_are_enforced(): void
    {
        $p = Programme::first();
        $p->update(['settings' => [...$p->settings, 'required_profile_fields' => ['department', 'emergency_phone']]]);
        $u = $this->user();
        $u->forceFill(['active' => false])->save();
        $this->invitation($u);
        // Department is assigned by administrators, so it is not required from staff at activation.
        $this->post('/invitation/test-invite', $this->activation())->assertSessionHasErrors('emergency_phone')->assertSessionDoesntHaveErrors('department');
        $values = [...$this->activation(), 'emergency_phone' => '08011111111'];
        unset($values['privacy_acknowledged']);
        $this->post('/invitation/test-invite', $values)->assertSessionHasErrors('privacy_acknowledged');
    }

    public function test_staff_activation_has_no_employment_details_and_keeps_assigned_ones(): void
    {
        $p = Programme::first();
        $p->update(['settings' => [...$p->settings, 'required_profile_fields' => ['department', 'designation', 'resumption_date', 'duty_location']]]);
        $u = $this->user('staff');
        $u->forceFill(['active' => false, 'staff_number' => 'SCODEX2026001', 'department' => null, 'profile' => ['resumption_date' => '2026-11-02']])->save();
        $this->invitation($u);
        $this->get('/invitation/test-invite')->assertOk()->assertDontSee('Employment details')->assertDontSee('name="staff_number"', false)
            ->assertDontSee('name="duty_location"', false)->assertDontSee('Additional required fields at activation');
        $this->post('/invitation/test-invite', [...$this->activation(), 'staff_number' => 'FORGED', 'department' => 'Forged unit', 'designation' => 'Forged title', 'group_name' => 'Forged group', 'resumption_date' => '2030-01-01', 'duty_location' => 'Forged office'])
            ->assertRedirect('/login');
        $u->refresh();
        $this->assertTrue($u->active);
        $this->assertSame(['SCODEX2026001', null, null, null], [$u->staff_number, $u->department, $u->designation, $u->group_name]);
        $this->assertSame('2026-11-02', $u->profile['resumption_date']);
        $this->assertArrayNotHasKey('duty_location', $u->profile);
    }

    public function test_self_profile_cannot_edit_employment_or_view_other_private_photos(): void
    {
        Storage::fake('local');
        $u = $this->user();
        $u->forceFill(['department' => 'Official unit', 'profile' => ['resumption_date' => '2026-11-02']])->save();
        $other = $this->user();
        Storage::disk('local')->put('photos/other.png', 'private');
        $other->forceFill(['photo_path' => 'photos/other.png'])->save();
        $this->actingAs($u)->post('/profile', ['first_name' => 'New', 'surname' => 'Name', 'phone' => '08000000000', 'department' => 'Forged unit', 'resumption_date' => '2020-01-01'])->assertRedirect();
        $this->assertSame('Official unit', $u->fresh()->department);
        $this->assertSame('2026-11-02', $u->fresh()->profile['resumption_date']);
        $this->get('/people/'.$other->id.'/photo')->assertForbidden();
    }

    public function test_profile_photographs_up_to_five_megabytes_are_accepted(): void
    {
        Storage::fake('local');
        $u = $this->user();
        $profile = ['first_name' => 'New', 'surname' => 'Name', 'phone' => '08000000000'];
        $this->actingAs($u)->post('/profile', [...$profile, 'photo' => UploadedFile::fake()->image('passport.jpg')->size(4900)])->assertSessionHasNoErrors();
        $this->assertNotNull($u->fresh()->photo_path);
        $this->post('/profile', [...$profile, 'photo' => UploadedFile::fake()->image('passport.jpg')->size(5200)])->assertSessionHasErrors('photo');
        $this->get('/profile')->assertSee('maximum 5 MB');
    }

    public function test_csv_template_import_supports_both_roles_and_reports_row_errors(): void
    {
        $this->actingAs($this->user('admin'));
        foreach (['staff', 'facilitator'] as $role) {
            $template = $this->get('/manage/staff/template?role='.$role.'&format=csv')->assertOk()->streamedContent();
            $row = $role === 'facilitator' ? ['Given', null, 'Surname', null, $role.'import@example.test', null, '08000000001', 'Demo Organisation', 'ICT', 'Facilitator'] : ['Given', null, 'Surname', null, $role.'import@example.test', null, 'N-'.$role, 'ICT', 'Officer', null, null, 'A'];
            $h = fopen('php://temp', 'w+');
            fputcsv($h, $row);
            rewind($h);
            $csv = $template.stream_get_contents($h);
            fclose($h);
            $file = UploadedFile::fake()->createWithContent('template.csv', $csv);
            $this->post('/manage/staff/import', ['role' => $role, 'file' => $file])->assertOk()->assertSee('Valid');
            $id = DB::table('participant_imports')->max('id');
            $this->post('/manage/staff/import/confirm', ['import_id' => $id])->assertRedirect('/manage/staff');
            $this->assertDatabaseHas('users', ['email' => $role.'import@example.test', 'role' => $role, 'active' => false]);
            $this->post('/manage/staff/import/confirm', ['import_id' => $id])->assertStatus(409);
        }$header = implode(',', ProfileService::IMPORT_COLUMNS)."\n";
        $csv = $header."A,,Person,,duplicate@example.test,,DUP,ICT,Officer,,,\nB,,Person,,duplicate@example.test,,DUP,ICT,Officer,,,\n";
        $this->post('/manage/staff/import', ['role' => 'staff', 'file' => UploadedFile::fake()->createWithContent('invalid.csv', $csv)])->assertOk()->assertSee('Row 3')->assertSee('Duplicate email within file')->assertSee('Duplicate staff number within file');
    }

    public function test_xlsx_templates_round_trip_and_facilitators_receive_teaching_permissions(): void
    {
        $this->actingAs($this->user('admin'));
        $bytes = $this->get('/manage/staff/template?role=facilitator&format=xlsx')->assertOk()->streamedContent();
        $path = tempnam(sys_get_temp_dir(), 'icrc-xlsx');
        file_put_contents($path, $bytes);
        $book = IOFactory::load($path);
        $book->getActiveSheet()->fromArray(['Excel', null, 'Facilitator', null, 'excel@example.test', null, '08000000001', 'Demo Organisation', 'ICT', 'Facilitator'], null, 'A2');
        (new Xlsx($book))->save($path);
        $file = new UploadedFile($path, 'facilitators.xlsx', null, null, true);
        $this->post('/manage/staff/import', ['role' => 'facilitator', 'file' => $file])->assertOk()->assertSee('Excel');
        $id = DB::table('participant_imports')->max('id');
        $this->post('/manage/staff/import/confirm', ['import_id' => $id])->assertRedirect();
        $u = User::where('email', 'excel@example.test')->firstOrFail();
        $this->assertSame(\App\Models\User::FACILITATOR_DEFAULT_PERMISSIONS, $u->permissions);
        $this->assertFalse($u->active);
        @unlink($path);
    }

    public function test_permanent_qr_replaces_expiring_links_without_authorizing_attendance(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)->get('/attendance/qr')->assertOk()->assertSee('Permanent attendance QR codes');
        $this->post('/daily/checkin/1', ['mode' => 'in'])->assertNotFound();
        $this->get('/checkin/1')->assertNotFound();
        $this->assertDatabaseCount('daily_attendance', 0);
    }

    public function test_daily_calculation_uses_days_and_excludes_excused_future_and_cancelled(): void
    {
        $u = $this->user();
        $this->travelTo(Carbon::parse('2026-11-06 18:00:00', 'Africa/Lagos'));
        $days = [];
        foreach (range(1, 6) as $n) {
            $days[$n] = $this->day($n);
        }$days[3]->update(['cancelled' => true]);
        $days[4]->update(['attendance_required' => false]);
        foreach ([1 => 'present', 2 => 'excused'] as $n => $status) {
            DailyAttendance::create(['programme_id' => Programme::first()->id, 'programme_day_id' => $days[$n]->id, 'user_id' => $u->id, 'status' => $status]);
        }ProgrammeSession::query()->update(['attendance_required' => true]);
        $s = app(AttendanceService::class)->summary($u);
        $this->assertSame(1, $s['numerator']);
        $this->assertSame(2, $s['denominator']);
        $this->assertSame(50.0, $s['percentage']);
        $this->assertSame(1, $s['pending']);
        $p = Programme::first();
        $p->update(['settings' => [...$p->settings, 'excused_in_denominator' => true]]);
        $this->assertSame(3, app(AttendanceService::class)->summary($u)['denominator']);
        $this->assertFalse(ProgrammeDay::where('number', 30)->first()->attendance_required);
    }

    public function test_manual_daily_changes_are_disabled_and_live_view_is_protected(): void
    {
        $u = $this->user();
        $admin = $this->user('admin');
        $d = $this->day();
        $this->actingAs($admin)->post('/manage/attendance', ['programme_day_id' => $d->id, 'user_id' => $u->id, 'status' => 'present', 'notes' => 'Manual marking prohibited'])->assertNotFound();
        $this->assertDatabaseCount('daily_attendance', 0);
        $this->get('/attendance/live?date=2026-11-02')->assertOk()->assertSee($u->name);
        $this->actingAs($u)->get('/attendance/live')->assertForbidden();
        $this->get('/attendance?staff='.$admin->id)->assertForbidden();
        $this->actingAs($this->user('facilitator'))->get('/attendance/security/audit')->assertForbidden();
    }

    public function test_session_qa_publication_and_moderation_protect_private_content(): void
    {
        $u = $this->user();
        $other = $this->user();
        $moderator = $this->user('facilitator', ['qa']);
        $session = ProgrammeSession::where('type', 'session')->first();
        $this->actingAs($u)->post('/qa/sessions/'.$session->id.'/questions', ['body' => 'Participant pending question'])->assertRedirect();
        $question = SessionQuestion::first();
        $this->actingAs($other)->get('/qa/sessions/'.$session->id)->assertDontSee('Participant pending question');
        $this->post('/qa/moderate/question/'.$question->id, ['action' => 'publish', 'reason' => 'Appropriate'])->assertForbidden();
        $this->actingAs($moderator)->post('/qa/moderate/question/'.$question->id, ['action' => 'publish', 'reason' => 'Appropriate'])->assertRedirect();
        $this->post('/qa/questions/'.$question->id.'/answers', ['body' => 'Published response', 'published' => 1])->assertRedirect();
        $this->actingAs($other)->get('/qa/sessions/'.$session->id)->assertSee('Participant pending question')->assertSee('Published response')->assertSee('Answered');
        $answer = SessionAnswer::first();
        $this->actingAs($moderator)->post('/qa/moderate/answer/'.$answer->id, ['action' => 'hide', 'reason' => 'Needs correction'])->assertRedirect();
        $this->actingAs($other)->get('/qa/sessions/'.$session->id)->assertDontSee('Published response')->assertSee('Unanswered');
    }

    public function test_facilitator_profile_does_not_grant_login_or_answer_permission(): void
    {
        $admin = $this->user('admin');
        $staff = $this->user();
        $session = ProgrammeSession::where('type', 'session')->first();
        $before = User::count();
        $this->actingAs($admin)->post('/facilitators', ['name' => 'Confirmed facilitator', 'sessions' => [$session->id]])->assertRedirect();
        $this->assertSame($before, User::count());
        $fac = Facilitator::first();
        $question = SessionQuestion::create(['session_id' => $session->id, 'user_id' => $staff->id, 'body' => 'Published question', 'published' => true]);
        $account = $this->user('facilitator');
        $fac->update(['user_id' => $account->id]);
        $this->actingAs($account)->post('/qa/questions/'.$question->id.'/answers', ['body' => 'Not authorised'])->assertForbidden();
        $account->forceFill(['permissions' => ['qa_answer']])->save();
        $this->actingAs($account->fresh())->post('/qa/questions/'.$question->id.'/answers', ['body' => 'Facilitator draft', 'published' => 1])->assertRedirect();
        $this->assertDatabaseHas('session_answers', ['body' => 'Facilitator draft', 'published' => false]);
        $another = SessionQuestion::create(['session_id' => ProgrammeSession::where('type', 'session')->where('id', '!=', $session->id)->first()->id, 'user_id' => $staff->id, 'body' => 'Other session', 'published' => true]);
        $this->post('/qa/questions/'.$another->id.'/answers', ['body' => 'Wrong session'])->assertForbidden();
    }

    public function test_badges_for_all_categories_generate_pdf_and_do_not_create_accounts(): void
    {
        $admin = $this->user('admin');
        $staff = $this->user();
        $facilitator = $this->user('facilitator');
        $fac = Facilitator::create(['programme_id' => Programme::first()->id, 'name' => 'Facilitator Example']);
        $count = User::count();
        $this->actingAs($admin)->post('/badges/generate', ['subjects' => ['u:'.$staff->id, 'u:'.$facilitator->id, 'f:'.$fac->id]])->assertRedirect();
        $this->assertSame($count, User::count());
        $this->assertDatabaseCount('badges', 3);
        $ids = Badge::pluck('id')->all();
        $query = http_build_query(['ids' => $ids]);
        $this->get('/badges/batch?'.$query)->assertOk()->assertSee('Facilitator Example');
        $pdf = $this->get('/badges/batch?'.$query.'&pdf=1')->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(3, preg_match_all('/\\/Type\\s*\\/Page\\b/', $pdf));
        $this->assertNull(Badge::first()->printed_at);
        $this->post('/badges/printed', ['ids' => $ids])->assertRedirect();
        $this->assertNotNull(Badge::first()->printed_at);
        $this->post('/badges/generate', ['subjects' => ['u:'.$staff->id]])->assertRedirect();
        $b = Badge::where('user_id', $staff->id)->first();
        $this->assertSame(2, $b->version);
        $this->assertNull($b->printed_at);
        $this->actingAs($staff)->get('/badges')->assertForbidden();
        $this->post('/badges/generate', ['subjects' => ['u:'.$staff->id]])->assertForbidden();
    }

    public function test_assessments_assign_to_groups_and_link_to_programme_sessions(): void
    {
        $admin = $this->user('admin');
        $a = $this->user();
        $a->forceFill(['group_name' => 'A'])->save();
        $b = $this->user();
        $b->forceFill(['group_name' => 'B'])->save();
        $session = ProgrammeSession::first();
        $values = ['kind' => 'quiz', 'title' => 'Grouped quiz', 'instructions' => 'Answer questions', 'due_at' => now()->addDay()->format('Y-m-d H:i:s'), 'attempts' => 1, 'assignment_mode' => 'group', 'group_name' => 'A', 'session_id' => $session->id, 'programme_week_id' => $session->day->programme_week_id];
        $this->actingAs($admin)->post('/activities', $values)->assertRedirect();
        $activity = Activity::first();
        $this->assertSame([$a->id], $activity->participants->pluck('id')->all());
        $this->assertEquals($session->id, $activity->session_id);
        $this->get('/reports/export?report=assessments&week=1')->assertOk();
        $this->get('/reports/export?report=attendance&format=xlsx')->assertOk();
        $pdf = $this->get('/reports/export?report=attendance&format=pdf')->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
    }
}
