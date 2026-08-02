<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The money core: the accounts users pay into, and the deposit / withdrawal
 * requests admins approve.
 *
 * These three tables carry every rupee in the system and are also the hottest
 * read path — the admin panels poll filtered, branch-scoped, status-scoped,
 * date-ordered lists continuously. The composite indexes here are shaped to
 * exactly those queries rather than to single columns.
 *
 * `deposits.bonus_code_id` is added by the bonus migration: deposits must exist
 * before bonus_code_redemptions can point at them, and bonus_codes must exist
 * before deposits can point back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 20);
            $table->string('upi_id')->nullable();
            $table->string('account_number')->nullable();
            $table->string('ifsc_code')->nullable();
            $table->string('holder_name')->nullable();
            $table->string('used_for', 20)->default('deposit');
            $table->string('logo_path')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('owner_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->text('notes')->nullable();

            // Cumulative approved-deposit cap. NULL = unlimited. When reached the
            // account auto-pauses and disappears from the user's picker.
            $table->decimal('deposit_limit', 12, 2)->nullable();
            // Per-deposit amount range for this account. NULL = no constraint.
            $table->decimal('min_deposit', 12, 2)->nullable();
            $table->decimal('max_deposit', 12, 2)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();

            // The user-facing picker: active deposit accounts for a branch.
            $table->index(['branch_id', 'used_for', 'status']);
            $table->index(['type', 'used_for', 'status']);
            $table->index('owner_admin_id');
        });

        Schema::create('deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('play_id', 64);
            $table->decimal('amount', 12, 2);
            $table->string('utr_number', 64)->nullable();
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->string('receipt_image_path', 2048)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();

            // The admin queue: one branch, one status, newest first. This is the
            // single most-executed query in the product.
            $table->index(['branch_id', 'status', 'created_at']);
            // Winner-streak and commission windows read by approval time.
            $table->index(['branch_id', 'status', 'approved_at']);
            $table->index(['status', 'account_id']);
            $table->index(['play_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('play_id', 64);
            $table->decimal('amount', 12, 2);
            $table->string('destination_type', 20)->default('upi');
            $table->string('upi_id')->nullable();
            $table->string('account_number')->nullable();
            $table->string('ifsc_code')->nullable();
            $table->string('account_name')->nullable();
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();

            $table->index(['branch_id', 'status', 'created_at']);
            $table->index(['branch_id', 'status', 'processed_at']);
            $table->index(['status', 'destination_type']);
            $table->index(['play_id', 'status']);
            $table->index(['user_id', 'created_at']);
            // Shared-payout fraud check: has this UPI id / bank account been
            // used by a different user before?
            $table->index('upi_id');
            $table->index('account_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
        Schema::dropIfExists('deposits');
        Schema::dropIfExists('accounts');
    }
};
