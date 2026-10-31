<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When the assigned person was emailed about the activity, so each person is told once.
        Schema::table('activity_assignments', fn (Blueprint $table) => $table->timestamp('notified_at')->nullable());
        // Work already published before emails existed is treated as already announced.
        DB::table('activity_assignments')
            ->whereIn('activity_id', DB::table('activities')->where('published', true)->select('id'))
            ->update(['notified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('activity_assignments', fn (Blueprint $table) => $table->dropColumn('notified_at'));
    }
};
