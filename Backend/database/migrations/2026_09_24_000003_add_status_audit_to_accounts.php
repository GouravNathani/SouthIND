<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Record WHY an account's status last changed and WHO changed it, so the panels
 * can show "Paused — limit reached" or "Disabled by <name>" instead of a bare
 * status. The latest change lives on the account row (cheap to list); every
 * change is also appended to account_status_events for the history.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('accounts', 'status_reason')) {
            Schema::table('accounts', function (Blueprint $table): void {
                // limit_reached | limit_lifted | manual
                $table->string('status_reason', 32)->nullable()->after('status');
                $table->foreignId('status_changed_by_id')->nullable()->after('status_reason')
                    ->constrained('admins')->nullOnDelete();
                // system | admin | staff | super_admin
                $table->string('status_changed_by_role', 20)->nullable()->after('status_changed_by_id');
                $table->timestamp('status_changed_at')->nullable()->after('status_changed_by_role');
                // e.g. {approved_total, limit} so the badge needs no extra query.
                $table->json('status_meta')->nullable()->after('status_changed_at');
            });

            // Only AccountLimitEnforcer ever sets `paused` — a manual change can
            // only be active/inactive — so every account paused today was paused
            // by its deposit limit.
            DB::table('accounts')
                ->where('status', 'paused')
                ->whereNull('status_reason')
                ->update([
                    'status_reason' => 'limit_reached',
                    'status_changed_by_role' => 'system',
                ]);
        }

        if (!Schema::hasTable('account_status_events')) {
            Schema::create('account_status_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
                $table->string('from_status', 20)->nullable();
                $table->string('to_status', 20);
                $table->string('reason', 32);
                $table->foreignId('actor_id')->nullable()->constrained('admins')->nullOnDelete();
                $table->string('actor_role', 20);
                $table->json('meta')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['account_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('account_status_events');

        if (Schema::hasColumn('accounts', 'status_reason')) {
            Schema::table('accounts', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('status_changed_by_id');
                $table->dropColumn(['status_reason', 'status_changed_by_role', 'status_changed_at', 'status_meta']);
            });
        }
    }
};
