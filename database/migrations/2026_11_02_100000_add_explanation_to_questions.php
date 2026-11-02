<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Why the correct answer is right, shown to staff once their result is released.
        if (! Schema::hasColumn('questions', 'explanation')) {
            Schema::table('questions', fn (Blueprint $table) => $table->text('explanation')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('questions', fn (Blueprint $table) => $table->dropColumn('explanation'));
    }
};
