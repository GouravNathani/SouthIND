<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Winner Streak — daily / weekly / monthly profit-and-loss leaderboards.
 *
 * A cycle is a time window. When it closes, the top N are FROZEN into
 * `winner_streak_entries` — display name, play id, amounts and all — so editing
 * the settings later can never rewrite history. `winner_streak_cycles` also
 * snapshots the participation rules it actually ran under, for the same reason.
 *
 * The privacy defaults are deliberately conservative: the loss board exists but
 * is admin-only until someone explicitly publishes it, phones are hidden, and
 * public amounts render as buckets ("50K+") rather than exact rupees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('winner_streak_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('period', 10);                  // daily | weekly | monthly
            $table->boolean('enabled')->default(false);

            // Group: only users carrying this tag compete. NULL = whole branch.
            $table->unsignedBigInteger('tag_id')->nullable();
            // Users carrying this tag never appear (test / staff accounts).
            $table->unsignedBigInteger('exclude_tag_id')->nullable();

            $table->unsignedSmallInteger('top_n')->default(3);
            // Publishing "biggest loser" names is reputationally costly, so it
            // takes a deliberate opt-in.
            $table->boolean('loss_board_enabled')->default(true);
            $table->boolean('loss_board_public')->default(false);

            // Anti-gaming floor: a user must actually have played to rank.
            $table->decimal('min_turnover', 12, 2)->default(0);
            $table->unsignedSmallInteger('min_transactions')->default(0);

            // Reset schedule, in the app timezone (Asia/Kolkata).
            $table->string('reset_time', 5)->default('00:00');             // HH:MM
            $table->unsignedTinyInteger('reset_weekday')->default(1);      // 1=Mon .. 7=Sun
            $table->unsignedTinyInteger('reset_day_of_month')->default(1); // 1..28
            $table->timestamp('last_reset_at')->nullable();
            $table->timestamp('next_reset_at')->nullable()->index();

            // What the USER panel may see. Admins always see everything.
            $table->boolean('show_name')->default(true);
            $table->boolean('show_play_id')->default(true);
            $table->boolean('show_phone')->default(false);
            $table->boolean('show_amount')->default(true);
            $table->boolean('show_profit_loss')->default(true);
            $table->boolean('show_reward')->default(true);
            $table->boolean('mask_amount_bucket')->default(true);
            $table->boolean('mask_name')->default(true);

            $table->decimal('reward_amount', 12, 2)->default(0);
            $table->string('reward_label', 40)->nullable();

            // Push the winners to every user in the branch when a cycle closes.
            $table->boolean('announce_push')->default(true);
            // Stops the same account winning every cycle.
            $table->unsignedSmallInteger('winner_cooldown_cycles')->default(0);
            // Never push an announcement in the middle of the night.
            $table->boolean('quiet_hours_enabled')->default(true);
            $table->string('quiet_from', 5)->default('22:00');
            $table->string('quiet_to', 5)->default('08:00');

            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('tag_id')->references('id')->on('tags')->nullOnDelete();
            $table->foreign('exclude_tag_id')->references('id')->on('tags')->nullOnDelete();

            $table->unique(['branch_id', 'period']);
        });

        Schema::create('winner_streak_cycles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('period', 10);
            $table->string('label', 40)->nullable();   // "2026-08-02", "2026-W31", "2026-08"

            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('status', 10)->default('open'); // open | closed

            // Snapshot of the rules this cycle actually ran under.
            $table->unsignedBigInteger('tag_id')->nullable();
            $table->unsignedSmallInteger('top_n')->default(3);

            $table->unsignedInteger('participants_count')->default(0);
            $table->boolean('announced')->default(false);
            // Admin id, or NULL when the scheduler closed it.
            $table->unsignedBigInteger('closed_by')->nullable();

            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();

            $table->index(['branch_id', 'period', 'status']);
            $table->index(['branch_id', 'period', 'closed_at']);
        });

        Schema::create('winner_streak_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cycle_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('period', 10);
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('kind', 10);        // profit | loss
            $table->unsignedSmallInteger('rank');

            $table->decimal('deposit_total', 14, 2)->default(0);
            $table->decimal('withdrawal_total', 14, 2)->default(0);
            $table->decimal('bonus_total', 14, 2)->default(0);
            // withdrawals + bonus - deposits. Signed: negative = loss.
            $table->decimal('net_amount', 14, 2)->default(0);
            $table->unsignedInteger('transactions_count')->default(0);

            // Frozen identity — survives a rename or a deleted account.
            $table->string('display_name', 120)->nullable();
            $table->string('display_play_id', 60)->nullable();
            $table->string('display_phone', 20)->nullable();

            $table->decimal('reward_amount', 12, 2)->default(0);
            $table->string('reward_label', 40)->nullable();
            // pending -> paid | skipped. There is no in-app balance, so rewards
            // are a manual admin queue exactly like bonus code redemptions.
            $table->string('reward_status', 12)->default('pending');
            $table->unsignedBigInteger('paid_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->foreign('cycle_id')->references('id')->on('winner_streak_cycles')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['cycle_id', 'kind', 'rank']);
            $table->index(['branch_id', 'period', 'kind']);
            $table->index(['branch_id', 'reward_status']);
            // Cooldown lookup: has this user won recently?
            $table->index(['user_id', 'created_at']);
            $table->unique(['cycle_id', 'kind', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('winner_streak_entries');
        Schema::dropIfExists('winner_streak_cycles');
        Schema::dropIfExists('winner_streak_settings');
    }
};
