<?php

namespace Tests\Feature;

use App\Mail\ProgrammeInvitation;
use App\Models\{Badge, Programme, User};
use App\Services\{InvitationEmail, InvitationService, TimetableImporter};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail};
use Tests\TestCase;

class BadgePrintStationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        app(TimetableImporter::class)->run();
        $this->admin = $this->user('admin');
        $this->staff = $this->user('staff', ['name' => 'Amina Bello', 'active' => false]);
        $this->actingAs($this->admin);
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['role' => $role, 'active' => true, ...$attributes]);
        $user->programmes()->attach(Programme::first()->id);
        return $user;
    }

    private function scan(?string $code = null)
    {
        return $this->postJson('/badges/print-station', ['code' => $code ?? $this->staff->print_code]);
    }

    public function test_people_get_a_print_code_that_differs_from_their_badge_qr_id(): void
    {
        $this->assertMatchesRegularExpression('/^[1-9][0-9]{15}$/', $this->staff->print_code);
        $this->assertNotSame($this->staff->qr_code, $this->staff->print_code);
        $this->assertArrayNotHasKey('print_code', $this->staff->toArray());
        $this->staff->forceFill(['print_code' => null])->saveQuietly();
        $this->assertMatchesRegularExpression('/^[0-9]{16}$/', $this->staff->printCode());
        $this->assertSame($this->staff->print_code, $this->staff->fresh()->print_code);
    }

    public function test_scanning_a_print_code_creates_the_badge_and_opens_it_for_printing(): void
    {
        $this->get('/badges')->assertOk()->assertSee('/badges/print-station', false);
        $this->get('/badges/print-station')->assertOk()->assertSee('Print your badge')->assertSee('name="code" type="password"', false)->assertDontSee('site-footer', false);
        $response = $this->scan()->assertOk()->assertJsonPath('name', 'Amina Bello')->assertJsonPath('reprint', false);
        $badge = Badge::where('user_id', $this->staff->id)->firstOrFail();
        $this->assertSame('staff', $badge->category);
        $this->assertNotNull($badge->printed_at);
        $this->assertSame(url('/badges/print-station/'.$badge->slug), $response->json('print_url'));
        $this->get('/badges/print-station/'.$badge->slug)->assertOk()->assertSee('badge-sheet', false)->assertSee('Amina');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'badge.print_station')->count());

        $printedAt = $badge->printed_at->toIso8601String();
        $this->scan(trim(chunk_split($this->staff->print_code, 4, ' ')))->assertOk()->assertJsonPath('reprint', true);
        $this->assertSame(1, Badge::where('user_id', $this->staff->id)->count());
        $this->assertSame($printedAt, $badge->fresh()->printed_at->toIso8601String());
    }

    public function test_print_codes_and_badge_qr_ids_only_work_at_their_own_station(): void
    {
        $this->scan($this->staff->qr_code)->assertUnprocessable();
        $this->scan('not a code')->assertUnprocessable();
        $this->scan('1234567890123456')->assertUnprocessable();
        $this->postJson('/attendance/scanner/sign-in', ['code' => $this->staff->print_code])->assertUnprocessable();
        $this->staff->programmes()->detach();
        $this->scan()->assertUnprocessable()->assertJsonPath('message', 'Amina Bello is not enrolled in this programme.');
        $this->assertSame(0, Badge::count());
    }

    public function test_only_administrators_can_operate_the_print_station(): void
    {
        $this->scan()->assertOk();
        $badge = Badge::firstOrFail();
        foreach ([$this->user('staff'), $this->user('facilitator', ['permissions' => ['attendance', 'staff']])] as $user) {
            $this->actingAs($user)->get('/badges/print-station')->assertForbidden();
            $this->scan()->assertForbidden();
            $this->get('/badges/print-station/'.$badge->slug)->assertForbidden();
        }
    }

    public function test_invitation_email_carries_the_print_qr_and_issues_a_missing_code_before_sending(): void
    {
        Mail::fake();
        $this->staff->forceFill(['print_code' => null])->saveQuietly();
        app(InvitationService::class)->send($this->staff);
        $code = $this->staff->fresh()->print_code;
        $this->assertMatchesRegularExpression('/^[0-9]{16}$/', $code);
        Mail::assertSent(ProgrammeInvitation::class, function (ProgrammeInvitation $mail) use ($code) {
            $mail->assertHasAttachedData($mail->printQrPng(), 'ICRC-SCODEX-QR-code.png', ['mime' => 'image/png']);
            $html = $mail->render();
            return $mail->printCode === $code && str_contains($html, 'YOUR UNIQUE QR CODE') && ! str_contains($html, $code);
        });

        $people = User::count();
        $preview = $this->postJson('/manage/staff/invitations/preview', [...InvitationEmail::defaults(), 'name' => 'Sample Person', 'email' => 'sample@example.test', 'role' => 'staff'])->assertOk()->json('html');
        $this->assertStringContainsString('src="data:image/png;base64,', $preview);
        $this->assertSame($people, User::count());
    }

    public function test_single_person_previews_show_their_own_qr_and_others_show_a_sample(): void
    {
        $qr = fn (string $code) => 'src="data:image/png;base64,'.base64_encode(\App\Services\BadgeService::qrPng($code)).'"';
        $preview = fn (array $extra) => $this->postJson('/manage/staff/invitations/preview', [...InvitationEmail::defaults(), 'name' => 'Sample Person', 'email' => 'sample@example.test', 'role' => 'staff', ...$extra]);

        $html = $preview(['user' => $this->staff->slug])->assertOk()->json('html');
        $this->assertStringContainsString($qr($this->staff->print_code), $html);
        $this->assertStringContainsString('Dear Amina Bello,', $html);
        $this->assertStringNotContainsString('Sample Person', $html);

        $html = $preview([])->assertOk()->json('html');
        $this->assertStringContainsString($qr(str_repeat('0', 16)), $html);
        $this->assertStringNotContainsString($qr($this->staff->print_code), $html);

        $outsider = User::factory()->create(['role' => 'staff']);
        $preview(['user' => $outsider->slug])->assertNotFound();
        $preview(['user' => 'missing-person'])->assertNotFound();
    }
}
