<?php

namespace Tests\Feature;

use App\Mail\ProgrammeInvitation;
use App\Models\Programme;
use App\Models\User;
use App\Services\{InvitationEmail, InvitationService, TimetableImporter};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail};
use Tests\TestCase;

class InvitationComposerTest extends TestCase
{
    use RefreshDatabase;

    private Programme $programme;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->programme = app(TimetableImporter::class)->run();
        $this->admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->admin->programmes()->attach($this->programme->id);
        Mail::fake();
    }

    private function person(array $attributes = []): User
    {
        $user = User::factory()->create(['role' => 'staff', 'active' => false, ...$attributes]);
        $user->programmes()->attach($this->programme->id);

        return $user;
    }

    private function content(array $overrides = []): array
    {
        return [...InvitationEmail::defaults(), ...$overrides];
    }

    public function test_people_page_offers_the_invitation_modal_and_bulk_selection_for_pending_people_only(): void
    {
        $pending = $this->person(['name' => 'Pending Person']);
        $active = $this->person(['name' => 'Active Person', 'active' => true]);
        $html = $this->actingAs($this->admin)->get('/manage/staff')->assertOk()->getContent();
        $this->assertStringContainsString('data-invite-modal', $html);
        $this->assertStringContainsString('data-invite-config', $html);
        $this->assertStringContainsString('value="'.$pending->getRouteKey().'"', $html);
        $this->assertStringNotContainsString('value="'.$active->getRouteKey().'"', $html);
        $this->assertStringNotContainsString('<summary>Individual invitation</summary>', $html);
    }

    public function test_preview_renders_the_edited_email_for_the_sample_recipient_without_sending(): void
    {
        $this->actingAs($this->admin)->postJson('/manage/staff/invitations/preview', $this->content([
            'subject' => 'Hello {first_name}',
            'body' => "## Programme brief\n\nWelcome, **{name}** ({role}).",
            'button' => 'Set my password',
            'name' => 'Amina <b>Bello</b>', 'email' => 'amina@example.test', 'role' => 'facilitator',
        ]))->assertOk()
            ->assertJsonPath('subject', 'Hello Amina')
            ->assertJson(fn ($json) => $json->where('html', fn ($html) => str_contains($html, 'Programme brief</h2>')
                && str_contains($html, 'Amina &lt;b&gt;Bello&lt;/b&gt;')
                && str_contains($html, 'Facilitator')
                && str_contains($html, 'Set my password')
                && ! str_contains($html, '<b>Bello</b>'))->etc());
        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('invitations')->count());
    }

    public function test_unknown_placeholders_and_missing_content_are_rejected(): void
    {
        $this->actingAs($this->admin)->postJson('/manage/staff/invitations/preview', $this->content(['body' => 'Hi {frist_name}', 'button' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors(['body', 'button']);
    }

    public function test_bulk_send_delivers_the_edited_email_and_reports_each_person(): void
    {
        $first = $this->person(['name' => 'First Person']);
        $second = $this->person(['name' => 'Second Person']);
        $active = $this->person(['active' => true]);
        $outsider = User::factory()->create(['role' => 'staff', 'active' => false]);

        $results = $this->actingAs($this->admin)->postJson('/manage/staff/invitations/send', $this->content([
            'subject' => 'Orientation brief for {first_name}',
            'body' => 'Custom programme brief for {name}.',
            'users' => [$first->getRouteKey(), $second->getRouteKey(), $active->getRouteKey(), $outsider->getRouteKey(), 'missing-person'],
        ]))->assertOk()->json('results');

        $this->assertSame(['sent', 'sent', 'skipped', 'skipped', 'skipped'], array_column($results, 'status'));
        $this->assertSame('Already activated.', $results[2]['message']);
        Mail::assertSent(ProgrammeInvitation::class, 2);
        Mail::assertSent(ProgrammeInvitation::class, fn ($mail) => $mail->hasTo($second->email)
            && $mail->subjectLine === 'Orientation brief for Second'
            && str_contains($mail->bodyHtml, 'Custom programme brief for Second Person.'));
        $this->assertSame(2, DB::table('invitations')->whereNull('revoked_at')->count());
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'invitation.send')->count());
    }

    public function test_bulk_send_limits_batch_size(): void
    {
        $this->actingAs($this->admin)->postJson('/manage/staff/invitations/send', $this->content(['users' => array_fill(0, 26, 'someone')]))
            ->assertUnprocessable()->assertJsonValidationErrors('users');
    }

    public function test_bulk_send_reports_delivery_failures_without_stopping_the_batch(): void
    {
        $first = $this->person();
        $second = $this->person();
        $this->mock(InvitationService::class, function ($mock) use ($first) {
            $mock->shouldReceive('send')->twice()->andReturnUsing(function (User $u) use ($first) {
                if ($u->is($first)) {
                    throw new \RuntimeException('SMTP unavailable');
                }
            });
        });
        $results = $this->actingAs($this->admin)->postJson('/manage/staff/invitations/send', $this->content(['users' => [$first->getRouteKey(), $second->getRouteKey()]]))
            ->assertOk()->json('results');
        $this->assertSame(['failed', 'sent'], array_column($results, 'status'));
    }

    public function test_staff_managers_cannot_invite_facilitators_or_save_the_default_email(): void
    {
        $manager = $this->person(['role' => 'facilitator', 'active' => true, 'permissions' => ['staff']]);
        $staff = $this->person();
        $facilitator = $this->person(['role' => 'facilitator']);
        $results = $this->actingAs($manager)->postJson('/manage/staff/invitations/send', $this->content(['users' => [$staff->getRouteKey(), $facilitator->getRouteKey()]]))
            ->assertOk()->json('results');
        $this->assertSame(['sent', 'skipped'], array_column($results, 'status'));
        $this->actingAs($manager)->postJson('/manage/staff/invitations/template', $this->content())->assertForbidden();

        $unauthorised = $this->person(['role' => 'facilitator', 'active' => true, 'permissions' => []]);
        $this->actingAs($unauthorised)->postJson('/manage/staff/invitations/send', $this->content(['users' => [$staff->getRouteKey()]]))->assertForbidden();
        $this->actingAs($unauthorised)->postJson('/manage/staff/invitations/preview', $this->content())->assertForbidden();
    }

    public function test_saved_default_email_is_used_by_later_invitations_and_audited(): void
    {
        $this->actingAs($this->admin)->postJson('/manage/staff/invitations/template', $this->content(['subject' => 'Saved subject for {first_name}', 'body' => 'Saved programme brief.']))
            ->assertOk();
        $this->assertSame('Saved subject for {first_name}', InvitationEmail::content($this->programme->fresh())['subject']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'invitation_email.update')->count());

        $person = $this->person(['name' => 'Later Person']);
        app(InvitationService::class)->send($person);
        Mail::assertSent(ProgrammeInvitation::class, fn ($mail) => $mail->subjectLine === 'Saved subject for Later'
            && str_contains($mail->bodyHtml, 'Saved programme brief.'));
    }

    public function test_new_person_invitation_from_the_modal_uses_the_edited_email_and_answers_json(): void
    {
        $this->actingAs($this->admin)->postJson('/manage/staff', $this->content([
            'invitation_only' => 1, 'role' => 'staff', 'name' => 'Modal Invitee', 'email' => 'modal@example.test',
            'subject' => 'Modal subject for {first_name}',
        ]))->assertOk()->assertJsonPath('message', 'Profile saved and activation invitation sent.');
        Mail::assertSent(ProgrammeInvitation::class, fn ($mail) => $mail->hasTo('modal@example.test') && $mail->subjectLine === 'Modal subject for Modal');

        $this->actingAs($this->admin)->postJson('/manage/staff', $this->content(['invitation_only' => 1, 'role' => 'staff', 'name' => 'Duplicate', 'email' => 'modal@example.test']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_message_markdown_cannot_inject_html_or_unsafe_links(): void
    {
        $html = InvitationEmail::html("Text <img src=x onerror=alert(1)> [bad](javascript:alert(1)) [good](https://example.org)\n\n<script>alert(1)</script>", ['{name}' => 'x']);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('href="https://example.org"', $html);
    }
}
