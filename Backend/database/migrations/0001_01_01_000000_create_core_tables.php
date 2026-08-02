<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core identity: branches, admins, users, plus Laravel's own framework tables.
 *
 * This schema is CONSOLIDATED. The panel this was rewritten from reached the
 * same shape through 41 incremental migrations, 8 of which were pure `add
 * column` patches applied later because the table already existed in
 * production. SouthIND starts on an empty database, so each table is declared
 * once, in full, with its indexes attached — there is nothing to preserve and
 * nothing to guard with hasColumn().
 *
 * branches <-> admins is circular (branches.created_by -> admins, admins
 * .branch_id -> branches), so admins is created with a plain indexed column and
 * the foreign key is attached after branches exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('unique_number', 32)->unique()->nullable();
            $table->string('name');
            $table->string('phone', 32)->unique();
            $table->string('email')->nullable()->unique();
            $table->string('domain')->nullable()->index();
            $table->string('password');
            // Forces a super admin to set their own password on next login.
            $table->boolean('must_change_password')->default(false);
            $table->string('role', 20)->default('admin');
            $table->boolean('is_active')->default(true);
            $table->boolean('allow_profit_view')->default(false);
            // Staff belong to the admin who created them.
            $table->foreignId('parent_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index(['branch_id', 'role', 'is_active']);
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('code', 40)->unique();
            $table->string('domain', 191)->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('min_deposit_amount')->nullable();
            $table->unsignedInteger('min_withdrawal_amount')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('admins', function (Blueprint $table) {
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->string('unique_number', 32)->unique()->nullable();
            $table->string('play_id', 64)->nullable();
            $table->string('name');
            $table->string('phone', 32);
            $table->string('email')->unique()->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('mpin')->nullable();
            // Brute-force protection for MPIN: lock the account after too many
            // wrong attempts.
            $table->unsignedSmallInteger('mpin_failed_attempts')->default(0);
            $table->timestamp('mpin_locked_at')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamp('last_seen_at')->nullable();

            // ---- Referral identity ------------------------------------------
            // user | agent. Agents earn commission; users do not.
            $table->string('user_type', 16)->default('user');
            // active | suspended. Suspending stops accrual and payouts without
            // touching the account or the existing ledger.
            $table->string('agent_status', 16)->default('active');
            // The agent's own shareable code. NULL until promoted.
            $table->string('referral_code', 16)->nullable()->unique();
            // The agent this user belongs to. Always the same branch.
            $table->unsignedBigInteger('referred_by')->nullable()->index();
            $table->timestamp('referred_at')->nullable();
            // signup | admin | link — how the link was made, for disputes.
            $table->string('referral_source', 16)->nullable();
            // Deposits before this instant never earn for the referrer. Set when
            // an admin attaches a referrer late and the branch is not configured
            // to pay retroactively.
            $table->timestamp('commission_from')->nullable();
            // Per-agent rate that beats both the flat rate and the tier ladder.
            // NULL = use the branch configuration.
            $table->decimal('commission_percent_override', 6, 3)->nullable();

            $table->rememberToken();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();

            $table->unique(['branch_id', 'phone']);
            // Branch user lists, and the "active now" counters on the dashboard.
            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'user_type']);
            $table->index('last_seen_at');
            $table->index('play_id');
        });

        // ---- Framework tables ----------------------------------------------
        // cache / jobs / sessions exist as a FALLBACK only. The shipped config
        // puts all three on redis, because pointing them at the database is what
        // exhausted MySQL's connection pool on a sibling panel and turned every
        // authenticated request into a 500. Keep the tables, never the drivers.

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');

        Schema::table('admins', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
        });

        Schema::dropIfExists('branches');
        Schema::dropIfExists('admins');
    }
};
