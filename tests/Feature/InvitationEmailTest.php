<?php

namespace Tests\Feature;

use App\Mail\ProgrammeInvitation;
use App\Models\User;
use App\Services\{InvitationService, TimetableImporter};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail};
use Tests\TestCase;

class InvitationEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_invitation_sends_personal_branded_email_with_the_real_single_use_token_and_expiry(): void
    {
        $programme = app(TimetableImporter::class)->run();
        $user = User::factory()->create(['name' => 'Amina Example', 'email' => 'amina@example.test', 'role' => 'facilitator', 'active' => false]);
        $user->programmes()->attach($programme->id);
        $this->actingAs(User::factory()->create(['role' => 'admin', 'active' => true]));
        Mail::fake();
        app(InvitationService::class)->send($user);
        Mail::assertSent(ProgrammeInvitation::class, function ($mail) use ($user) {
            $this->assertTrue($mail->hasTo($user->email));
            $token = basename(parse_url($mail->activationUrl, PHP_URL_PATH));
            $record = DB::table('invitations')->where('token_hash', hash('sha256', $token))->first();
            $this->assertNotNull($record);
            $this->assertSame($mail->expiresAt->format('Y-m-d H:i:s'), $record->expires_at);
            $html = $mail->render();
            $this->assertStringContainsString('WELCOME TO THE ICRC SCODEX TRAINING PROGRAMME!', $html);
            $this->assertStringContainsString('CBN, Central Area, Abuja', $html);
            $this->assertStringContainsString('href="https://icrcscodex.bcommz.com"', $html);
            $this->assertStringContainsString('amina@example.test', $html);
            $this->assertStringContainsString('Dear Amina Example,', $html);
            $this->assertSame(5, substr_count($html, 'Monday, 2nd November 2026')); // Four in the body, one in the inbox preview line.
            $this->assertStringNotContainsString('{start_date}', $html);
            $this->assertSame('Welcome to the ICRC SCODEX Training Programme | Activate your account', $mail->subjectLine);
            $this->assertStringContainsString('Select the <strong style="color:#2d4c34;font-weight:600">Create your password &amp; sign in</strong> button below.', $html);
            $this->assertSame(1, substr_count($html, 'SCODEX Training Coordination Team'));
            $this->assertStringNotContainsString('ICRC Programme Team', $html);
            $this->assertStringContainsString('Create your password &amp; sign in', $html);
            $this->assertStringContainsString($mail->activationUrl, $html);
            $this->assertMatchesRegularExpression('/src="(?:cid:|data:image\/)/', $html);
            $this->assertStringNotContainsString("\u{FFFD}", $html);
            $this->assertFileExists($mail->logoPath);
            return true;
        });
        $oldId = DB::table('invitations')->value('id');
        app(InvitationService::class)->send($user);
        $this->assertNotNull(DB::table('invitations')->where('id', $oldId)->value('revoked_at'));
        Mail::assertSent(ProgrammeInvitation::class, 2);
    }

    public function test_real_email_contains_html_plain_text_and_an_embedded_logo_without_external_images(): void
    {
        $programme = app(TimetableImporter::class)->run();
        $user = User::factory()->make(['name' => 'Demo Recipient', 'role' => 'staff']);
        $mail = new ProgrammeInvitation($user, $programme, 'https://orientation.example.test/invitation/preview', now()->addDays(3));
        $sent = Mail::mailer('array')->to('preview@example.test')->send($mail);
        $message = $sent->getSymfonySentMessage()->getOriginalMessage();
        $this->assertStringContainsString('Create your password &amp; sign in', $message->getHtmlBody());
        $this->assertStringContainsString('https://orientation.example.test/invitation/preview', $message->getTextBody());
        $this->assertStringContainsString('cid:', $message->getHtmlBody());
        // Inline logo and badge printing QR, plus the QR as a downloadable attachment.
        $parts = collect($message->getAttachments());
        $this->assertCount(3, $parts);
        $this->assertSame(2, $parts->filter(fn ($part) => $part->getDisposition() === 'inline')->count());
        $this->assertSame(['ICRC-SCODEX-QR-code.png'], $parts->filter(fn ($part) => $part->getDisposition() === 'attachment')->map->getFilename()->values()->all());
        $this->assertTrue($parts->every(fn ($part) => $part->getMediaType() === 'image'));
    }
}
