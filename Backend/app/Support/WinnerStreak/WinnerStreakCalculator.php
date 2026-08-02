<?php

namespace App\Support\WinnerStreak;

use App\Models\Deposit;
use App\Models\User;
use App\Models\WinnerStreakCycle;
use App\Models\WinnerStreakEntry;
use App\Models\WinnerStreakSetting;
use App\Models\Withdrawal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ranks a branch's users by net result over a time window.
 *
 *     net = approved withdrawals + fulfilled bonus rewards - approved deposits
 *
 * Positive net = the user took money out of the house (Profit / Winner).
 * Negative net = the user is down (Loss).
 *
 * The window is matched on the APPROVAL timestamp, not created_at: a deposit
 * raised yesterday and approved today belongs to today's cycle, which is the
 * only reading that keeps a closed cycle's numbers final.
 *
 * This runs against the raw deposit/withdrawal tables, so it is only ever
 * called from the admin panel (short cache) or once per cycle close. The
 * user-facing feed reads frozen winner_streak_entries instead.
 */
class WinnerStreakCalculator
{
    /**
     * Rank users for one branch over [$from, $to).
     *
     * @return array{
     *     profit: array<int, array<string, mixed>>,
     *     loss: array<int, array<string, mixed>>,
     *     participants: int
     * }
     */
    public function rank(
        int $branchId,
        Carbon $from,
        Carbon $to,
        WinnerStreakSetting $settings,
    ): array {
        $userIds = $this->eligibleUserIds($branchId, $settings);

        if ($userIds === []) {
            return ['profit' => [], 'loss' => [], 'participants' => 0];
        }

        $deposits = $this->depositTotals($branchId, $userIds, $from, $to);
        $withdrawals = $this->withdrawalTotals($branchId, $userIds, $from, $to);
        $bonuses = $this->bonusTotals($branchId, $userIds, $from, $to);

        $touched = array_unique(array_merge(
            array_keys($deposits),
            array_keys($withdrawals),
            array_keys($bonuses),
        ));

        if ($touched === []) {
            return ['profit' => [], 'loss' => [], 'participants' => 0];
        }

        $users = User::query()
            ->whereIn('id', $touched)
            ->get(['id', 'name', 'phone', 'play_id', 'unique_number'])
            ->keyBy('id');

        $minTurnover = (float) $settings->min_turnover;
        $minTransactions = (int) $settings->min_transactions;

        $rows = [];

        foreach ($touched as $userId) {
            $user = $users->get($userId);
            if (!$user) {
                continue;
            }

            $depositTotal = (float) ($deposits[$userId]['amount'] ?? 0);
            $withdrawalTotal = (float) ($withdrawals[$userId]['amount'] ?? 0);
            $bonusTotal = (float) ($bonuses[$userId] ?? 0);
            $transactions = (int) ($deposits[$userId]['count'] ?? 0)
                + (int) ($withdrawals[$userId]['count'] ?? 0);

            // Anti-gaming floor: without it a ₹100 deposit / ₹150 withdrawal
            // outranks a genuine high-volume player.
            if ($depositTotal < $minTurnover) {
                continue;
            }
            if ($transactions < $minTransactions) {
                continue;
            }

            $rows[] = [
                'user_id' => (int) $userId,
                'display_name' => $user->name,
                'display_play_id' => $user->play_id ?: $user->unique_number,
                'display_phone' => $user->phone,
                'deposit_total' => round($depositTotal, 2),
                'withdrawal_total' => round($withdrawalTotal, 2),
                'bonus_total' => round($bonusTotal, 2),
                'net_amount' => round($withdrawalTotal + $bonusTotal - $depositTotal, 2),
                'transactions_count' => $transactions,
            ];
        }

        $topN = max(1, (int) $settings->top_n);

        $profit = array_values(array_filter($rows, fn (array $r) => $r['net_amount'] > 0));
        usort($profit, fn ($a, $b) => $this->compare($b, $a));
        $profit = array_slice($profit, 0, $topN);

        $loss = array_values(array_filter($rows, fn (array $r) => $r['net_amount'] < 0));
        // Biggest loss first, so the comparison runs the other way round.
        usort($loss, fn ($a, $b) => $this->compare($a, $b));
        $loss = array_slice($loss, 0, $topN);

        return [
            'profit' => $this->withRanks($profit, WinnerStreakEntry::KIND_PROFIT),
            'loss' => $this->withRanks($loss, WinnerStreakEntry::KIND_LOSS),
            'participants' => count($rows),
        ];
    }

    /**
     * Ranking order, highest-first.
     *
     * Net alone leaves ties broken at random, which matters at the Top-N
     * cutoff: two players on the same net where one takes the reward and the
     * other gets nothing is a dispute waiting to happen. Turnover breaks the
     * tie (the player who staked more ranks higher) and the user id is a final
     * deterministic fallback so repeated runs agree.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    protected function compare(array $a, array $b): int
    {
        return [$a['net_amount'], $a['deposit_total'], -$a['user_id']]
            <=> [$b['net_amount'], $b['deposit_total'], -$b['user_id']];
    }

    /**
     * Users allowed to compete: the branch, narrowed to the group tag if one is
     * configured, minus anyone carrying the exclude tag, minus anyone still
     * inside the winner cooldown.
     *
     * @return array<int, int>
     */
    public function eligibleUserIds(int $branchId, WinnerStreakSetting $settings): array
    {
        $query = User::query()
            ->where('branch_id', $branchId)
            ->where('status', User::STATUS_ACTIVE);

        if ($settings->tag_id) {
            $query->whereHas('tags', fn ($q) => $q->where('tags.id', $settings->tag_id));
        }

        if ($settings->exclude_tag_id) {
            $query->whereDoesntHave('tags', fn ($q) => $q->where('tags.id', $settings->exclude_tag_id));
        }

        $cooling = $this->cooldownUserIds($branchId, $settings);
        if ($cooling !== []) {
            $query->whereNotIn('id', $cooling);
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Users who won recently enough to be sitting out.
     *
     * Only the #1 of each recent cycle is benched, never the whole podium. On a
     * small branch, benching everyone who placed empties the board completely —
     * three players, three podium spots, and the next cycle has nobody left.
     * The point of the cooldown is to stop one player owning the top spot, and
     * rank 1 is exactly that player.
     *
     * `winner_cooldown_cycles = 0` (the default) disables it entirely.
     *
     * @return array<int, int>
     */
    public function cooldownUserIds(int $branchId, WinnerStreakSetting $settings): array
    {
        $cooldown = (int) $settings->winner_cooldown_cycles;
        if ($cooldown < 1) {
            return [];
        }

        $recentCycleIds = WinnerStreakCycle::query()
            ->where('branch_id', $branchId)
            ->where('period', $settings->period)
            ->closed()
            ->orderByDesc('starts_at')
            ->limit($cooldown)
            ->pluck('id');

        if ($recentCycleIds->isEmpty()) {
            return [];
        }

        return WinnerStreakEntry::query()
            ->whereIn('cycle_id', $recentCycleIds)
            ->where('kind', WinnerStreakEntry::KIND_PROFIT)
            ->where('rank', 1)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, array{amount: float, count: int}>
     */
    protected function depositTotals(int $branchId, array $userIds, Carbon $from, Carbon $to): array
    {
        // Plain column comparison, never a function on approved_at — that is
        // what lets deposits_branch_status_approved_idx be used. Legacy rows
        // had approved_at backfilled from updated_at by the index migration.
        $rows = DB::table('deposits')
            ->select('user_id', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as cnt'))
            ->where('branch_id', $branchId)
            ->whereIn('user_id', $userIds)
            ->where('status', Deposit::STATUS_APPROVED)
            ->where('approved_at', '>=', $from)
            ->where('approved_at', '<', $to)
            ->groupBy('user_id')
            ->get();

        return $this->keyTotals($rows);
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, array{amount: float, count: int}>
     */
    protected function withdrawalTotals(int $branchId, array $userIds, Carbon $from, Carbon $to): array
    {
        $rows = DB::table('withdrawals')
            ->select('user_id', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as cnt'))
            ->where('branch_id', $branchId)
            ->whereIn('user_id', $userIds)
            ->where('status', Withdrawal::STATUS_APPROVED)
            ->where('processed_at', '>=', $from)
            ->where('processed_at', '<', $to)
            ->groupBy('user_id')
            ->get();

        return $this->keyTotals($rows);
    }

    /**
     * Bonus rewards actually handed over in the window. They are money the
     * house paid out, so they count on the user's profit side.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, float>
     */
    protected function bonusTotals(int $branchId, array $userIds, Carbon $from, Carbon $to): array
    {
        if (!DB::getSchemaBuilder()->hasTable('bonus_code_redemptions')) {
            return [];
        }

        $rows = DB::table('bonus_code_redemptions')
            ->select('user_id', DB::raw('SUM(amount) as total'))
            ->where('branch_id', $branchId)
            ->whereIn('user_id', $userIds)
            ->where('status', 'fulfilled')
            ->where('fulfilled_at', '>=', $from)
            ->where('fulfilled_at', '<', $to)
            ->groupBy('user_id')
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row->user_id] = (float) $row->total;
        }

        return $totals;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return array<int, array{amount: float, count: int}>
     */
    protected function keyTotals($rows): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row->user_id] = [
                'amount' => (float) $row->total,
                'count' => (int) $row->cnt,
            ];
        }

        return $totals;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function withRanks(array $rows, string $kind): array
    {
        foreach ($rows as $index => $row) {
            $rows[$index]['kind'] = $kind;
            $rows[$index]['rank'] = $index + 1;
        }

        return $rows;
    }
}
