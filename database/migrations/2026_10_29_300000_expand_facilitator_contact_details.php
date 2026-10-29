<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facilitators', function (Blueprint $table) {
            $table->string('department')->nullable();
            $table->string('alternative_phone', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('facilitators', fn (Blueprint $table) => $table->dropColumn(['department', 'alternative_phone']));
    }
};
