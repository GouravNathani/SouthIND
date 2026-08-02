<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BookFlowControl wallet billing.
 *
 * This panel is billed per message it sends. Messages never call the wallet API
 * inline — they only write a `wallet_charges` row. A nightly job then bills one
 * whole day as a SINGLE deduction (`wallet_daily_payouts`), which is why both
 * tables carry a unique `reference`: Control dedupes on it, so a retried day is
 * a replay rather than a second charge.
 *
 * `wallet_states` is the durable fallback for the balance and the per-message
 * rates, so pricing a message never has to make an HTTP call — and a wallet
 * outage can never block a chat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_states', function (Blueprint $table) {
            $table->id();
            $table->string('wallet_id')->unique(); // external wallet id
            $table->string('name')->nullable();
            $table->string('number')->nullable();
            $table->string('status')->nullable();
            // Coins, fractional down to paise. 1 coin = ₹1.
            $table->decimal('balance', 16, 2)->nullable();
            $table->decimal('monthly_payout_percent', 8, 4)->nullable();
            $table->json('pending_payout')->nullable();
            $table->json('costs')->nullable();
            $table->date('expires_at')->nullable();
            $table->boolean('is_expired')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('wallet_charges', function (Blueprint $table) {
            $table->id();
            $table->string('channel');            // support | whatsapp | media
            $table->string('direction');          // in | out
            $table->string('cost_field');         // support_in_cost, whatsapp_out_cost, ...
            $table->decimal('cost_value', 10, 2); // money cost applied
            // Where the rate came from: live | stale | self.
            $table->string('rate_source')->default('live');
            $table->decimal('coins', 16, 2);
            // Idempotency key, prefixed per panel (see config/wallet.php
            // reference_prefix) because every BookFlow panel bills one shared
            // account and Control dedupes on this value.
            $table->string('reference')->unique();
            $table->string('status')->default('unsettled');
            $table->string('external_transaction_id')->nullable();
            $table->decimal('balance_after', 16, 2)->nullable();
            $table->string('error_code')->nullable();
            // Set when a deleted message's charge is voided.
            $table->timestamp('voided_at')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['status', 'created_at']);
            $table->index(['channel', 'direction', 'created_at']);
            // Finding a specific message's charge when it is edited or deleted.
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('wallet_daily_payouts', function (Blueprint $table) {
            $table->id();
            $table->date('payout_date')->unique();
            $table->decimal('coins', 16, 2)->default(0);
            $table->unsignedInteger('charge_count')->default(0);
            // channel_direction => {count, coins}
            $table->json('breakdown')->nullable();
            // Idempotency key for the whole day, e.g. sind-day-2026-08-02.
            $table->string('reference')->unique();
            $table->string('status')->default('pending');
            $table->string('external_transaction_id')->nullable();
            $table->decimal('balance_after', 16, 2)->nullable();
            $table->string('error_code')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            // Set when Control's operator cleared the owed amount by hand.
            $table->string('cleared_reference')->nullable();
            $table->timestamps();

            $table->index(['status', 'payout_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_daily_payouts');
        Schema::dropIfExists('wallet_charges');
        Schema::dropIfExists('wallet_states');
    }
};
