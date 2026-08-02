<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bonus codes and their redemptions.
 *
 * Two abuse controls are enforced in the schema itself rather than only in code:
 *
 *   - `unique(bonus_code_id, user_id, period_key)` is what makes "once per day /
 *     week / month" real. period_key is derived from the code's frequency, so
 *     the database rejects a second redemption in the same window even if two
 *     requests race.
 *   - `redeemed_count` is a denormalised counter so the total cap can be checked
 *     under a row lock instead of a COUNT(*) that two concurrent redemptions
 *     could both pass.
 *
 * `deposits.bonus_code_id` is attached here because the two tables reference
 * each other: a deposit names the code applied to it, and a redemption names
 * the deposit that unlocks it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bonus_codes', function (Blueprint $table) {
            $table->id();
            // Matched case-insensitively; always stored uppercase.
            $table->string('code', 40)->unique();
            // NULL = usable in every branch (super admin "global" code).
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('title', 120)->nullable();
            $table->text('terms_text')->nullable();

            $table->decimal('reward_amount', 12, 2)->default(0);
            $table->string('reward_label', 40)->nullable();

            // once | daily | weekly | monthly | unlimited
            $table->string('frequency', 20)->default('once');
            // How many redemptions a single user gets inside one period.
            $table->unsignedInteger('per_user_limit')->default(1);
            // Total redemptions across all users. NULL = no cap.
            $table->unsignedInteger('max_redemptions')->nullable();
            // Denormalised so the cap can be checked with a row lock.
            $table->unsignedInteger('redeemed_count')->default(0);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 20)->default('active'); // active | paused

            // Reward lands as `fulfilled` immediately instead of queueing for an
            // admin decision.
            $table->boolean('auto_approve')->default(false);
            // Code can only be applied to a deposit, never redeemed standalone.
            $table->boolean('requires_deposit')->default(false);
            // Minimum approved deposit amount that unlocks the reward.
            $table->decimal('min_deposit', 12, 2)->default(0);
            // Only accounts created within N days of signup may redeem.
            $table->unsignedInteger('new_user_days')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('created_by_role', 20)->nullable();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();

            $table->index(['branch_id', 'status']);
        });

        // Optional audience targeting: restrict a code to users carrying any of
        // these tags. No rows = open to everyone.
        Schema::create('bonus_code_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bonus_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            $table->unique(['bonus_code_id', 'tag_id']);
        });

        Schema::table('deposits', function (Blueprint $table) {
            $table->foreignId('bonus_code_id')->nullable()->after('utr_number')
                ->constrained('bonus_codes')->nullOnDelete();
        });

        Schema::create('bonus_code_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bonus_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();

            // Snapshot — the code's reward may be edited after redemption.
            $table->string('code', 40);
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('reward_label', 40)->nullable();

            $table->string('status', 20)->default('pending'); // pending | fulfilled | rejected
            $table->string('period_key', 32);

            // Set when the code was applied to a deposit rather than redeemed
            // standalone. The reward unlocks when that deposit is approved.
            $table->foreignId('deposit_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('redeemed_at')->nullable();
            $table->foreignId('fulfilled_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('fulfilled_at')->nullable();
            $table->text('notes')->nullable();

            // Fraud trail: several accounts redeeming from one device or IP.
            $table->string('ip_address', 45)->nullable();
            $table->string('device_hash', 64)->nullable()->index();

            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();

            // One redemption per user per code per period — the frequency lock.
            $table->unique(['bonus_code_id', 'user_id', 'period_key']);
            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'status', 'fulfilled_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bonus_code_redemptions');

        Schema::table('deposits', function (Blueprint $table) {
            $table->dropForeign(['bonus_code_id']);
            $table->dropColumn('bonus_code_id');
        });

        Schema::dropIfExists('bonus_code_tag');
        Schema::dropIfExists('bonus_codes');
    }
};
