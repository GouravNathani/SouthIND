<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branch-scoped user tags.
 *
 * Deliberately early in the order: support chat filters by tag, bonus codes
 * target audiences by tag, and winner-streak boards scope competitors by tag.
 * Everything downstream depends on these two tables existing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('name');
            $table->string('color', 32)->default('#6366f1');
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();

            $table->unique(['branch_id', 'name']);
        });

        Schema::create('tag_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['tag_id', 'user_id']);
            // Reverse lookup: every user carrying a given tag (streak boards,
            // bonus-code audiences).
            $table->index(['user_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_user');
        Schema::dropIfExists('tags');
    }
};
