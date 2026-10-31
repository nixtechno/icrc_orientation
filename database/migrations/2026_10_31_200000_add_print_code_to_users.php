<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('print_code', 20)->nullable()->unique();
        });
        // Give existing people a badge print code that never matches any badge QR ID.
        DB::table('users')->whereNull('print_code')->orderBy('id')->pluck('id')->each(function ($id) {
            do {
                $code = (string) random_int(1, 9);
                for ($i = 1; $i < 16; $i++) $code .= random_int(0, 9);
            } while (DB::table('users')->where('qr_code', $code)->orWhere('print_code', $code)->exists());
            DB::table('users')->where('id', $id)->update(['print_code' => $code]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['print_code']);
            $table->dropColumn('print_code');
        });
    }
};
