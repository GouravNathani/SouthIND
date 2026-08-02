<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branding and configuration: one global row plus one per-branch row, and the
 * promotional banners the User app rotates through.
 *
 * `global_settings` is a singleton row read on every public request; the
 * per-branch `app_settings` overrides it. Both are cached (see
 * App\Support\Cache\GlobalSettingCache / AppSettingCache) and answered with an
 * ETag, so the SPAs' polling costs almost nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_settings', function (Blueprint $table) {
            $table->id();
            $table->string('instagram_link', 2048)->nullable();
            $table->string('telegram_link', 2048)->nullable();
            $table->string('whatsapp_link', 2048)->nullable();
            // Masks user phone numbers in admin views; OFF until a super admin
            // opts in.
            $table->boolean('mask_user_phone')->default(false);
            // Puts the user panel into maintenance mode; OFF by default. The
            // public settings endpoint stays open while this is on so the User
            // app can still render a branded maintenance screen.
            $table->boolean('user_panel_maintenance_enabled')->default(false);
            $table->boolean('bonus_deposit_enabled')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('app_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('deposit_offer_text', 255)->nullable();
            $table->string('withdrawal_offer_text', 255)->nullable();
            $table->string('instagram_link', 2048)->nullable();
            $table->string('whatsapp_number', 64)->nullable();
            $table->string('whatsapp_link', 2048)->nullable();
            $table->string('telegram_link', 2048)->nullable();
            $table->string('logo_path', 2048)->nullable();
            $table->foreignId('owner_admin_id')->constrained('admins')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('deposit_wa', 64)->nullable();
            $table->string('withdrawal_wa', 64)->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            // Per-branch support chat auto-reply; OFF until a branch opts in.
            // There is no admin UI for these — deliberately API/DB only.
            $table->boolean('support_auto_reply_enabled')->default(false);
            $table->text('support_auto_reply_text')->nullable();
            $table->boolean('bonus_deposit_enabled')->default(false);
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();

            $table->unique('branch_id');
            $table->index(['owner_admin_id', 'created_at']);
        });

        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('image_path');
            $table->boolean('is_logo')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();

            // The exact shape of the public banner query: active banners for a
            // branch, in display order.
            $table->index(['branch_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('global_settings');
    }
};
