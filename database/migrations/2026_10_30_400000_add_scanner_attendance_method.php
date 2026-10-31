<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('daily_attendance', function (Blueprint $table) {
            $table->string('signin_method', 30)->nullable();
            $table->string('signout_method', 30)->nullable();
            $table->foreignId('signin_scanned_by')->nullable()->constrained('users');
            $table->foreignId('signout_scanned_by')->nullable()->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('daily_attendance', function (Blueprint $table) {
            $table->dropConstrainedForeignId('signin_scanned_by');
            $table->dropConstrainedForeignId('signout_scanned_by');
            $table->dropColumn(['signin_method', 'signout_method']);
        });
    }
};
