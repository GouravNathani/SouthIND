<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('wallet:daily-payout {--date= : Bill only this day (YYYY-MM-DD)} {--days=60 : How far back to catch up}', function (): int {
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

// 03:00 — bill yesterday, and retry anything still owed from earlier days.
Schedule::command('wallet:daily-payout')
    ->dailyAt('03:00')
    ->withoutOverlapping();

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
    ->withoutOverlapping();

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
    ->withoutOverlapping();

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
    ->withoutOverlapping();

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
