<?php

namespace App\Support\WinnerStreak;

use App\Models\WinnerStreakCycle;
use App\Models\WinnerStreakEntry;
use App\Models\WinnerStreakSetting;
use App\Support\Push\UserPushNotifier;
use App\Support\WinnerStreak\WinnerStreakService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Opens, closes and re-opens Winner Streak cycles.
 *
 * Closing is the only moment the leaderboard is written down: the calculator
 * runs once, the top N of each board are frozen into winner_streak_entries,
 * and the cycle is marked closed. Everything the user panel shows afterwards
 * is a read of those frozen rows.
 */
class WinnerStreakCycleManager
{
    /** Safety valve — a very stale cron should not spin forever. */
    protected const MAX_CATCHUP_CYCLES = 400;

    public function __construct(protected WinnerStreakCalculator $calculator)
    {
    }

    /**
     * Advance every enabled board to the present: close any window whose end
     * has passed and open the current one.
     *
     * @return array{checked: int, closed: int, opened: int, announced: int}
     */
    public function tick(?Carbon $now = null): array
    {
        $now = $now ? $now->copy() : now();
        $checked = 0;
        $closed = 0;
        $opened = 0;

        $settings = WinnerStreakSetting::query()->where('enabled', true)->get();

        foreach ($settings as $setting) {
            $checked++;

            try {
                $result = $this->advance($setting, $now);
                $closed += $result['closed'];
                $opened += $result['opened'];
            } catch (\Throwable $e) {
                // One misconfigured branch must not stop the others.
                Log::warning('Winner streak tick failed for a branch.', [
                    'branch_id' => $setting->branch_id,
                    'period' => $setting->period,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $announced = $this->flushHeldAnnouncements($now);

        return [
            'checked' => $checked,
            'closed' => $closed,
            'opened' => $opened,
            'announced' => $announced,
        ];
    }

    /**
     * @return array{closed: int, opened: int}
     */
    public function advance(WinnerStreakSetting $settings, ?Carbon $now = null): array
    {
        $now = $now ? $now->copy() : now();
        $closed = 0;
        $opened = 0;

        $cycle = $this->openCycle($settings, $now);
        if ($cycle->wasRecentlyCreated) {
            $opened++;
        }

        $guard = 0;
        while ($cycle->ends_at && $now->gte($cycle->ends_at) && $guard++ < self::MAX_CATCHUP_CYCLES) {
            $this->close($cycle, $settings, null);
            $closed++;

            $cycle = $this->startCycle($settings, $cycle->ends_at->copy());
            $opened++;
        }

        $settings->forceFill([
            'next_reset_at' => $cycle->ends_at,
        ])->save();

        return ['closed' => $closed, 'opened' => $opened];
    }

    /**
     * The live cycle for a branch/period, created on demand.
     */
    public function openCycle(WinnerStreakSetting $settings, ?Carbon $now = null): WinnerStreakCycle
    {
        $now = $now ? $now->copy() : now();

        $cycle = WinnerStreakCycle::query()
            ->where('branch_id', $settings->branch_id)
            ->where('period', $settings->period)
            ->open()
            ->latest('starts_at')
            ->first();

        if ($cycle) {
            return $cycle;
        }

        return $this->startCycle($settings, WinnerStreakPeriod::boundaryBefore($now, $settings));
    }

    /**
     * Open a cycle that begins exactly at $startsAt.
     */
    public function startCycle(WinnerStreakSetting $settings, Carbon $startsAt): WinnerStreakCycle
    {
        // Always the next real boundary — a cycle may start off-schedule (manual
        // reset, cron catch-up) but must never end off-schedule.
        $endsAt = WinnerStreakPeriod::nextBoundaryAfter($startsAt, $settings);

        $cycle = WinnerStreakCycle::query()->create([
            'branch_id' => $settings->branch_id,
            'period' => $settings->period,
            'label' => WinnerStreakPeriod::label($startsAt, $settings->period),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => WinnerStreakCycle::STATUS_OPEN,
            'tag_id' => $settings->tag_id,
            'top_n' => $settings->top_n,
        ]);

        $cycle->wasRecentlyCreated = true;

        return $cycle;
    }

    /**
     * Freeze a cycle's winners and mark it closed.
     *
     * $closedBy is the admin id for a manual "Reset Now", null for the
     * scheduler.
     */
    public function close(WinnerStreakCycle $cycle, WinnerStreakSetting $settings, ?int $closedBy = null): WinnerStreakCycle
    {
        if ($cycle->status === WinnerStreakCycle::STATUS_CLOSED) {
            return $cycle;
        }

        $endsAt = $cycle->ends_at ?: now();

        $ranked = $this->calculator->rank(
            (int) $cycle->branch_id,
            $cycle->starts_at->copy(),
            $endsAt->copy(),
            $settings,
        );

        $boards = [$ranked['profit']];
        if ($settings->loss_board_enabled) {
            $boards[] = $ranked['loss'];
        }

        DB::transaction(function () use ($cycle, $settings, $boards, $ranked, $endsAt, $closedBy): void {
            foreach ($boards as $rows) {
                foreach ($rows as $row) {
                    WinnerStreakEntry::query()->updateOrCreate(
                        [
                            'cycle_id' => $cycle->id,
                            'kind' => $row['kind'],
                            'user_id' => $row['user_id'],
                        ],
                        [
                            'branch_id' => $cycle->branch_id,
                            'period' => $cycle->period,
                            'rank' => $row['rank'],
                            'deposit_total' => $row['deposit_total'],
                            'withdrawal_total' => $row['withdrawal_total'],
                            'bonus_total' => $row['bonus_total'],
                            'net_amount' => $row['net_amount'],
                            'transactions_count' => $row['transactions_count'],
                            'display_name' => $row['display_name'],
                            'display_play_id' => $row['display_play_id'],
                            'display_phone' => $row['display_phone'],
                            // Only the profit board carries a reward.
                            'reward_amount' => $row['kind'] === WinnerStreakEntry::KIND_PROFIT
                                ? $settings->reward_amount
                                : 0,
                            'reward_label' => $row['kind'] === WinnerStreakEntry::KIND_PROFIT
                                ? $settings->reward_label
                                : null,
                            'reward_status' => WinnerStreakEntry::REWARD_PENDING,
                        ],
                    );
                }
            }

            $cycle->forceFill([
                'ends_at' => $endsAt,
                'closed_at' => now(),
                'status' => WinnerStreakCycle::STATUS_CLOSED,
                'participants_count' => $ranked['participants'],
                'closed_by' => $closedBy,
            ])->save();

            $settings->forceFill(['last_reset_at' => now()])->save();
        });

        // Fresh winners must reach the dashboards now, not up to two minutes
        // later when the cached feed expires.
        WinnerStreakService::flushBranch(
            (int) $cycle->branch_id,
            $cycle->period,
            (int) $cycle->id,
        );

        $this->announce($cycle, $settings, $ranked['profit']);

        return $cycle->refresh();
    }

    /**
     * Re-run a CLOSED cycle's ranking against the current transaction data.
     *
     * Needed because a frozen board can go stale: an admin approves a deposit,
     * the cycle closes, and only then is that deposit reversed to rejected. The
     * entry keeps the old number forever unless it is recomputed.
     *
     * Rewards already handed over are never disturbed — a paid entry keeps its
     * paid status, and survives even if the recount drops it out of the top N,
     * because the money is gone either way and the audit row has to stay.
     *
     * @return array{added: int, updated: int, kept_paid: int, removed: int}
     */
    public function recalculate(WinnerStreakCycle $cycle, WinnerStreakSetting $settings): array
    {
        if ($cycle->status !== WinnerStreakCycle::STATUS_CLOSED) {
            return ['added' => 0, 'updated' => 0, 'kept_paid' => 0, 'removed' => 0];
        }

        $ranked = $this->calculator->rank(
            (int) $cycle->branch_id,
            $cycle->starts_at->copy(),
            ($cycle->ends_at ?: now())->copy(),
            $settings,
        );

        $boards = [$ranked['profit']];
        if ($settings->loss_board_enabled) {
            $boards[] = $ranked['loss'];
        }

        $stats = ['added' => 0, 'updated' => 0, 'kept_paid' => 0, 'removed' => 0];

        DB::transaction(function () use ($cycle, $settings, $boards, $ranked, &$stats): void {
            $seen = [];

            foreach ($boards as $rows) {
                foreach ($rows as $row) {
                    $key = $row['kind'] . ':' . $row['user_id'];
                    $seen[] = $key;

                    $entry = WinnerStreakEntry::query()->firstOrNew([
                        'cycle_id' => $cycle->id,
                        'kind' => $row['kind'],
                        'user_id' => $row['user_id'],
                    ]);

                    $isNew = !$entry->exists;
                    $wasPaid = $entry->reward_status === WinnerStreakEntry::REWARD_PAID;

                    $entry->fill([
                        'branch_id' => $cycle->branch_id,
                        'period' => $cycle->period,
                        'rank' => $row['rank'],
                        'deposit_total' => $row['deposit_total'],
                        'withdrawal_total' => $row['withdrawal_total'],
                        'bonus_total' => $row['bonus_total'],
                        'net_amount' => $row['net_amount'],
                        'transactions_count' => $row['transactions_count'],
                        'display_name' => $row['display_name'],
                        'display_play_id' => $row['display_play_id'],
                        'display_phone' => $row['display_phone'],
                    ]);

                    if ($isNew) {
                        $entry->reward_amount = $row['kind'] === WinnerStreakEntry::KIND_PROFIT
                            ? $settings->reward_amount
                            : 0;
                        $entry->reward_label = $row['kind'] === WinnerStreakEntry::KIND_PROFIT
                            ? $settings->reward_label
                            : null;
                        $entry->reward_status = WinnerStreakEntry::REWARD_PENDING;
                        $stats['added']++;
                    } else {
                        $stats['updated']++;
                    }

                    $entry->save();

                    if ($wasPaid) {
                        $stats['kept_paid']++;
                    }
                }
            }

            // Entries that no longer rank: drop them unless the reward is
            // already out the door, in which case the audit row has to stay.
            $stale = WinnerStreakEntry::query()
                ->where('cycle_id', $cycle->id)
                ->get()
                ->filter(fn (WinnerStreakEntry $e) => !in_array($e->kind . ':' . $e->user_id, $seen, true));

            foreach ($stale as $entry) {
                if ($entry->reward_status === WinnerStreakEntry::REWARD_PAID) {
                    $stats['kept_paid']++;
                    continue;
                }

                $entry->delete();
                $stats['removed']++;
            }

            $cycle->forceFill(['participants_count' => $ranked['participants']])->save();
        });

        WinnerStreakService::flushBranch(
            (int) $cycle->branch_id,
            $cycle->period,
            (int) $cycle->id,
        );

        return $stats;
    }

    /**
     * Manual "Reset Now": end the live cycle at this instant, then open the
     * next one aligned back to the configured schedule.
     */
    public function resetNow(WinnerStreakSetting $settings, ?int $adminId = null): WinnerStreakCycle
    {
        $now = now();

        $cycle = $this->openCycle($settings, $now);
        $cycle->ends_at = $now->copy();
        $this->close($cycle, $settings, $adminId);

        // startCycle already pins the end to the next scheduled boundary, so a
        // manual reset shortens this one window and leaves the rhythm intact.
        $next = $this->startCycle($settings, $now->copy());

        $settings->forceFill(['next_reset_at' => $next->ends_at])->save();

        return $next;
    }

    /**
     * Is now inside the branch's do-not-disturb window?
     *
     * The window normally wraps midnight (22:00 -> 08:00), so "inside" is an OR
     * of the two halves rather than a simple between.
     */
    public function inQuietHours(WinnerStreakSetting $settings, ?Carbon $now = null): bool
    {
        if (!$settings->quiet_hours_enabled) {
            return false;
        }

        $from = $this->minutesOfDay((string) $settings->quiet_from, 22 * 60);
        $to = $this->minutesOfDay((string) $settings->quiet_to, 8 * 60);

        if ($from === $to) {
            return false;
        }

        $now = ($now ?? now())->copy()->setTimezone(config('app.timezone'));
        $minutes = $now->hour * 60 + $now->minute;

        return $from < $to
            ? ($minutes >= $from && $minutes < $to)
            : ($minutes >= $from || $minutes < $to);
    }

    protected function minutesOfDay(string $value, int $fallback): int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m)) {
            return $fallback;
        }

        return min(23, (int) $m[1]) * 60 + min(59, (int) $m[2]);
    }

    /**
     * Deliver announcements that were held back by quiet hours.
     *
     * Only cycles closed in the last day are considered — a winner from a week
     * ago is stale news, and pushing it would confuse more than it delights.
     */
    public function flushHeldAnnouncements(?Carbon $now = null): int
    {
        $now = $now ? $now->copy() : now();
        $sent = 0;

        $settings = WinnerStreakSetting::query()
            ->where('enabled', true)
            ->where('announce_push', true)
            ->get();

        foreach ($settings as $setting) {
            if ($this->inQuietHours($setting, $now)) {
                continue;
            }

            $pending = WinnerStreakCycle::query()
                ->where('branch_id', $setting->branch_id)
                ->where('period', $setting->period)
                ->closed()
                ->where('announced', false)
                ->where('closed_at', '>=', $now->copy()->subDay())
                ->orderByDesc('closed_at')
                ->get();

            foreach ($pending as $cycle) {
                $winners = $cycle->entries()
                    ->where('kind', WinnerStreakEntry::KIND_PROFIT)
                    ->orderBy('rank')
                    ->get()
                    ->map(fn (WinnerStreakEntry $e) => [
                        'display_name' => $e->display_name,
                        'display_play_id' => $e->display_play_id,
                        'net_amount' => (float) $e->net_amount,
                    ])
                    ->all();

                if ($winners === []) {
                    // Nothing to say — mark it done so it stops being scanned.
                    $cycle->forceFill(['announced' => true])->save();
                    continue;
                }

                $this->announce($cycle, $setting, $winners);
                if ($cycle->refresh()->announced) {
                    $sent++;
                }
            }
        }

        return $sent;
    }

    /**
     * Push the fresh winners to every user in the branch.
     *
     * @param  array<int, array<string, mixed>>  $winners
     */
    protected function announce(WinnerStreakCycle $cycle, WinnerStreakSetting $settings, array $winners): void
    {
        if (!$settings->announce_push || $winners === [] || $cycle->announced) {
            return;
        }

        // Held, not dropped. A board that resets at midnight would otherwise
        // buzz every phone in the branch at midnight; the cycle still closes on
        // time and `announced` stays false so the next tick delivers it once
        // the quiet window ends.
        if ($this->inQuietHours($settings)) {
            Log::info('Winner streak announcement held for quiet hours.', [
                'cycle_id' => $cycle->id,
                'branch_id' => $cycle->branch_id,
            ]);

            return;
        }

        $top = $winners[0];
        $label = WinnerStreakPeriod::periodLabel($cycle->period);
        $who = WinnerStreakPresenter::publicName($top['display_name'], $top['display_play_id'], $settings);

        try {
            UserPushNotifier::notifyWinnerAnnouncement(
                (int) $cycle->branch_id,
                "{$label} Winner \u{1F3C6}",
                "{$who} ne " . WinnerStreakPresenter::publicAmount((float) $top['net_amount'], $settings) . " jeeta!",
            );

            $cycle->forceFill(['announced' => true])->save();
        } catch (\Throwable $e) {
            Log::warning('Winner streak announcement failed.', [
                'cycle_id' => $cycle->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
