<?php

namespace Tests\Feature;

use App\Models\{Badge, DailyAttendance, Programme, ProgrammeDay, User};
use App\Services\TimetableImporter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttendanceScannerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $staff;
    private Badge $badge;
    private ProgrammeDay $day;

    protected function setUp(): void
    {
        parent::setUp();
        app(TimetableImporter::class)->run();
        $this->travelTo(Carbon::parse('2026-11-02 09:00:00', 'Africa/Lagos'));
        $this->admin=$this->user('admin'); $this->staff=$this->user('staff');
        $this->day=ProgrammeDay::where('number',1)->firstOrFail();
        $this->day->update(['attendance_required'=>true,'cancelled'=>false,'checkin_opens'=>'08:00','checkin_closes'=>'10:00','late_after'=>'08:30','checkout_opens'=>'09:00','checkout_closes'=>'18:00']);
        $this->badge=Badge::create(['programme_id'=>Programme::first()->id,'subject_key'=>'u:'.$this->staff->id,'user_id'=>$this->staff->id,'category'=>'staff','snapshot'=>['name'=>$this->staff->name],'generated_at'=>now(),'generated_by'=>$this->admin->id]);
        $this->actingAs($this->admin);
    }

    private function user(string $role, array $permissions=[]): User
    {
        $user=User::factory()->create(['role'=>$role,'active'=>true,'permissions'=>$permissions]);
        $user->programmes()->attach(Programme::first()->id);
        return $user;
    }

    private function scan(string $mode='sign-in', ?string $code=null)
    {
        return $this->postJson('/attendance/scanner/'.$mode,['code'=>$code ?? $this->staff->qr_code]);
    }

    public function test_manager_stations_record_attendance_and_reject_duplicate_scans(): void
    {
        $this->get('/attendance')->assertOk()->assertSee('Scan with scanner');
        $this->get('/attendance/scanner')->assertOk()->assertSee('/attendance/scanner/sign-in',false)->assertSee('/attendance/scanner/sign-out',false)->assertSee('target="_blank"',false);
        $this->get('/attendance/scanner/sign-in')->assertOk()->assertSee('Scan to sign in')->assertSee('name="code" type="password"',false)->assertDontSee('Record sign-in')->assertDontSee('site-footer',false);
        $this->get('/attendance/scanner/sign-out')->assertOk()->assertSee('Scan to sign out')->assertDontSee('Record sign-out');
        $this->scan()->assertOk()->assertJsonPath('name',$this->staff->name)->assertJsonPath('status','late');
        $record=DailyAttendance::firstOrFail();
        $signedIn=$record->checked_in_at->toIso8601String();
        $this->assertSame('scanner',$record->signin_method);
        $this->assertEquals($this->admin->id,$record->signin_scanned_by);
        $this->assertFalse($record->signin_device_verified); $this->assertFalse($record->signin_location_verified);
        $this->scan()->assertConflict(); $this->assertDatabaseCount('daily_attendance',1);
        $this->travel(2)->hours();
        $this->scan('sign-out')->assertOk(); $record->refresh();
        $this->assertSame('scanner',$record->signout_method);
        $this->assertEquals($this->admin->id,$record->signout_scanned_by);
        $this->assertEquals(7200,$record->duration_seconds);
        $out=$record->checked_out_at->toIso8601String();
        $this->scan('sign-out')->assertConflict();
        $this->assertSame($signedIn,$record->fresh()->checked_in_at->toIso8601String());
        $this->assertSame($out,$record->fresh()->checked_out_at->toIso8601String());
        $this->assertSame(2,DB::table('attendance_audit_logs')->where('user_id',$this->staff->id)->where('actor_id',$this->admin->id)->where('event','like','attendance.scanner.%')->count());
        $this->get('/attendance/live')->assertOk()->assertSee('Badge scanner')->assertSee('N/A (scanner)');
    }

    public function test_staff_and_unauthorised_facilitators_cannot_use_a_scanner_station(): void
    {
        foreach ([$this->staff,$this->user('facilitator')] as $user) {
            $this->actingAs($user)->get('/attendance/scanner')->assertForbidden();
            $this->get('/attendance/scanner/sign-in')->assertForbidden();
            $this->scan()->assertForbidden();
        }
        $this->actingAs($this->user('facilitator',['attendance']))->scan()->assertOk();
    }

    public function test_only_current_programme_active_enrolled_staff_badges_are_accepted(): void
    {
        $this->scan('sign-in','not a QR code')->assertUnprocessable();
        $this->scan('sign-in','https://example.test/badges/unknown/verify')->assertUnprocessable();
        $this->badge->update(['category'=>'facilitator']); $this->scan()->assertUnprocessable();
        $this->badge->update(['category'=>'staff']);
        $this->staff->forceFill(['active'=>false])->save(); $this->scan()->assertForbidden()->assertJsonPath('message',$this->staff->name.' has not activated their account yet. Attendance can be recorded once they accept their invitation.');
        $this->staff->forceFill(['active'=>true])->save(); $this->staff->programmes()->detach(); $this->scan()->assertForbidden()->assertJsonPath('message',$this->staff->name.' is not enrolled in this programme.');
        $this->staff->programmes()->attach(Programme::first()->id);
        $this->badge->update(['programme_id'=>Programme::create(['code'=>'OTHER','title'=>'Other','starts_on'=>'2026-11-02','ends_on'=>'2026-12-31'])->id]);
        $this->scan()->assertUnprocessable();
        $this->assertDatabaseCount('daily_attendance',0);
    }

    public function test_existing_windows_cancelled_days_and_signout_order_are_enforced(): void
    {
        $this->scan('sign-out')->assertUnprocessable();
        $this->day->update(['cancelled'=>true]); $this->scan()->assertUnprocessable();
        $this->day->update(['cancelled'=>false,'attendance_required'=>false]); $this->scan()->assertUnprocessable();
        $this->day->update(['attendance_required'=>true,'checkin_closes'=>'08:59']); $this->scan()->assertUnprocessable();
        $this->day->update(['checkin_closes'=>'10:00']);
        $this->travelTo(Carbon::parse('2026-11-02 08:30:00','Africa/Lagos'));
        $this->scan('sign-in',$this->badge->slug)->assertOk()->assertJsonPath('status','present');
        $this->scan('sign-out')->assertUnprocessable();
        $this->assertNull(DailyAttendance::first()->checked_out_at);
    }

    public function test_scanner_checkout_preserves_existing_device_signin_verification(): void
    {
        DailyAttendance::create(['programme_id'=>Programme::first()->id,'programme_day_id'=>$this->day->id,'user_id'=>$this->staff->id,'staff_name'=>$this->staff->name,'attendance_date'=>$this->day->date,'checked_in_at'=>now()->subHour(),'status'=>'present','signin_method'=>'device','signin_device_verified'=>true,'signin_location_verified'=>true]);
        $this->scan('sign-out')->assertOk();
        $record=DailyAttendance::first();
        $this->assertTrue($record->signin_device_verified); $this->assertTrue($record->signin_location_verified);
        $this->assertSame('device',$record->signin_method); $this->assertSame('scanner',$record->signout_method);
        $this->assertEquals(3600,$record->duration_seconds);
    }

    public function test_every_new_person_gets_a_unique_numeric_qr_id(): void
    {
        $other=$this->user('facilitator');
        $this->assertMatchesRegularExpression('/^[1-9][0-9]{15}$/',$this->staff->qr_code);
        $this->assertNotSame($this->staff->qr_code,$other->qr_code);
        $this->assertArrayNotHasKey('qr_code',$this->staff->toArray());
        $this->staff->forceFill(['qr_code'=>null])->saveQuietly();
        $this->assertMatchesRegularExpression('/^[0-9]{16}$/',$this->staff->qrCode());
        $this->assertSame($this->staff->qr_code,$this->staff->fresh()->qr_code);
    }

    public function test_badges_carry_the_qr_id_and_links_on_earlier_badges_still_scan(): void
    {
        $writer=new \BaconQrCode\Writer(new \BaconQrCode\Renderer\ImageRenderer(new \BaconQrCode\Renderer\RendererStyle\RendererStyle(220,4),new \BaconQrCode\Renderer\Image\SvgImageBackEnd));
        $item=app(\App\Services\BadgeService::class)->data(collect([$this->badge]))['items']->first();
        $this->assertSame('data:image/svg+xml;base64,'.base64_encode($writer->writeString($this->staff->qr_code)),$item['qr']);
        $this->scan('sign-in',url('/badges/'.$this->badge->slug.'/verify'))->assertOk()->assertJsonPath('name',$this->staff->name);
        $this->scan('sign-in','1234567890123456')->assertUnprocessable();
        $this->scan('sign-in',$this->admin->qr_code)->assertUnprocessable();
    }
}
