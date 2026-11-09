<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How far through an assessment attempt the person has moved. Questions before this position are locked.
        if (! Schema::hasColumn('submissions', 'question_position')) {
            Schema::table('submissions', function (Blueprint $table) {
                $table->unsignedSmallInteger('question_position')->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('submissions', 'question_position')) {
            Schema::table('submissions', fn (Blueprint $table) => $table->dropColumn('question_position'));
        }
    }
};
