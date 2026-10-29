<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('programme_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->string('name', 150);
            $table->string('slug')->unique();
            $table->timestamps();
            $table->unique(['programme_id', 'name']);
        });
        Schema::create('assessment_group_user', function (Blueprint $table) {
            $table->foreignId('assessment_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['assessment_group_id', 'user_id']);
        });
        Schema::table('activities', function (Blueprint $table) {
            $table->foreignId('assessment_group_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('activities', fn (Blueprint $table) => $table->dropConstrainedForeignId('assessment_group_id'));
        Schema::dropIfExists('assessment_group_user');
        Schema::dropIfExists('assessment_groups');
    }
};
