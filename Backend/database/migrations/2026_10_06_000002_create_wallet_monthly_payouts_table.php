<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per finished calendar month: that month's Monthly Payout (the
 * developer's percent of the month's approved deposits) billed to the external
 * wallet as a SINGLE deduction.
 *
 *   status: pending  — worked out, not paid yet (or being retried)
 *           settled  — deducted from the wallet
 *           owed     — the deduction failed; retried every night
 *           cleared  — an operator collected it by hand from Control
 *           skipped  — nothing to bill (no approved deposits that month)
 *
 * `percent`, `approved_total` and `coins` are frozen when the month is first
 * billed, so a later rate change never alters an unpaid month. `reference` is
 * the idempotency key Control dedupes on. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wallet_monthly_payouts')) {
            return;
        }

        Schema::create('wallet_monthly_payouts', function (Blueprint $table) {
            $table->id();
            $table->string('month', 7)->unique(); // YYYY-MM
            $table->decimal('approved_total', 16, 2)->default(0);
            $table->unsignedInteger('deposit_count')->default(0);
            $table->decimal('percent', 12, 6)->default(0);
            $table->decimal('coins', 16, 2)->default(0);
            $table->string('reference')->unique(); // e.g. sind-month-2026-09
            $table->string('status')->default('pending');
            $table->string('external_transaction_id')->nullable();
            $table->decimal('balance_after', 16, 2)->nullable();
            $table->string('error_code')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->string('cleared_reference')->nullable();
            $table->timestamps();

            $table->index(['status', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_monthly_payouts');
    }
};
