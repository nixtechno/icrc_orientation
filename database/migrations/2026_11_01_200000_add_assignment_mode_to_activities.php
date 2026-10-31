<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Records who an activity was assigned to (all, group or selected) so managers can see and change it.
        Schema::table('activities', fn (Blueprint $table) => $table->string('assignment_mode', 20)->nullable()->after('published'));
    }

    public function down(): void
    {
        Schema::table('activities', fn (Blueprint $table) => $table->dropColumn('assignment_mode'));
    }
};
