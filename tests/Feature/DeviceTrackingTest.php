<?php

namespace Tests\Feature;

use App\Models\{Activity, Programme, Question, Submission, User};
use App\Services\{DeviceLabel, QuizAttemptService, TimetableImporter};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_devices_are_named_by_type_brand_system_and_browser(): void
    {
        $samsungTab = 'Mozilla/5.0 (Linux; Android 13; SM-X200) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
        $this->assertSame('Tablet · Samsung SM-X200 · Android 13 · Chrome', DeviceLabel::line(['ua' => $samsungTab]));
        // Chrome now hides the model in the user agent ("K"); the model sent by the page is used instead.
        $hidden = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36';
        $this->assertSame('Phone · Tecno TECNO KI5 · Android 10 · Chrome', DeviceLabel::line(['ua' => $hidden, 'model' => 'TECNO KI5']));
        $ipad = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';
        $this->assertSame('Tablet · Apple · iPadOS · Safari', DeviceLabel::line(['ua' => $ipad, 'touch' => 5]));
        $this->assertSame('Computer · Apple · macOS · Safari', DeviceLabel::line(['ua' => $ipad]));
        $this->assertSame('Computer · Windows · Edge', DeviceLabel::line(['ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36 Edg/120.0']));
        $this->assertSame('Device not recorded', DeviceLabel::line(null));
    }

    public function test_the_device_is_recorded_and_automatic_submissions_are_grouped_by_device(): void
    {
        app(TimetableImporter::class)->run();
        $staff = User::factory()->create(['role' => 'staff', 'active' => true]); $staff->programmes()->attach(Programme::first()->id);
        $quiz = Activity::create(['programme_id' => Programme::first()->id, 'kind' => 'quiz', 'title' => 'Day 1 Assessment', 'instructions' => 'Answer all', 'due_at' => now()->addDays(3), 'attempts' => 1, 'published' => true]);
        $quiz->participants()->attach($staff->id);
        Question::create(['activity_id' => $quiz->id, 'type' => 'true_false', 'prompt' => 'PPPs share risk.', 'options' => ['True', 'False'], 'correct_answer' => 'True', 'points' => 1]);

        $tablet = 'Mozilla/5.0 (Linux; Android 13; SM-X200) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
        $this->actingAs($staff)->withHeader('User-Agent', $tablet)
            ->postJson('/activities/'.$quiz->slug.'/start', ['fullscreen' => 1, 'policy_acknowledged' => 1, 'launch_token' => 'device-launch-token-123', 'device' => json_encode(['model' => 'SM-X200', 'touch' => 10, 'screen' => '800x1340'])])->assertOk();
        $submission = Submission::firstOrFail();
        $this->assertSame(['ua' => $tablet, 'model' => 'SM-X200', 'touch' => 10, 'screen' => '800x1340'], $submission->device);
        app(QuizAttemptService::class)->finalize($submission->load('activity.questions'), 'fullscreen_exit', []);

        $admin = User::factory()->create(['role' => 'admin', 'active' => true]); $admin->programmes()->attach(Programme::first()->id);
        $this->actingAs($admin)->get('/activities/'.$quiz->slug)->assertOk()
            ->assertSee('Tablet · Samsung SM-X200 · Android 13 · Chrome')->assertSee('Auto &middot; Fullscreen Exit', false)
            ->assertSeeInOrder(['Automatic submissions by device', 'Tablet · Samsung · Android 13', '100%', 'SM-X200']);
    }
}
