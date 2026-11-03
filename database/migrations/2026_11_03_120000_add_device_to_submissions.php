<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The device an assessment attempt was taken on, so automatic submissions can be traced to a brand or type.
        if (! Schema::hasColumn('submissions', 'device')) {
            Schema::table('submissions', function (Blueprint $table) {
                $table->json('device')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('submissions', 'device')) {
            Schema::table('submissions', fn (Blueprint $table) => $table->dropColumn('device'));
        }
    }
};
