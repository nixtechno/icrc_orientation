<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->text('profile')->nullable(); // encrypted by the model
            $t->string('photo_path')->nullable();
            $t->timestamp('privacy_acknowledged_at')->nullable();
            $t->text('responsibilities')->nullable();
        });
        Schema::table('programme_days', function (Blueprint $t) {
            $t->boolean('attendance_required')->default(false);
            $t->boolean('cancelled')->default(false);
            $t->time('checkin_opens')->nullable();
            $t->time('checkin_closes')->nullable();
            $t->time('late_after')->nullable();
        });
        // Retain historical per-session records for administrator reconciliation, never calculate from them.
        Schema::rename('attendance', 'legacy_session_attendance');
        Schema::create('daily_attendance', function (Blueprint $t) {
            $t->id();
            $t->foreignId('programme_id')->constrained();
            $t->foreignId('programme_day_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('status')->default('pending');
            $t->dateTime('checked_in_at')->nullable();
            $t->dateTime('checked_out_at')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users');
            $t->unique(['programme_id', 'programme_day_id', 'user_id'], 'daily_attendance_identity');
            $t->timestamps();
        });
        Schema::create('facilitators', function (Blueprint $t) {
            $t->id();
            $t->foreignId('programme_id')->constrained();
            $t->string('name');
            $t->string('organisation')->nullable();
            $t->string('job_title')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('photo_path')->nullable();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->timestamps();
        });
        Schema::create('facilitator_session', function (Blueprint $t) {
            $t->id();
            $t->foreignId('facilitator_id')->constrained();
            $t->foreignId('session_id')->constrained('programme_sessions');
            $t->unique(['facilitator_id', 'session_id']);
        });
        Schema::create('session_questions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('session_id')->constrained('programme_sessions');
            $t->foreignId('user_id')->constrained();
            $t->text('body');
            $t->boolean('published')->default(false);
            $t->boolean('hidden')->default(false);
            $t->timestamps();
        });
        Schema::create('session_answers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('session_question_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->text('body');
            $t->boolean('published')->default(false);
            $t->boolean('hidden')->default(false);
            $t->timestamps();
        });
        Schema::table('activities', function (Blueprint $t) {
            $t->foreignId('programme_week_id')->nullable()->constrained();
            $t->foreignId('session_id')->nullable()->constrained('programme_sessions');
        });
        Schema::create('badges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('programme_id')->constrained();
            $t->string('subject_key');
            $t->foreignId('user_id')->nullable()->constrained();
            $t->foreignId('facilitator_id')->nullable()->constrained();
            $t->string('category');
            $t->json('snapshot');
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('generated_at');
            $t->timestamp('printed_at')->nullable();
            $t->foreignId('generated_by')->constrained('users');
            $t->unique(['programme_id', 'subject_key']);
            $t->timestamps();
        });
        Schema::create('participant_imports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('programme_id')->constrained();
            $t->string('role');
            $t->json('rows');
            $t->json('issues');
            $t->json('summary')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['participant_imports', 'badges', 'session_answers', 'session_questions', 'facilitator_session', 'facilitators', 'daily_attendance'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::rename('legacy_session_attendance', 'attendance');
        Schema::table('activities', function (Blueprint $t) {
            $t->dropConstrainedForeignId('programme_week_id');
            $t->dropConstrainedForeignId('session_id');
        });
        Schema::table('programme_days', fn (Blueprint $t) => $t->dropColumn(['attendance_required', 'cancelled', 'checkin_opens', 'checkin_closes', 'late_after']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['profile', 'photo_path', 'privacy_acknowledged_at', 'responsibilities']));
    }
};
