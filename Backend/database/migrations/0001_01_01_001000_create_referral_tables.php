<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referral and agent commission.
 *
 * `commission_entries` is the ledger and the only source of truth.
 * `commission_accounts` is a CACHE of it — balances and team stats that can be
 * rebuilt at any time with `php artisan referral:rebuild`. Never treat the
 * cached balance as authoritative.
 *
 * Two rules are worth knowing before touching this:
 *
 *   - Commission is never reversed automatically. It sits `pending` for a
 *     holding window; if the underlying deposit stops being approved, the entry
 *     is FLAGGED for an admin instead of being clawed back.
 *   - `unique(deposit_id, agent_id, level)` is the idempotency guarantee. A
 *     double-clicked or retried approval cannot accrue twice. MySQL permits
 *     repeated NULLs there, so manual adjustments (which have no deposit) are
 *     unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->unique();
            $table->boolean('enabled')->default(false);

            // ---- Commission rate ---------------------------------------------
            $table->decimal('commission_percent', 6, 3)->default(1.000);
            // Tier ladder. NULL/empty = use the flat rate above. Shape:
            // [{"label":"Silver","min_volume":50000,"percent":2.0}, ...]
            // Highest tier whose min_volume <= lifetime team volume wins.
            $table->boolean('tiers_enabled')->default(false);
            $table->json('tiers')->nullable();
            // Second level. OFF by default — enabling it turns a referral
            // programme into a two-tier one and roughly doubles the liability.
            $table->boolean('level2_enabled')->default(false);
            $table->decimal('level2_percent', 6, 3)->default(0.000);

            // ---- Earning rules -----------------------------------------------
            // Deposits under this never earn (stops ₹10-deposit farming).
            $table->decimal('min_deposit_amount', 12, 2)->default(0);
            $table->boolean('first_deposit_only')->default(false);
            // How long commission stays `pending` — the window in which a wrong
            // approval can still be undone without money having moved.
            $table->unsignedSmallInteger('holding_hours')->default(24);
            // 0 = unlimited.
            $table->decimal('monthly_cap_per_agent', 12, 2)->default(0);
            $table->decimal('per_deposit_cap', 12, 2)->default(0);

            // ---- Fraud controls ------------------------------------------------
            $table->unsignedSmallInteger('max_referrals_per_day')->default(0);
            $table->boolean('block_same_phone')->default(true);
            // Refuse a referral when the two accounts have ever shared a
            // withdrawal destination (same UPI id / bank account).
            $table->boolean('block_shared_payout')->default(true);
            // Ignore a deposit the same user withdrew back within N hours
            // (0 = off). Deposit-withdraw-repeat is the classic farm.
            $table->unsignedSmallInteger('washout_hours')->default(0);

            // ---- Becoming an agent ---------------------------------------------
            $table->boolean('auto_promote_to_agent')->default(true);
            $table->boolean('allow_self_signup_code')->default(true);
            // A referrer attached AFTER the user already deposited: do those past
            // deposits earn? This is a real money decision — default no.
            $table->boolean('retroactive_on_attach')->default(false);

            // ---- Payouts ---------------------------------------------------------
            $table->decimal('min_payout_amount', 12, 2)->default(100);
            $table->boolean('payout_to_bank_enabled')->default(true);
            // "Use it in the game" — converts commission into deposit credit
            // instead of paying cash out.
            $table->boolean('payout_to_play_enabled')->default(true);

            // ---- Notifications -----------------------------------------------------
            $table->boolean('notify_on_commission')->default(true);
            $table->boolean('notify_on_join')->default(true);

            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
        });

        Schema::create('commission_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('branch_id');

            // Earned, holding period elapsed, not yet paid out. Withdrawable.
            $table->decimal('available_balance', 14, 2)->default(0);
            // Earned but still inside the holding window.
            $table->decimal('pending_balance', 14, 2)->default(0);

            $table->decimal('lifetime_earned', 14, 2)->default(0);
            $table->decimal('lifetime_paid', 14, 2)->default(0);
            // Sum of manual admin corrections (signed — can be negative).
            $table->decimal('lifetime_adjusted', 14, 2)->default(0);

            // Team snapshot, refreshed on join and on accrual.
            $table->unsignedInteger('team_count')->default(0);
            $table->unsignedInteger('team_active_count')->default(0);
            $table->decimal('team_deposit_total', 16, 2)->default(0);

            // Resolved tier at the last accrual, for display.
            $table->string('tier_label', 40)->nullable();
            $table->decimal('tier_percent', 6, 3)->nullable();

            // Mirrors users.agent_status for cheap listing.
            $table->string('status', 16)->default('active');

            $table->timestamp('last_accrual_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();

            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'available_balance']);
        });

        Schema::create('commission_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('branch_id');

            $table->string('type', 16);                       // accrual | adjustment | payout | bonus
            $table->string('status', 16)->default('pending'); // pending | available | paid | void

            // Who generated it, and off what. NULL for manual adjustments.
            $table->unsignedBigInteger('from_user_id')->nullable();
            $table->unsignedBigInteger('deposit_id')->nullable();
            $table->unsignedBigInteger('payout_id')->nullable();
            $table->unsignedTinyInteger('level')->default(1); // 1 = direct, 2 = sub-team

            // How the number was reached, frozen at accrual time so a later rate
            // change never rewrites history.
            $table->decimal('base_amount', 14, 2)->default(0);
            $table->decimal('percent', 6, 3)->default(0);
            $table->string('tier_label', 40)->nullable();
            $table->decimal('amount', 14, 2)->default(0); // signed

            // When `pending` becomes `available` (accrual + holding_hours).
            $table->timestamp('available_at')->nullable();
            $table->timestamp('released_at')->nullable();

            // Deposit status when we accrued vs what it is now. When these
            // disagree the row is flagged for an admin — money never moves on
            // its own.
            $table->string('deposit_status_at_accrual', 16)->nullable();
            $table->string('deposit_status_now', 16)->nullable();
            $table->timestamp('flagged_at')->nullable();
            $table->string('flag_reason', 120)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();

            // Frozen identity of the depositing user, so the ledger still reads
            // correctly if that user is renamed or removed.
            $table->string('from_display_name', 120)->nullable();
            $table->string('from_display_play_id', 60)->nullable();

            $table->text('note')->nullable();
            // Admin id for adjustments / payouts, NULL for system accruals.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->foreign('agent_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('from_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('deposit_id')->references('id')->on('deposits')->nullOnDelete();

            // Idempotency: one accrual per (deposit, agent, level).
            $table->unique(['deposit_id', 'agent_id', 'level'], 'commission_entries_deposit_unique');

            $table->index(['agent_id', 'status']);
            $table->index(['agent_id', 'created_at']);
            $table->index(['branch_id', 'type', 'created_at']);
            // The release job: everything pending whose window has elapsed.
            $table->index(['status', 'available_at']);
            $table->index(['branch_id', 'flagged_at']);
            $table->index('from_user_id');
        });

        Schema::create('commission_payouts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('branch_id');

            $table->decimal('amount', 14, 2);
            // upi | bank | play — `play` credits the game balance instead of cash.
            $table->string('method', 16)->default('upi');

            $table->string('upi_id')->nullable();
            $table->string('account_number', 40)->nullable();
            $table->string('ifsc_code', 20)->nullable();
            $table->string('account_name', 120)->nullable();

            $table->string('status', 16)->default('pending'); // pending | approved | rejected
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('processed_by')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->foreign('agent_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();

            $table->index(['branch_id', 'status']);
            $table->index(['agent_id', 'status']);
        });

        Schema::create('referral_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            // The user whose referral state changed.
            $table->unsignedBigInteger('user_id');

            // attach | detach | reattach | promote | demote | suspend | resume |
            // adjust | settings
            $table->string('action', 24);

            $table->unsignedBigInteger('old_referrer_id')->nullable();
            $table->unsignedBigInteger('new_referrer_id')->nullable();

            $table->string('actor_type', 16)->default('admin'); // admin | user | system
            $table->unsignedBigInteger('actor_id')->nullable();

            $table->text('reason')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->index(['branch_id', 'action', 'created_at']);
            $table->index('user_id');
        });

        // Attached last: users.referred_by points at another user, so the table
        // must be fully created first.
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('referred_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['referred_by']);
        });

        Schema::dropIfExists('referral_audits');
        Schema::dropIfExists('commission_payouts');
        Schema::dropIfExists('commission_entries');
        Schema::dropIfExists('commission_accounts');
        Schema::dropIfExists('referral_settings');
    }
};
