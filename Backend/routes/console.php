<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('wallet:daily-payout {--date= : Bill only this day (YYYY-MM-DD)} {--days=60 : Max days to bill in this run (a longer backlog continues next run)}', function (): int {
    // The ONE place that deducts from the BookFlow wallet.
    //
    // Messages never call the wallet API — they only write wallet_charges rows.
    // Here a whole day of those rows is billed as a single deduction, oldest
    // unpaid day first. A day is all-or-nothing: if Control is down or the
    // balance can't cover it, the day is marked `owed`, retried on every later
    // run (the per-day reference makes that a replay, never a double charge)
    // and reported to Control so an operator can clear it by hand.
    $wallet = app(\App\Support\Wallet\WalletService::class);

    $result = $wallet->runDailyPayout(
        $this->option('date') ?: null,
        max(1, (int) $this->option('days')),
    );

    $this->info("Daily payout: {$result['days']} day(s), settled {$result['settled']}, owed {$result['owed']}, total {$result['coins']} coins.");
    Log::info('Wallet daily payout run.', $result);

    // Always report, even on a clean run — Control needs to see owed drop to 0.
    $wallet->reportOwed();

    return \Illuminate\Console\Command::SUCCESS;
})->purpose('Bill each completed day of message charges to the wallet as a single deduction');

// withoutOverlapping(N): N minutes is how long a run's lock outlives a run the
// host killed (CPU/time limits on shared hosting). The default is 24 h, which
// silently skipped that command for a whole day. N comfortably exceeds each
// command's longest normal run, so a slow run is still not doubled.

// 03:00 — bill yesterday, and retry anything still owed from earlier days.
Schedule::command('wallet:daily-payout')
    ->dailyAt('03:00')
    ->withoutOverlapping(120);

Artisan::command('wallet:monthly-payout {--month= : Bill only this finished month (YYYY-MM)}', function (): int {
    // Monthly Payout — the developer's percent of each month's approved deposits —
    // deducted from the BookFlow wallet as one deduction per finished month,
    // oldest first (from config payout.start_month). A failed month is owed and
    // retried every night with the same amount; the per-month reference makes a
    // retry a replay at Control, never a second deduction.
    $wallet = app(\App\Support\Wallet\WalletService::class);

    $result = $wallet->runMonthlyPayout($this->option('month') ?: null);

    $this->info("Monthly payout: {$result['months']} month(s), settled {$result['settled']}, owed {$result['owed']}, total {$result['coins']} coins.");
    Log::info('Wallet monthly payout run.', $result);

    $wallet->reportOwed();

    return \Illuminate\Console\Command::SUCCESS;
})->purpose('Deduct each finished month\'s Monthly Payout from the wallet as a single deduction');

// 03:30 every night (after the daily payout): bills last month once it is over
// and retries any owed month. Nightly rather than once a month, so a missed
// cron run on the 1st never skips a month.
Schedule::command('wallet:monthly-payout')
    ->dailyAt('03:30')
    ->withoutOverlapping(120);

Artisan::command('wallet:settle {--limit=60 : Max days to retry}', function (): int {
    // Manual "settle now" (also used by the SuperAdmin button): same run as the
    // nightly payout, on demand.
    $limit = max(1, (int) $this->option('limit'));
    $result = app(\App\Support\Wallet\WalletService::class)->retryUnsettled($limit);
    $this->info("Wallet settle: attempted {$result['attempted']} day(s), settled {$result['settled']}.");

    return \Illuminate\Console\Command::SUCCESS;
})->purpose('Retry owed daily payouts against the BookFlow wallet API');

Artisan::command('wallet:sync-rates', function (): int {
    // Keep the cached balance/rates warm so pricing a message never has to make
    // an HTTP call. Message sends read the cache/WalletState only.
    $snapshot = app(\App\Support\Wallet\WalletService::class)->snapshot(fresh: true);
    $this->info('Wallet rates synced (source: ' . ($snapshot['rate_source'] ?? 'unknown') . ').');

    return \Illuminate\Console\Command::SUCCESS;
})->purpose('Refresh the cached wallet balance/rates from BookFlowControl');

Schedule::command('wallet:sync-rates')
    ->hourly()
    ->withoutOverlapping(60);

Artisan::command('winner:streak-tick', function (): int {
    // Auto-reset for the Winner Streak boards.
    //
    // Every enabled (branch, period) board is checked against its own schedule.
    // A window whose end has passed is CLOSED — its top N are frozen into
    // winner_streak_entries and the winners are pushed to the branch — and the
    // next window opens immediately. Boundaries are derived from the schedule,
    // never from "last run + 24h", so a paused cron just catches up on the next
    // tick instead of drifting.
    $result = app(\App\Support\WinnerStreak\WinnerStreakCycleManager::class)->tick();

    $this->info("Winner streak: checked {$result['checked']} board(s), closed {$result['closed']}, opened {$result['opened']}, announced {$result['announced']}.");

    if ($result['closed'] > 0) {
        Log::info('Winner streak cycles closed.', $result);
    }

    return \Illuminate\Console\Command::SUCCESS;
})->purpose('Close due Winner Streak cycles, freeze their winners and open the next window');

// Every five minutes so a board configured to reset at, say, 21:30 turns over
// within minutes of the boundary rather than at the next hour.
Schedule::command('winner:streak-tick')
    ->everyFiveMinutes()
    ->withoutOverlapping(15);

Artisan::command('referral:tick {--limit=500 : Max entries to release per run}', function (): int {
    // Releases agent commission whose holding window has elapsed.
    //
    // The holding window is the safety net for the "no automatic reversal"
    // policy: while commission is pending, a deposit that turns out to be wrong
    // can be dealt with before the agent could ever have withdrawn it. Anything
    // whose deposit is no longer approved (or that trips the washout rule) is
    // left pending and FLAGGED for an admin instead of being released.
    $result = \App\Support\Referral\CommissionAccrual::releaseDue(
        max(1, (int) $this->option('limit')),
    );

    $this->info("Referral: released {$result['released']} entr(ies), flagged {$result['flagged']} for review.");

    if ($result['flagged'] > 0) {
        Log::info('Referral commission flagged for review.', $result);
    }

    return \Illuminate\Console\Command::SUCCESS;
})->purpose('Release commission whose holding period has elapsed');

// Every ten minutes: a holding period is configured in hours, so this is well
// inside the resolution anyone can perceive.
Schedule::command('referral:tick')
    ->everyTenMinutes()
    ->withoutOverlapping(30);

Artisan::command('referral:rebuild {--branch= : Only this branch}', function (): int {
    // Rebuild every agent's cached balances and team stats from the ledger.
    //
    // commission_accounts is only ever a cache of commission_entries, so this is
    // always safe to run — use it after a manual SQL fix, or to prove the cached
    // balances still agree with their history.
    $query = \App\Models\CommissionAccount::query();

    if ($branch = $this->option('branch')) {
        $query->where('branch_id', (int) $branch);
    }

    $count = 0;

    $query->orderBy('id')->chunk(200, function ($accounts) use (&$count) {
        foreach ($accounts as $account) {
            \App\Support\Referral\CommissionLedger::recompute((int) $account->user_id);
            \App\Support\Referral\CommissionLedger::refreshTeamStats((int) $account->user_id);
            $count++;
        }
    });

    $this->info("Referral: rebuilt {$count} commission account(s) from the ledger.");

    return \Illuminate\Console\Command::SUCCESS;
})->purpose('Recompute commission account balances and team stats from the ledger');

// ── Housekeeping (needs the one-minute `schedule:run` cron) ─────────────────

Artisan::command('logs:prune {--days= : Keep log files for this many days}', function (): int {
    // Logs are kept for 7 days at most: the daily laravel-*.log files, the
    // per-day api-*.log activity logs, and any legacy single file (laravel.log,
    // api.log) that stopped being written to. Judged by the last write time, so
    // a file that is still in use is never removed.
    $days = max(1, (int) ($this->option('days') ?: \App\Http\Middleware\LogApiActivity::RETENTION_DAYS));
    $cutoff = now()->subDays($days)->getTimestamp();
    $removed = 0;
    $freed = 0;

    $dirs = [storage_path('logs'), \Illuminate\Support\Facades\Storage::disk('local')->path('logs')];

    foreach ($dirs as $dir) {
        foreach (glob($dir . '/*.log') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime === false || $mtime >= $cutoff) {
                continue;
            }

            $size = (int) @filesize($file);
            if (@unlink($file)) {
                $removed++;
                $freed += $size;
            }
        }
    }

    $this->info("Log prune: removed {$removed} file(s), freed " . round($freed / 1048576, 2) . ' MB.');

    return \Illuminate\Console\Command::SUCCESS;
})->purpose('Delete log files not written to within the retention window (7 days)');

Schedule::command('logs:prune')
    ->dailyAt('02:15')
    ->withoutOverlapping(120);

Artisan::command('deposits:cleanup-receipts {--days=7 : Keep receipts for this many days}', function (): int {
    // Deposit receipts are kept for a fixed window only. Older ones are deleted
    // from disk and their path cleared, so an old deposit simply shows no
    // receipt. A deposit that is still open (pending / on process) keeps its
    // receipt whatever its age — an admin still needs it to decide.
    //
    // Paths are stored as "/storage/deposit-receipts/<file>" (Storage::url);
    // nothing outside deposit-receipts/ is ever touched.
    $days = max((int) $this->option('days'), 1);
    $cutoff = now()->subDays($days);
    $disk = \Illuminate\Support\Facades\Storage::disk('public');
    $open = [\App\Models\Deposit::STATUS_PENDING, \App\Models\Deposit::STATUS_ON_PROCESS];

    $relative = function (?string $stored): ?string {
        $path = ltrim((string) (parse_url(trim((string) $stored), PHP_URL_PATH) ?: ''), '/');
        foreach (['storage/app/public/', 'storage/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix));
                break;
            }
        }

        return str_starts_with($path, 'deposit-receipts/') && !str_contains($path, '..') ? $path : null;
    };

    $clearedRows = 0;
    $removedFiles = 0;

    \App\Models\Deposit::query()
        ->whereNotNull('receipt_image_path')
        ->where('receipt_image_path', '!=', '')
        ->where('created_at', '<', $cutoff)
        ->whereNotIn('status', $open)
        ->select(['id', 'receipt_image_path'])
        ->chunkById(500, function ($deposits) use ($disk, $relative, &$clearedRows, &$removedFiles): void {
            foreach ($deposits as $deposit) {
                $path = $relative($deposit->receipt_image_path);
                if ($path !== null && $disk->exists($path) && $disk->delete($path)) {
                    $removedFiles++;
                }
            }

            // toBase(): clearing an expired receipt is housekeeping, not an edit
            // of the deposit, so updated_at is left alone.
            $clearedRows += \App\Models\Deposit::query()
                ->whereIn('id', $deposits->pluck('id')->all())
                ->toBase()
                ->update(['receipt_image_path' => null]);
        });

    // Files no deposit points at any more (deleted deposits, abandoned uploads)
    // would otherwise stay forever. Anything an open deposit still uses is kept.
    $keep = \App\Models\Deposit::query()
        ->whereIn('status', $open)
        ->whereNotNull('receipt_image_path')
        ->pluck('receipt_image_path')
        ->map($relative)
        ->filter()
        ->flip();

    $orphans = 0;
    $dir = $disk->path('deposit-receipts');
    if (is_dir($dir)) {
        $threshold = $cutoff->getTimestamp();
        foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || $file->getMTime() >= $threshold || isset($keep['deposit-receipts/' . $file->getFilename()])) {
                continue;
            }

            if (@unlink($file->getPathname())) {
                $orphans++;
            }
        }
    }

    $this->info("Receipt cleanup: cleared {$clearedRows} deposit(s), removed {$removedFiles} receipt file(s) and {$orphans} orphaned file(s).");
    Log::info('Deposit receipt cleanup complete.', [
        'days' => $days,
        'deposits_cleared' => $clearedRows,
        'files_removed' => $removedFiles,
        'orphans_removed' => $orphans,
    ]);

    return \Illuminate\Console\Command::SUCCESS;
})->purpose('Delete deposit receipts older than the retention window (7 days) and clear their paths');

Schedule::command('deposits:cleanup-receipts --days=7')
    ->dailyAt('01:00')
    ->withoutOverlapping(120);

Artisan::command('cache:prune-files', function (): int {
    // Laravel's file cache driver never garbage-collects: an expired entry is
    // only unlinked when something reads that exact key again. The admin list
    // caches are tens of MB each, so the directory grows without bound.
    //
    // File layout: the first 10 bytes are the expiry unix timestamp, then the
    // serialized payload. "9999999999" is Cache::forever and must be kept.
    $dir = storage_path('framework/cache/data');

    if (!is_dir($dir)) {
        $this->info('No file cache directory.');

        return \Illuminate\Console\Command::SUCCESS;
    }

    $now = time();
    $scanned = 0;
    $removed = 0;
    $freed = 0;

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        /** @var \SplFileInfo $entry */
        if ($entry->isDir()) {
            @rmdir($entry->getPathname()); // drops the hash dirs once emptied

            continue;
        }

        $scanned++;
        $path = $entry->getPathname();

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            continue;
        }

        $expiry = fread($handle, 10);
        fclose($handle);

        // Not a payload we understand, kept forever, or still live.
        if ($expiry === false || !ctype_digit($expiry) || $expiry === '9999999999' || (int) $expiry > $now) {
            continue;
        }

        $size = (int) $entry->getSize();
        if (@unlink($path)) {
            $removed++;
            $freed += $size;
        }
    }

    $this->info("Cache prune: scanned {$scanned} file(s), removed {$removed}, freed " . round($freed / 1048576, 2) . ' MB.');

    return \Illuminate\Console\Command::SUCCESS;
})->purpose('Delete expired Laravel file-cache entries (the file driver never GCs)');

Schedule::command('cache:prune-files')
    ->dailyAt('02:30')
    ->withoutOverlapping(120);

Artisan::command('tokens:cleanup', function (): int {
    $deleted = \Laravel\Sanctum\PersonalAccessToken::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<=', now())
        ->delete();

    $this->info("Token cleanup: removed {$deleted} expired access token(s).");

    return \Illuminate\Console\Command::SUCCESS;
})->purpose('Delete expired access tokens');

Schedule::command('tokens:cleanup')
    ->hourly()
    ->withoutOverlapping(60);

Artisan::command('tokens:purge-all', function (): int {
    // Hard reset: remove ALL access tokens (valid ones included) and every
    // pending password-reset link, forcing users, admins and super admins to log
    // in again. Anyone online gets a 401 on their next request and is sent back
    // to the login screen. Unlike tokens:cleanup, which only drops expired ones.
    $purgedAccessTokens = \Laravel\Sanctum\PersonalAccessToken::query()->delete();
    $purgedResetTokens = \Illuminate\Support\Facades\DB::table('password_reset_tokens')->delete();

    $this->info("All tokens purged. Access: {$purgedAccessTokens}, password reset: {$purgedResetTokens}.");
    Log::info('Nightly token purge complete (everyone logged out).', [
        'access_tokens_purged' => $purgedAccessTokens,
        'password_reset_tokens_purged' => $purgedResetTokens,
    ]);

    return \Illuminate\Console\Command::SUCCESS;
})->purpose('Force-logout everyone by deleting ALL access and password-reset tokens');

// Every night at 00:00 (app timezone) — hard reset, everyone is logged out.
Schedule::command('tokens:purge-all')
    ->dailyAt('00:00')
    ->withoutOverlapping(120);

// Shared hosting has no always-on worker, so the one-minute scheduler cron
// drains the queue instead: --stop-when-empty keeps each run short, --max-time
// guarantees it exits before the next tick, and withoutOverlapping stops runs
// from stacking up.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(5);
