<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('qr_code', 20)->nullable()->unique();
        });
        // Give existing people a badge QR ID; new people get one when they are created.
        DB::table('users')->whereNull('qr_code')->orderBy('id')->pluck('id')->each(function ($id) {
            do {
                $code = (string) random_int(1, 9);
                for ($i = 1; $i < 16; $i++) $code .= random_int(0, 9);
            } while (DB::table('users')->where('qr_code', $code)->exists());
            DB::table('users')->where('id', $id)->update(['qr_code' => $code]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['qr_code']);
            $table->dropColumn('qr_code');
        });
    }
};
