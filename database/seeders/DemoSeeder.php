<?php

namespace Database\Seeders;

use App\Http\Controllers\ManagementController;
use App\Models\{Activity, Badge, DailyAttendance, Facilitator, Programme, ProgrammeDay, ProgrammeSession, SessionAnswer, SessionQuestion, Submission, TimetableProposal, User};
use App\Services\{SupplementImporter, TimetableImporter};
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\{DB, Hash, Storage};
use RuntimeException;

class DemoSeeder extends Seeder
{
    public int $staffCount = 200;
    public const PASSWORD = 'IcrcDemo!2026';

    public function run(): void
    {
        $database = DB::connection()->getDatabaseName();
        if (! app()->environment('local', 'demo', 'testing') || ! preg_match('/_(demo|testing)$/', $database)) {
            throw new RuntimeException('Demo data is allowed only in a local/testing database ending in _demo or _testing.');
        }
        if ($this->staffCount < 10 || $this->staffCount > 5000) {
            throw new RuntimeException('Choose between 10 and 5000 demo staff.');
        }
        if (User::where('email', 'not like', '%@icrc-demo.test')->exists()) {
            throw new RuntimeException('This database contains non-demo accounts. No demo data was written.');
        }
        $hash = Hash::make(self::PASSWORD);
        DB::transaction(function () use ($hash) {
            $programme = app(TimetableImporter::class)->run();
            if (! ($programme->settings['demo_mode'] ?? false)) {
                app(SupplementImporter::class)->run();
                $this->prepareTimeline($programme);
            }
            $programme->refresh();
            $enrolledAt = $programme->starts_on->copy()->subWeek();
            $admin = $this->account('admin', 'Demo Administrator', 'admin', true, [], $hash, $programme, $enrolledAt);
            $this->account('facilitator', 'Demo Programme Facilitator', 'facilitator', true, ManagementController::PERMISSIONS, $hash, $programme, $enrolledAt);
            $this->account('attendance', 'Demo Attendance Facilitator', 'facilitator', true, ['attendance', 'reports'], $hash, $programme, $enrolledAt);
            $this->account('assessments', 'Demo Assessment Facilitator', 'facilitator', true, ['assessments', 'qa'], $hash, $programme, $enrolledAt);
            $first = ['Amina', 'Chinedu', 'Fatima', 'Tunde', 'Zainab', 'Emeka', 'Ngozi', 'Ibrahim', 'Bola', 'Halima'];
            $last = ['Bello', 'Okafor', 'Adeyemi', 'Ibrahim', 'Eze', 'Lawal', 'Obi', 'Usman', 'Balogun', 'Yusuf'];
            $departments = ['ICT', 'Finance', 'Legal', 'Human Resources', 'Procurement', 'PPP Development', 'Compliance', 'Administration'];
            $staff = collect();
            for ($i = 1; $i <= $this->staffCount; $i++) {
                $active = $i % 10 !== 0;
                $user = $this->account(sprintf('staff%04d', $i), $first[($i - 1) % 10].' '.$last[intdiv($i - 1, 10) % 10].' (Demo '.sprintf('%04d', $i).')', 'staff', $active, [], $hash, $programme, $enrolledAt, $departments[($i - 1) % 8], 'Group '.chr(65 + (($i - 1) % 4)));
                $staff->push($user);
                if (! $active) {
                    $this->invitation($user, $i);
                }
            }
            $days = ProgrammeDay::orderBy('date')->get();
            foreach ($days as $day) {
                if (! $day->attendance_required || $day->date->isFuture()) continue;
                foreach ($staff->where('active', true)->values() as $i => $user) {
                    $variant = ($i + $day->number) % 10;
                    $status = match ($variant) { 0 => 'absent', 1 => 'excused', 2, 3 => 'late', default => 'present' };
                    $in = in_array($status, ['present', 'late']) ? $day->date->copy()->setTime($status === 'late' ? 9 : 8, 5 + ($i % 20)) : null;
                    if ($in?->isFuture()) continue;
                    $out = $in ? $day->date->copy()->setTime(16, 10 + ($i % 30)) : null;
                    if ($out?->isFuture() || $variant === 4) $out = null;
                    if (! $in && $day->date->isToday() && now('Africa/Lagos')->lt($day->date->copy()->setTime(10, 0))) continue;
                    DailyAttendance::firstOrCreate(['programme_id' => $programme->id, 'programme_day_id' => $day->id, 'user_id' => $user->id], ['status' => $status, 'checked_in_at' => $in, 'checked_out_at' => $out, 'staff_name' => $user->name, 'staff_number_snapshot' => $user->staff_number, 'attendance_date' => $day->date, 'punctuality_status' => $in ? ($status === 'late' ? 'late' : 'on_time') : null, 'duration_seconds' => $in && $out ? (int) $in->diffInSeconds($out) : null, 'notes' => 'DEMO: synthetic attendance for reports and performance testing. Device and location verification have not been performed.', 'recorded_by' => $admin->id]);
                }
            }
            $this->activities($programme, $staff);
            $this->content($programme, $admin, $staff);
            $this->command?->info('Demo data ready: '.$this->staffCount.' staff, 4 management accounts, mixed activities and reporting data.');
        });
    }

    private function account($slug, $name, $role, $active, $permissions, $hash, $programme, $enrolledAt, $department = 'Programme Office', $group = null): User
    {
        if ($slug === 'facilitator' && ! User::where('email', 'facilitator@icrc-demo.test')->exists()) {
            $legacy = User::where('email', 'supervisor@icrc-demo.test')->first();
            $legacy?->forceFill(['email' => 'facilitator@icrc-demo.test', 'name' => 'Demo Programme Facilitator'])->save();
        }
        $user = User::firstOrNew(['email' => $slug.'@icrc-demo.test']);
        if (! $user->exists) {
            $user->forceFill(['name' => $name, 'role' => $role, 'active' => $active, 'permissions' => $permissions, 'password' => $hash, 'department' => $department, 'designation' => $role === 'staff' ? 'Programme Officer' : 'Programme Manager', 'group_name' => $group, 'staff_number' => 'DEMO-'.strtoupper($slug), 'phone' => '+2340000000000', 'profile' => ['first_name' => explode(' ', $name)[0], 'surname' => explode(' ', $name)[1], 'programme_information' => 'Synthetic demo profile.'], 'email_verified_at' => $active ? now() : null, 'privacy_acknowledged_at' => $active ? now() : null])->save();
        }
        if (! $user->programmes()->whereKey($programme->id)->exists()) {
            $user->programmes()->attach($programme->id, ['created_at' => $enrolledAt, 'updated_at' => $enrolledAt]);
        }
        return $user;
    }

    private function prepareTimeline(Programme $programme): void
    {
        $start = now('Africa/Lagos')->startOfWeek()->subWeeks(2);
        $offset = (int) $programme->starts_on->diffInDays($start);
        $days = ProgrammeDay::orderBy('date', $offset > 0 ? 'desc' : 'asc')->get();
        foreach ($days as $day) {
            $day->update(['date' => $day->date->copy()->addDays($offset), 'attendance_required' => ! $day->induction_free, 'checkin_opens' => '07:30', 'checkin_closes' => '10:00', 'late_after' => '08:30', 'checkout_opens' => '15:30', 'checkout_closes' => '18:00']);
        }
        foreach (ProgrammeSession::all() as $session) {
            $session->update(['starts_at' => $session->starts_at?->addDays($offset), 'ends_at' => $session->ends_at?->addDays($offset)]);
        }
        foreach (TimetableProposal::with('session')->get() as $proposal) {
            $proposed = $proposal->proposed;
            foreach (['starts_at', 'ends_at'] as $key) {
                if (! empty($proposed[$key])) $proposed[$key] = Carbon::parse($proposed[$key])->addDays($offset)->format('Y-m-d H:i:s');
            }
            $proposal->update(['proposed' => $proposed, 'baseline' => $proposal->session ? SupplementImporter::snapshot($proposal->session) : null]);
        }
        $programme->update(['title' => 'DEMO — ICRC Staff Orientation', 'starts_on' => $start, 'ends_on' => $start->copy()->addWeeks(5)->addDays(4), 'settings' => [...$programme->settings, 'demo_mode' => true, 'demo_seeded_at' => now()->toIso8601String(), 'email_notifications' => false, 'venue' => 'Demo training venue', 'contact' => 'DEMO DATA — no real staff or verified attendance.']]);
    }

    private function invitation(User $user, int $index): void
    {
        DB::table('invitations')->updateOrInsert(['user_id' => $user->id, 'token_hash' => hash('sha256', 'demo-invitation-'.$user->email)], ['expires_at' => $index % 30 === 0 ? now()->subDay() : now()->addDays(3), 'revoked_at' => $index % 40 === 0 ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function activities(Programme $p, $staff): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $kind = ['quiz', 'assignment', 'presentation'][($i - 1) % 3];
            $a = Activity::firstOrCreate(['programme_id' => $p->id, 'title' => 'DEMO '.sprintf('%02d', $i).' — '.ucfirst($kind).': PPP fundamentals'], ['kind' => $kind, 'instructions' => 'DEMO exercise: explain how public-private partnerships deliver public value. For quiz questions, choose the best answer. For other work, submit text or a permitted file.', 'available_at' => $i === 10 ? now()->addDay() : now()->subDays(7), 'due_at' => $i === 11 ? now()->subDay() : now()->addDays(14), 'attempts' => 2, 'time_limit' => $kind === 'quiz' ? 30 : null, 'published' => $i !== 12, 'programme_week_id' => $p->weeks()->where('number', (($i - 1) % 6) + 1)->value('id')]);
            if ($kind === 'quiz' && ! $a->questions()->exists()) {
                foreach ([['multiple_choice', 'What does PPP stand for?', ['Public-Private Partnership', 'Public Procurement Policy', 'Private Project Planning'], 'Public-Private Partnership'], ['true_false', 'A PPP should allocate risk to the party best able to manage it.', ['True', 'False'], 'True'], ['multiple_choice', 'What should a PPP deliver?', ['Public value', 'Guaranteed private profits only', 'No public oversight'], 'Public value']] as [$type, $prompt, $options, $answer]) {
                    $a->questions()->create(['type' => $type, 'prompt' => $prompt, 'options' => $options, 'correct_answer' => $answer, 'points' => 5]);
                }
            }
            $participants = $staff->where('active', true)->filter(fn ($u, $index) => $i % 3 === 1 || $index % 4 === ($i % 4))->values();
            $a->participants()->syncWithoutDetaching($participants->pluck('id'));
            if (! $a->published || $a->available_at?->isFuture()) continue;
            foreach ($participants as $index => $user) {
                if ($index % 5 === 0) continue;
                $submitted = $index % 5 !== 1;
                $released = $index % 5 === 4;
                $answers = $a->questions->mapWithKeys(fn ($q) => [$q->id => $q->correct_answer])->all();
                Submission::firstOrCreate(['activity_id' => $a->id, 'user_id' => $user->id, 'attempt' => 1], ['started_at' => now()->subDays(3)->setTime(9, 0), 'submitted_at' => $submitted ? now()->subDays(3)->setTime(9, 20) : null, 'answers' => $kind === 'quiz' ? $answers : null, 'body' => $kind !== 'quiz' && $submitted ? 'DEMO submission: PPPs combine public oversight and private expertise to deliver infrastructure and public value.' : null, 'score' => $submitted && ($kind === 'quiz' || $released) ? ($kind === 'quiz' ? 15 : 75) : null, 'released_at' => $released ? now()->subDays(2) : null, 'feedback' => $released ? 'DEMO feedback: good understanding; develop the risk-allocation examples further.' : null]);
            }
        }
    }

    private function content(Programme $p, User $admin, $staff): void
    {
        Storage::disk('local')->put('demo/resources/ppp-guide.pdf', app(\App\Services\PdfService::class)->render('<html><body><h1>DEMO: PPP learning guide</h1><p>Synthetic learning material for download testing.</p><p>Public-private partnerships should deliver public value and allocate risk to the party best able to manage it.</p></body></html>'));
        for ($i = 1; $i <= 18; $i++) {
            DB::table('resources')->updateOrInsert(['programme_id' => $p->id, 'title' => 'DEMO learning material '.sprintf('%02d', $i)], ['uploaded_by' => $admin->id, 'category' => ['Reference', 'Slides', 'Case study'][($i - 1) % 3], 'week' => (($i - 1) % 6) + 1, 'path' => 'demo/resources/ppp-guide.pdf', 'filename' => 'DEMO-PPP-guide.pdf', 'published' => $i % 6 !== 0, 'created_at' => now()->subDays($i), 'updated_at' => now()]);
        }
        for ($i = 1; $i <= 12; $i++) {
            DB::table('announcements')->updateOrInsert(['programme_id' => $p->id, 'title' => 'DEMO programme announcement '.sprintf('%02d', $i)], ['body' => 'DEMO: review your timetable, prepare for the next session and check your assigned activities.', 'department' => $i % 4 === 0 ? 'ICT' : null, 'group_name' => $i % 5 === 0 ? 'Group A' : null, 'published_at' => $i === 11 ? now()->addDay() : ($i === 12 ? null : now()->subDays($i)), 'archived' => $i === 10, 'created_at' => now(), 'updated_at' => now()]);
        }
        $sessions = ProgrammeSession::whereIn('type', ['session', 'recap', 'presentation'])->orderBy('id')->take(12)->get();
        foreach ($sessions as $index => $session) {
            $facilitator = Facilitator::firstOrCreate(['programme_id' => $p->id, 'email' => 'facilitator'.($index + 1).'@icrc-demo.test'], ['name' => 'Demo Facilitator '.($index + 1), 'organisation' => 'Demo Public Service Academy', 'job_title' => 'Training Facilitator']);
            $facilitator->sessions()->syncWithoutDetaching([$session->id]);
            foreach ($staff->where('active', true)->take(10)->values() as $j => $user) {
                $question = SessionQuestion::firstOrCreate(['session_id' => $session->id, 'user_id' => $user->id, 'body' => 'DEMO question '.($j + 1).': How does risk allocation apply to this session?'], ['published' => $j % 3 !== 0, 'hidden' => $j === 9]);
                if ($question->published) SessionAnswer::firstOrCreate(['session_question_id' => $question->id, 'user_id' => $admin->id], ['body' => 'DEMO answer: allocate each risk to the party with the best capacity to manage it.', 'published' => true]);
            }
        }
        foreach ($staff->where('active', true)->take(30) as $user) {
            Badge::firstOrCreate(['programme_id' => $p->id, 'subject_key' => 'u:'.$user->id], ['user_id' => $user->id, 'category' => 'staff', 'snapshot' => ['name' => $user->name, 'department' => $user->department, 'designation' => $user->designation, 'photo_path' => null], 'generated_at' => now(), 'generated_by' => $admin->id]);
            DB::table('notifications')->updateOrInsert(['id' => (string) \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_DNS, 'demo-notification-'.$user->email)], ['type' => \App\Notifications\ProgrammeUpdate::class, 'notifiable_type' => User::class, 'notifiable_id' => $user->id, 'data' => json_encode(['title' => 'DEMO: welcome to your orientation', 'body' => 'Explore your timetable, attendance records and assigned assessments.', 'url' => '/timetable']), 'read_at' => null, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('audit_logs')->updateOrInsert(['action' => 'demo.seed', 'subject' => 'demo-dataset'], ['user_id' => $admin->id, 'after' => json_encode(['staff_count' => $this->staffCount, 'synthetic' => true]), 'created_at' => now(), 'updated_at' => now()]);
    }
}
