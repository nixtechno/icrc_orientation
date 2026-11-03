<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What staff thought of the facilitator, material, IT and portal after an assessment; one response each.
        if (! Schema::hasTable('assessment_feedback')) {
            Schema::create('assessment_feedback', function (Blueprint $table) {
                $table->id();
                $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->unsignedTinyInteger('facilitator_rating');
                $table->unsignedTinyInteger('content_rating');
                $table->unsignedTinyInteger('infrastructure_rating');
                $table->unsignedTinyInteger('portal_rating');
                $table->text('comment')->nullable();
                $table->timestamps();
                $table->unique(['activity_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_feedback');
    }
};
