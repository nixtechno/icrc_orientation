<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When managers released an assessment's correct answers and explanations to staff.
        if (! Schema::hasColumn('activities', 'answers_released_at')) {
            Schema::table('activities', function (Blueprint $table) {
                $table->timestamp('answers_released_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('activities', 'answers_released_at')) {
            Schema::table('activities', fn (Blueprint $table) => $table->dropColumn('answers_released_at'));
        }
    }
};
