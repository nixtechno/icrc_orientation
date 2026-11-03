<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What staff thought of each programme day's training; asked once a day at the feedback time, one response each.
        if (! Schema::hasTable('training_feedback')) {
            Schema::create('training_feedback', function (Blueprint $table) {
                $table->id();
                $table->foreignId('programme_id')->constrained()->cascadeOnDelete();
                $table->foreignId('programme_day_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                foreach (['overall', 'facilitator', 'relevance', 'understanding', 'infrastructure', 'food'] as $step) {
                    $table->unsignedTinyInteger($step.'_rating');
                }
                $table->text('comment')->nullable();
                $table->timestamps();
                $table->unique(['programme_day_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('training_feedback');
    }
};
