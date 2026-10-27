<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programme_days', function (Blueprint $t) {
            $t->time('checkout_opens')->nullable();
            $t->time('checkout_closes')->nullable();
        });
        Schema::create('attendance_devices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('active_user_id')->nullable()->unique()->constrained('users');
            $t->text('credential_id');
            $t->string('credential_hash', 64)->unique();
            $t->text('public_key');
            $t->unsignedBigInteger('sign_count')->default(0);
            $t->string('aaguid', 64)->nullable();
            $t->string('label', 100);
            $t->timestamp('registered_at');
            $t->timestamp('last_verified_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('attendance_device_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('programme_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->text('reason');
            $t->string('status')->default('pending');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->text('decision_reason')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamp('enrolled_at')->nullable();
            $t->timestamps();
        });
        Schema::create('attendance_challenges', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('programme_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('device_id')->nullable()->constrained('attendance_devices');
            $t->foreignId('programme_day_id')->nullable()->constrained();
            $t->string('purpose');
            $t->string('challenge', 255);
            $t->string('session_hash', 64);
            $t->timestamp('expires_at')->index();
            $t->timestamp('used_at')->nullable();
            $t->timestamps();
        });
        Schema::table('daily_attendance', function (Blueprint $t) {
            $t->string('staff_name')->nullable();
            $t->string('staff_number_snapshot')->nullable();
            $t->date('attendance_date')->nullable()->index();
            $t->string('punctuality_status')->nullable();
            $t->unsignedInteger('duration_seconds')->nullable();
            $t->foreignId('signin_device_id')->nullable()->constrained('attendance_devices');
            $t->foreignId('signout_device_id')->nullable()->constrained('attendance_devices');
            $t->boolean('signin_device_verified')->default(false);
            $t->boolean('signout_device_verified')->default(false);
            $t->boolean('signin_location_verified')->default(false);
            $t->boolean('signout_location_verified')->default(false);
            $t->text('signin_location')->nullable();
            $t->text('signout_location')->nullable();
        });
        Schema::create('attendance_audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('programme_id')->constrained();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->string('event')->index();
            $t->boolean('verified')->default(false);
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 1000)->nullable();
            $t->text('reason')->nullable();
            $t->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_audit_logs');
        Schema::table('daily_attendance', function (Blueprint $t) {
            $t->dropConstrainedForeignId('signin_device_id');
            $t->dropConstrainedForeignId('signout_device_id');
            $t->dropColumn(['staff_name', 'staff_number_snapshot', 'attendance_date', 'punctuality_status', 'duration_seconds', 'signin_device_verified', 'signout_device_verified', 'signin_location_verified', 'signout_location_verified', 'signin_location', 'signout_location']);
        });
        foreach (['attendance_challenges', 'attendance_device_requests', 'attendance_devices'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('programme_days', fn (Blueprint $t) => $t->dropColumn(['checkout_opens', 'checkout_closes']));
    }
};
