<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The published activity an announcement tells its assigned staff about; only they see it.
        if (! Schema::hasColumn('announcements', 'activity_id')) {
            Schema::table('announcements', fn (Blueprint $table) => $table->foreignId('activity_id')->nullable()->unique()->constrained()->cascadeOnDelete());
        }
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('activity_id');
        });
    }
};
