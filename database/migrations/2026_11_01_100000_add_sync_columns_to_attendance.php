<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['daily_attendance', 'attendance_audit_logs'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                // Identifies the same record on the venue server and the live site, whose numeric ids differ.
                $t->uuid('sync_uuid')->nullable()->unique();
                // Where the record was first written: "online" or the venue server's name.
                $t->string('origin', 60)->nullable();
                // On the venue server: when this version was accepted by the live site.
                $t->timestamp('synced_at')->nullable();
                if ($table === 'daily_attendance') {
                    // Raised on every save; the venue server uploads a record while synced_version is behind it.
                    $t->unsignedInteger('sync_version')->default(0);
                    $t->unsignedInteger('synced_version')->nullable();
                    $t->string('sync_error', 500)->nullable();
                }
            });
            DB::table($table)->whereNull('sync_uuid')->orderBy('id')->select('id')->each(function ($row) use ($table) {
                DB::table($table)->where('id', $row->id)->update(['sync_uuid' => (string) Str::uuid()]);
            });
        }
    }

    public function down(): void
    {
        foreach (['daily_attendance', 'attendance_audit_logs'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropUnique(['sync_uuid']);
                $t->dropColumn($table === 'daily_attendance'
                    ? ['sync_uuid', 'origin', 'synced_at', 'sync_version', 'synced_version', 'sync_error']
                    : ['sync_uuid', 'origin', 'synced_at']);
            });
        }
    }
};
