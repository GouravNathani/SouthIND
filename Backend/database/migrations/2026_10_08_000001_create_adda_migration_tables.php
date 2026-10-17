<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support for the one-time Adda → SouthIND move (adda:import + Adda SSO).
 *
 * legacy_adda_rows keeps every Adda row that has no live home here — Adda's
 * streaks, login audit, referral rewards and its own billing ledger — plus any
 * Adda column value SouthIND has no column for. Nothing from Adda is dropped;
 * it is parked as JSON and never read by live features.
 *
 * sso_tickets records each Adda hand-off ticket once it is used, so a ticket
 * cannot be replayed inside its short lifetime.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('legacy_adda_rows')) {
            Schema::create('legacy_adda_rows', function (Blueprint $table) {
                $table->id();
                // Adda table name; "<table>.dropped" for column values with no home here.
                $table->string('source_table', 64);
                $table->string('source_id', 64)->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->longText('payload');
                $table->timestamp('source_created_at')->nullable();
                $table->timestamp('imported_at')->nullable();

                $table->index(['source_table', 'source_id']);
                $table->index('user_id');
            });
        }

        if (!Schema::hasTable('sso_tickets')) {
            Schema::create('sso_tickets', function (Blueprint $table) {
                $table->id();
                $table->string('jti', 64)->unique();
                $table->unsignedBigInteger('user_id');
                $table->timestamp('expires_at')->index();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_tickets');
        Schema::dropIfExists('legacy_adda_rows');
    }
};
