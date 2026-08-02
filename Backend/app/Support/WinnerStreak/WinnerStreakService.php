<?php

namespace App\Support\WinnerStreak;

use App\Models\Branch;
use App\Models\Tag;
use App\Models\WinnerStreakCycle;
use App\Models\WinnerStreakEntry;
use App\Models\WinnerStreakSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Everything the Admin and Super Admin panels need from the Winner Streak
 * feature, in one place, so the two controllers stay thin and identical in
 * behaviour — only their branch resolution differs.
 */
class WinnerStreakService
{
    /** Live board cache. Short enough to feel live, long enough to keep the
     *  aggregation off every page load. */
    protected const BOARD_TTL = 60;

    /** Highest `cycles` value the user feed accepts — every variant has to be
     *  dropped when the winners change. Mirrors the cap in the User controller. */
    protected const USER_FEED_VARIANTS = 10;

    public static function boardKey(int $branchId, string $period, int $cycleId): string
    {
        return "winner-streak:board:{$branchId}:{$period}:{$cycleId}";
    }

    public static function userFeedKey(int $branchId, int $cycles): string
    {
        return "winner-streak:user:{$branchId}:{$cycles}";
    }

    /**
     * Drop every cached read for a branch.
     *
     * Called whenever the numbers behind them change — a settings edit, a
     * cycle realignment, a close. Without this an admin who fixes a wrong
     * reset time still stares at the old board for a minute and assumes the
     * save failed.
     */
    public static function flushBranch(int $branchId, ?string $period = null, ?int $cycleId = null): void
    {
        if ($period !== null && $cycleId !== null) {
            Cache::forget(self::boardKey($branchId, $period, $cycleId));
        } else {
            // Called without a specific cycle ("drop everything for this
            // branch"). The board key embeds the cycle id, so the live cycles
            // have to be looked up — otherwise this silently clears only the
            // user feed and leaves a stale admin board behind.
            WinnerStreakCycle::query()
                ->where('branch_id', $branchId)
                ->open()
                ->get(['id', 'period'])
                ->each(fn (WinnerStreakCycle $cycle) => Cache::forget(
                    self::boardKey($branchId, $cycle->period, (int) $cycle->id),
                ));
        }

        for ($i = 1; $i <= self::USER_FEED_VARIANTS; $i++) {
            Cache::forget(self::userFeedKey($branchId, $i));
        }
    }

    public function __construct(
        protected WinnerStreakCalculator $calculator,
        protected WinnerStreakCycleManager $cycles,
    ) {
    }

    /**
     * Settings + live board + open-cycle info for one period.
     *
     * @return array<string, mixed>
     */
    public function period(int $branchId, string $period): array
    {
        $settings = WinnerStreakSetting::forBranch($branchId, $period);
        $cycle = $this->cycles->openCycle($settings);

        // Keep the advertised reset time honest even before the first tick.
        if ($settings->next_reset_at?->ne($cycle->ends_at)) {
            $settings->forceFill(['next_reset_at' => $cycle->ends_at])->save();
        }

        $board = $this->liveBoard($settings, $cycle);

        return [
            'settings' => $this->serializeSettings($settings),
            'cycle' => $this->serializeCycle($cycle),
            'board' => $board,
        ];
    }

    /**
     * All three boards for a branch.
     *
     * @return array<string, mixed>
     */
    public function overview(int $branchId): array
    {
        $periods = [];

        foreach (WinnerStreakSetting::PERIODS as $period) {
            $periods[$period] = $this->period($branchId, $period);
        }

        return [
            'periods' => $periods,
            'tags' => Tag::query()
                ->where('branch_id', $branchId)
                ->orderBy('name')
                ->get()
                ->map(fn (Tag $tag) => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color,
                ])
                ->all(),
        ];
    }

    /**
     * The board as it stands right now — computed, not frozen.
     *
     * @return array{profit: array<int, mixed>, loss: array<int, mixed>, participants: int}
     */
    public function liveBoard(WinnerStreakSetting $settings, WinnerStreakCycle $cycle): array
    {
        $key = self::boardKey((int) $settings->branch_id, $settings->period, (int) $cycle->id);

        return Cache::remember($key, self::BOARD_TTL, function () use ($settings, $cycle) {
            $ranked = $this->calculator->rank(
                (int) $settings->branch_id,
                $cycle->starts_at->copy(),
                now(),
                $settings,
            );

            return [
                'profit' => $ranked['profit'],
                // Loss stays out of any payload the user panel can reach; the
                // admin controllers are the only callers of this method.
                'loss' => $settings->loss_board_enabled ? $ranked['loss'] : [],
                'participants' => $ranked['participants'],
            ];
        });
    }

    /**
     * Closed cycles with their frozen winners, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function history(int $branchId, string $period, int $limit = 12): array
    {
        $cycles = WinnerStreakCycle::query()
            ->where('branch_id', $branchId)
            ->where('period', $period)
            ->closed()
            ->with(['entries' => fn ($q) => $q->orderBy('kind')->orderBy('rank')])
            ->orderByDesc('starts_at')
            ->limit(max(1, min(60, $limit)))
            ->get();

        // One lookup for every winner across every cycle on the page, rather
        // than a query per entry.
        $userIds = $cycles
            ->flatMap(fn (WinnerStreakCycle $cycle) => $cycle->entries->pluck('user_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $shared = SharedPayoutDetector::forUsers($branchId, $userIds);

        return $cycles->map(function (WinnerStreakCycle $cycle) use ($shared) {
            $render = fn (WinnerStreakEntry $e) => WinnerStreakPresenter::forAdmin($e)
                + ['shared_payout' => $shared[(int) $e->user_id] ?? null];

            $data = $this->serializeCycle($cycle);
            $data['profit'] = $cycle->entries
                ->where('kind', WinnerStreakEntry::KIND_PROFIT)
                ->map($render)
                ->values()
                ->all();
            $data['loss'] = $cycle->entries
                ->where('kind', WinnerStreakEntry::KIND_LOSS)
                ->map($render)
                ->values()
                ->all();

            return $data;
        })->all();
    }

    /**
     * Compact per-branch summary for the Super Admin landing view: is each
     * board on, when does it reset, and who won last time.
     *
     * Reads only frozen entries — no aggregation — so it stays cheap however
     * many branches exist.
     *
     * @return array<int, array<string, mixed>>
     */
    public function branchSummaries(): array
    {
        $branches = Branch::query()->orderBy('name')->get(['id', 'name', 'code', 'is_active']);

        $settings = WinnerStreakSetting::query()
            ->get()
            ->groupBy('branch_id');

        // Latest closed cycle per (branch, period), newest first.
        $cycles = WinnerStreakCycle::query()
            ->closed()
            ->orderByDesc('starts_at')
            ->get(['id', 'branch_id', 'period', 'label', 'starts_at', 'closed_at']);

        $latestCycle = [];
        foreach ($cycles as $cycle) {
            $key = $cycle->branch_id . ':' . $cycle->period;
            if (!isset($latestCycle[$key])) {
                $latestCycle[$key] = $cycle;
            }
        }

        $winners = WinnerStreakEntry::query()
            ->whereIn('cycle_id', array_map(fn ($c) => $c->id, $latestCycle))
            ->where('kind', WinnerStreakEntry::KIND_PROFIT)
            ->where('rank', 1)
            ->get()
            ->keyBy('cycle_id');

        return $branches->map(function (Branch $branch) use ($settings, $latestCycle, $winners) {
            $branchSettings = ($settings[$branch->id] ?? collect())->keyBy('period');

            $periods = [];
            foreach (WinnerStreakSetting::PERIODS as $period) {
                /** @var WinnerStreakSetting|null $s */
                $s = $branchSettings[$period] ?? null;
                $cycle = $latestCycle[$branch->id . ':' . $period] ?? null;
                $winner = $cycle ? $winners->get($cycle->id) : null;

                $periods[$period] = [
                    'enabled' => (bool) ($s?->enabled ?? false),
                    'next_reset_at' => $s?->next_reset_at?->toIso8601String(),
                    'last_cycle_label' => $cycle?->label,
                    'last_winner' => $winner ? [
                        'name' => $winner->display_name,
                        'play_id' => $winner->display_play_id,
                        'net_amount' => (float) $winner->net_amount,
                        'reward_status' => $winner->reward_status,
                    ] : null,
                ];
            }

            return [
                'branch_id' => (int) $branch->id,
                'branch_name' => $branch->name,
                'branch_code' => $branch->code,
                'is_active' => (bool) $branch->is_active,
                'periods' => $periods,
            ];
        })->all();
    }

    /**
     * Apply a validated settings payload.
     */
    public function updateSettings(int $branchId, string $period, array $data, ?int $adminId): WinnerStreakSetting
    {
        $settings = WinnerStreakSetting::forBranch($branchId, $period);

        // A tag can only be used as a group if it belongs to this branch.
        foreach (['tag_id', 'exclude_tag_id'] as $field) {
            if (array_key_exists($field, $data) && $data[$field]) {
                $owned = Tag::query()
                    ->where('id', $data[$field])
                    ->where('branch_id', $branchId)
                    ->exists();
                if (!$owned) {
                    $data[$field] = null;
                }
            }
        }

        $data['updated_by'] = $adminId;
        $settings->fill($data)->save();

        $this->realignOpenCycle($settings);

        return $settings->refresh();
    }

    /**
     * Move the live cycle onto the schedule the admin just saved.
     *
     * Both ends move: changing the reset time from 00:00 to 21:00 turns
     * "today" into "yesterday 21:00 -> today 21:00", and leaving starts_at
     * behind would silently drop every transaction before midnight from the
     * board.
     *
     * The new start is clamped to the end of the last CLOSED cycle so a
     * schedule change can never pull already-frozen transactions into the live
     * window and count them twice.
     */
    protected function realignOpenCycle(WinnerStreakSetting $settings): void
    {
        $cycle = $this->cycles->openCycle($settings);

        $start = WinnerStreakPeriod::boundaryBefore(now(), $settings);
        $end = WinnerStreakPeriod::boundaryAfter($start, $settings);

        $lastClosedEnd = WinnerStreakCycle::query()
            ->where('branch_id', $settings->branch_id)
            ->where('period', $settings->period)
            ->closed()
            ->orderByDesc('ends_at')
            ->value('ends_at');

        if ($lastClosedEnd) {
            $floor = Carbon::parse($lastClosedEnd);
            if ($floor->gt($start)) {
                $start = $floor;
            }
        }

        $cycle->forceFill([
            'starts_at' => $start,
            'ends_at' => $end,
            'label' => WinnerStreakPeriod::label($start, $settings->period),
        ])->save();

        $settings->forceFill(['next_reset_at' => $end])->save();

        self::flushBranch((int) $settings->branch_id, $settings->period, (int) $cycle->id);
    }

    public function resetNow(int $branchId, string $period, ?int $adminId): WinnerStreakCycle
    {
        $settings = WinnerStreakSetting::forBranch($branchId, $period);

        return $this->cycles->resetNow($settings, $adminId);
    }

    /**
     * Re-rank a closed cycle against today's transaction data.
     *
     * @return array{added: int, updated: int, kept_paid: int, removed: int}
     */
    public function recalculate(WinnerStreakCycle $cycle): array
    {
        $settings = WinnerStreakSetting::forBranch((int) $cycle->branch_id, $cycle->period);

        return $this->cycles->recalculate($cycle, $settings);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeSettings(WinnerStreakSetting $s): array
    {
        return [
            'branch_id' => (int) $s->branch_id,
            'period' => $s->period,
            'enabled' => (bool) $s->enabled,
            'tag_id' => $s->tag_id,
            'exclude_tag_id' => $s->exclude_tag_id,
            'top_n' => (int) $s->top_n,
            'loss_board_enabled' => (bool) $s->loss_board_enabled,
            'loss_board_public' => (bool) $s->loss_board_public,
            'min_turnover' => (float) $s->min_turnover,
            'min_transactions' => (int) $s->min_transactions,
            'reset_time' => $s->reset_time,
            'reset_weekday' => (int) $s->reset_weekday,
            'reset_day_of_month' => (int) $s->reset_day_of_month,
            'last_reset_at' => $s->last_reset_at?->toIso8601String(),
            'next_reset_at' => $s->next_reset_at?->toIso8601String(),
            'show_name' => (bool) $s->show_name,
            'show_play_id' => (bool) $s->show_play_id,
            'show_phone' => (bool) $s->show_phone,
            'show_amount' => (bool) $s->show_amount,
            'show_profit_loss' => (bool) $s->show_profit_loss,
            'show_reward' => (bool) $s->show_reward,
            'mask_amount_bucket' => (bool) $s->mask_amount_bucket,
            'mask_name' => (bool) $s->mask_name,
            'reward_amount' => (float) $s->reward_amount,
            'reward_label' => $s->reward_label,
            'announce_push' => (bool) $s->announce_push,
            'winner_cooldown_cycles' => (int) $s->winner_cooldown_cycles,
            'quiet_hours_enabled' => (bool) $s->quiet_hours_enabled,
            'quiet_from' => $s->quiet_from,
            'quiet_to' => $s->quiet_to,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeCycle(WinnerStreakCycle $c): array
    {
        return [
            'id' => $c->id,
            'branch_id' => (int) $c->branch_id,
            'period' => $c->period,
            'label' => $c->label,
            'status' => $c->status,
            'starts_at' => $c->starts_at?->toIso8601String(),
            'ends_at' => $c->ends_at?->toIso8601String(),
            'closed_at' => $c->closed_at?->toIso8601String(),
            'participants_count' => (int) $c->participants_count,
            'announced' => (bool) $c->announced,
        ];
    }
}
