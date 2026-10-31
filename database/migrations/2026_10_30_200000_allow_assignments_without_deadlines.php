<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', fn (Blueprint $table) => $table->dateTime('due_at')->nullable()->change());
    }

    public function down(): void
    {
        if (DB::table('activities')->whereNull('due_at')->exists()) throw new \RuntimeException('Assign deadlines to activities without a due date before rolling back this migration.');
        Schema::table('activities', fn (Blueprint $table) => $table->dateTime('due_at')->nullable(false)->change());
    }
};
