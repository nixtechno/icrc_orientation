<?php

use App\Models\User;
use App\Services\Access;
use App\Services\SupplementImporter;
use App\Services\TimetableImporter;
use Illuminate\Support\Facades\Artisan;

Artisan::command('icrc:seed-demo {--staff=200 : Number of synthetic staff (10-5000)}', function () {
    $count = filter_var($this->option('staff'), FILTER_VALIDATE_INT);
    if ($count === false || $count < 10 || $count > 5000) {
        $this->error('Choose --staff=10 through --staff=5000.');
        return 1;
    }
    $seeder = app(\Database\Seeders\DemoSeeder::class);
    $seeder->staffCount = $count;
    $seeder->setCommand($this);
    $seeder->run();
});

Artisan::command('attendance:retention-report', function () {
    $p = Access::programme();
    $s = $p->settings['attendance_security'] ?? [];
    foreach (['location_retention_days', 'audit_retention_days'] as $key) {
        $this->info($key.': '.($s[$key] ?? 'Not configured'));
    }
    $this->info('Read-only report. No attendance or audit data deleted.');
});

Artisan::command('icrc:import-supplement', function () {
    $count = app(SupplementImporter::class)->run();
    $this->info("Staged {$count} new source proposals; published timetable unchanged.");
});
Artisan::command('icrc:import-timetable', function () {
    $p = app(TimetableImporter::class)->run();
    $this->info('Imported '.$p->title.' without overwriting existing records.');
});
Artisan::command('icrc:create-admin', function () {
    $name = $this->ask('Administrator name');
    $email = $this->ask('Administrator email');
    $password = $this->secret('Password (8+ characters)');
    if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        $this->error('Invalid email or weak password.');

        return 1;
    }if (User::where('email', $email)->exists()) {
        $this->error('Email already exists.');

        return 1;
    }$p = app(TimetableImporter::class)->run();
    $u = new User;
    $u->forceFill(['name' => $name, 'email' => $email, 'password' => $password, 'role' => 'admin', 'active' => true, 'email_verified_at' => now()])->save();
    $u->programmes()->attach($p->id);
    $this->info('Administrator created. No default credentials are installed.');
});
