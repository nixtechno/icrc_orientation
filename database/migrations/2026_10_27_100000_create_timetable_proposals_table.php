<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_proposals', function (Blueprint $table) {
            $table->id();
            $table->string('source_key')->unique();
            $table->foreignId('programme_day_id')->constrained();
            $table->foreignId('session_id')->nullable()->constrained('programme_sessions');
            $table->json('source');
            $table->json('proposed');
            $table->json('baseline')->nullable();
            $table->json('warnings');
            $table->string('status')->default('pending')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_proposals');
    }
};
