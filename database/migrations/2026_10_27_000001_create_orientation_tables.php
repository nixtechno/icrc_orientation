<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('staff');
            $t->boolean('active')->default(false);
            $t->json('permissions')->nullable();
            foreach (['phone', 'department', 'designation', 'group_name'] as $c) {
                $t->string($c)->nullable();
            }$t->string('staff_number')->nullable()->unique();
        });
        Schema::create('programmes', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('title');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('timezone')->default('Africa/Lagos');
            $t->json('settings')->nullable();
            $t->timestamps();
        });
        Schema::create('enrolments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('programme_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->unique(['programme_id', 'user_id']);
            $t->timestamps();
        });
        Schema::create('programme_weeks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('programme_id')->constrained();
            $t->unsignedInteger('number');
            $t->string('theme');
            $t->unique(['programme_id', 'number']);
            $t->timestamps();
        });
        Schema::create('programme_days', function (Blueprint $t) {
            $t->id();
            $t->foreignId('programme_week_id')->constrained();
            $t->unsignedInteger('number')->unique();
            $t->date('date')->unique();
            $t->text('source_label');
            $t->boolean('induction_free')->default(false);
            $t->timestamps();
        });
        Schema::create('programme_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('programme_day_id')->constrained();
            $t->string('source_key')->unique();
            $t->text('title')->nullable();
            $t->text('facilitator')->nullable();
            $t->string('venue')->nullable();
            $t->string('type')->default('session');
            $t->dateTime('starts_at')->nullable()->index();
            $t->dateTime('ends_at')->nullable();
            $t->unsignedInteger('actual_minutes')->nullable();
            $t->json('source');
            $t->json('warnings');
            $t->string('status')->default('published');
            $t->boolean('reviewed')->default(false);
            $t->boolean('attendance_required')->default(false);
            $t->timestamps();
        });
        Schema::create('attendance', function (Blueprint $t) {
            $t->id();
            $t->foreignId('session_id')->constrained('programme_sessions');
            $t->foreignId('user_id')->constrained();
            $t->string('status');
            $t->dateTime('checked_in_at')->nullable();
            $t->dateTime('checked_out_at')->nullable();
            $t->text('notes')->nullable();
            $t->unique(['session_id', 'user_id']);
            $t->timestamps();
        });
        Schema::create('invitations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('token_hash')->unique();
            $t->dateTime('expires_at');
            $t->dateTime('used_at')->nullable();
            $t->dateTime('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('resources', function (Blueprint $t) {
            $t->id();
            $t->foreignId('programme_id')->constrained();
            $t->foreignId('uploaded_by')->constrained('users');
            $t->string('title');
            $t->string('category')->nullable();
            $t->unsignedInteger('week')->nullable();
            $t->string('path')->nullable();
            $t->string('filename')->nullable();
            $t->text('url')->nullable();
            $t->boolean('published')->default(false);
            $t->timestamps();
        });
        Schema::create('announcements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('programme_id')->constrained();
            $t->string('title');
            $t->text('body');
            $t->string('department')->nullable();
            $t->string('group_name')->nullable();
            $t->dateTime('published_at')->nullable();
            $t->boolean('archived')->default(false);
            $t->timestamps();
        });
        Schema::create('activities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('programme_id')->constrained();
            $t->string('kind');
            $t->string('title');
            $t->text('instructions');
            $t->dateTime('available_at')->nullable();
            $t->dateTime('due_at');
            $t->unsignedInteger('attempts')->default(1);
            $t->unsignedInteger('time_limit')->nullable();
            $t->boolean('published')->default(false);
            $t->timestamps();
        });
        Schema::create('activity_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('activity_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->unique(['activity_id', 'user_id']);
        });
        Schema::create('questions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('activity_id')->constrained();
            $t->string('type');
            $t->text('prompt');
            $t->json('options')->nullable();
            $t->text('correct_answer')->nullable();
            $t->unsignedInteger('points')->default(1);
            $t->timestamps();
        });
        Schema::create('submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('activity_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->unsignedInteger('attempt');
            $t->json('answers')->nullable();
            $t->text('body')->nullable();
            $t->string('path')->nullable();
            $t->string('filename')->nullable();
            $t->dateTime('started_at');
            $t->dateTime('submitted_at')->nullable();
            $t->decimal('score', 8, 2)->nullable();
            $t->text('feedback')->nullable();
            $t->dateTime('released_at')->nullable();
            $t->unique(['activity_id', 'user_id', 'attempt']);
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('action');
            $t->string('subject');
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->timestamps();
        });
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->morphs('notifiable');
            $t->text('data');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['notifications', 'audit_logs', 'submissions', 'questions', 'activity_assignments', 'activities', 'announcements', 'resources', 'invitations', 'attendance', 'programme_sessions', 'programme_days', 'programme_weeks', 'enrolments', 'programmes'] as $name) {
            Schema::dropIfExists($name);
        }Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'active', 'permissions', 'phone', 'staff_number', 'department', 'designation', 'group_name']));
    }
};
