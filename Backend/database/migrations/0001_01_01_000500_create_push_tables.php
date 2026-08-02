<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Web-push subscriptions, kept in two tables because admins and users are
 * separate authenticatables with separate VAPID audiences.
 *
 * `endpoint` is VARCHAR(512), NOT the Laravel default 255. On a sibling panel
 * the 255 column silently truncated real FCM endpoints and produced ~979
 * "Data too long for column 'endpoint'" 500s before anyone noticed. Modern
 * push endpoints routinely exceed 255 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->string('endpoint', 512)->unique();
            $table->string('public_key');
            $table->string('auth_token');
            $table->string('content_encoding')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });

        Schema::create('user_push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('endpoint', 512)->unique();
            $table->string('public_key');
            $table->string('auth_token');
            $table->string('content_encoding')->nullable();
            $table->string('user_agent')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();

            // Broadcasting a winner announcement to a whole branch.
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_push_subscriptions');
        Schema::dropIfExists('push_subscriptions');
    }
};
