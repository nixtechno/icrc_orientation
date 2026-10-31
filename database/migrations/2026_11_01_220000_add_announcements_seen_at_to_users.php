<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When the person last opened Announcements; anything published after it counts as unread.
        Schema::table('users', fn (Blueprint $table) => $table->timestamp('announcements_seen_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('announcements_seen_at'));
    }
};
