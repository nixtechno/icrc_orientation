<?php

use App\Support\PublicSlug;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        foreach (PublicSlug::TABLES as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->string('slug', 120)->nullable()->unique());
            DB::table($table)->orderBy('id')->chunkById(500, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update(['slug' => PublicSlug::make($table, (array) $row)]);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (PublicSlug::TABLES as $table) Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('slug'));
    }
};
