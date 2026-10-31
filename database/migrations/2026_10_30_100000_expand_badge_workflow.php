<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            $table->string('collection_code', 24)->nullable()->unique();
            $table->timestamp('collected_at')->nullable();
            $table->timestamp('materials_collected_at')->nullable();
        });
        DB::table('badges')->orderBy('id')->eachById(function ($badge) {
            DB::table('badges')->where('id', $badge->id)->update(['collection_code' => 'SCX-'.strtoupper(bin2hex(random_bytes(6)))]);
        });
    }

    public function down(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            $table->dropUnique(['collection_code']);
            $table->dropColumn(['collection_code', 'collected_at', 'materials_collected_at']);
        });
    }
};
