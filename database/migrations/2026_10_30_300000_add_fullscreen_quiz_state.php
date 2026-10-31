<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->timestamp('fullscreen_started_at')->nullable();
            $table->string('launch_token_hash',64)->nullable();
            $table->unsignedInteger('draft_version')->default(0);
            $table->string('submission_reason', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('submissions', fn (Blueprint $table) => $table->dropColumn(['fullscreen_started_at','launch_token_hash','draft_version','submission_reason']));
    }
};
