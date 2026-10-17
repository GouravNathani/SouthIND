<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The super admin's business dashboard: month-by-month growth, churn, new and
 * lost players, signup cohorts and the players worth calling back.
 *
 * "Active" means at least one APPROVED deposit or withdrawal (by request time)
 * in the period — the same signal the clubs use, so a player who only opens
 * the app does not count. A player has "left" once their last approved
 * transaction is more than LOST_AFTER_DAYS old.
 *
 * Everything comes from a handful of grouped queries folded together in PHP,
 * and the result is cached for a few minutes because the home page polls.
 */
class BusinessDashboardController extends Controller
{
    private const MONTHS = 12;

    private const COHORTS = 6;

    private const LOST_AFTER_DAYS = 30;

    private const AT_RISK_AFTER_DAYS = 14;

    private const LIST_SIZE = 10;

    private const CACHE_SECONDS = 300;

    public function __invoke(Request $request): JsonResponse
    {
        $branchId = $request->filled('branch_id') ? (int) $request->query('branch_id') : null;

        $data = Cache::remember(
            'super:business-dashboard:' . ($branchId ?? 'all'),
            self::CACHE_SECONDS,
            fn () => $this->build($branchId),
        );

        return response()->json(['data' => $data], Response::HTTP_OK);
    }

    /** @return array<string, mixed> */
    private function build(?int $branchId): array
    {
        $now = now();
        $thisMonth = $now->copy()->startOfMonth();
        $firstMonth = $thisMonth->copy()->subMonths(self::MONTHS - 1);
        $lostBefore = $now->copy()->subDays(self::LOST_AFTER_DAYS)->format('Y-m-d H:i:s');
        $riskBefore = $now->copy()->subDays(self::AT_RISK_AFTER_DAYS)->format('Y-m-d H:i:s');

        // One label per month, plus the month before the window so the first
        // month also gets a churn and growth figure.
        $labels = [];
        for ($i = -1; $i < self::MONTHS; $i++) {
            $labels[] = $firstMonth->copy()->addMonths($i)->format('Y-m');
        }

        $users = DB::table('users')
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->get(['id', 'branch_id', 'status', 'user_type', 'created_at', 'last_seen_at']);

        $deposits = $this->approved('deposits', $branchId)
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as n, SUM(amount) as total, MIN(created_at) as first_at, MAX(created_at) as last_at')
            ->get()
            ->keyBy('user_id');

        $withdrawals = $this->approved('withdrawals', $branchId)
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as n, SUM(amount) as total, MAX(created_at) as last_at')
            ->get()
            ->keyBy('user_id');

        // Which months each player was active in, over all time — needed to
        // tell a brand-new player from one coming back.
        $month = $this->monthSql('created_at');
        $activeIn = [];
        $firstActive = [];
        $pairs = DB::query()
            ->fromSub(
                $this->approved('deposits', $branchId)->selectRaw("user_id, {$month} as m")
                    ->unionAll($this->approved('withdrawals', $branchId)->selectRaw("user_id, {$month} as m")),
                'activity'
            )
            ->select('user_id', 'm')
            ->groupBy('user_id', 'm')
            ->get();
        foreach ($pairs as $pair) {
            $activeIn[$pair->m][$pair->user_id] = true;
            if (!isset($firstActive[$pair->user_id]) || $pair->m < $firstActive[$pair->user_id]) {
                $firstActive[$pair->user_id] = $pair->m;
            }
        }

        $windowStart = $firstMonth->copy()->subMonth();
        $depositMonths = $this->monthlyMoney('deposits', $branchId, $windowStart);
        $withdrawalMonths = $this->monthlyMoney('withdrawals', $branchId, $windowStart);

        $signups = [];
        foreach ($users as $user) {
            $key = substr((string) $user->created_at, 0, 7);
            $signups[$key] = ($signups[$key] ?? 0) + 1;
        }
        $firstDeposits = [];
        foreach ($deposits as $row) {
            $key = substr((string) $row->first_at, 0, 7);
            $firstDeposits[$key] = ($firstDeposits[$key] ?? 0) + 1;
        }

        $months = [];
        for ($i = 1; $i < count($labels); $i++) {
            $label = $labels[$i];
            $prev = $labels[$i - 1];
            $current = $activeIn[$label] ?? [];
            $before = $activeIn[$prev] ?? [];
            $active = count($current);
            $prevActive = count($before);
            $retained = count(array_intersect_key($current, $before));
            $newActive = 0;
            foreach ($current as $userId => $_) {
                if (($firstActive[$userId] ?? null) === $label) {
                    $newActive++;
                }
            }
            $dep = $depositMonths[$label] ?? null;
            $prevDep = $depositMonths[$prev] ?? null;
            $wd = $withdrawalMonths[$label] ?? null;
            $depositTotal = $dep ? $dep['total'] : 0.0;
            $prevDepositTotal = $prevDep ? $prevDep['total'] : 0.0;
            $withdrawalTotal = $wd ? $wd['total'] : 0.0;

            $months[] = [
                'month' => $label,
                'partial' => $label === $thisMonth->format('Y-m'),
                'signups' => $signups[$label] ?? 0,
                'signup_growth' => $this->growth($signups[$label] ?? 0, $signups[$prev] ?? 0),
                'first_deposits' => $firstDeposits[$label] ?? 0,
                'active' => $active,
                'active_growth' => $this->growth($active, $prevActive),
                'new_active' => $newActive,
                'retained' => $retained,
                'reactivated' => $active - $retained - $newActive,
                'churned' => $prevActive - $retained,
                'churn_rate' => $this->percent($prevActive - $retained, $prevActive),
                'retention_rate' => $this->percent($retained, $prevActive),
                'deposits_total' => $depositTotal,
                'deposits_count' => $dep ? $dep['count'] : 0,
                'depositors' => $dep ? $dep['payers'] : 0,
                'deposit_growth' => $this->growth($depositTotal, $prevDepositTotal),
                'withdrawals_total' => $withdrawalTotal,
                'withdrawals_count' => $wd ? $wd['count'] : 0,
                'net' => round($depositTotal - $withdrawalTotal, 2),
                'avg_per_depositor' => $dep && $dep['payers'] > 0 ? round($depositTotal / $dep['payers'], 2) : 0,
            ];
        }

        // Per player: last approved transaction and lifetime money.
        $players = [];
        foreach ($deposits as $userId => $row) {
            $players[$userId] = [
                'last_at' => (string) $row->last_at,
                'deposited' => (float) $row->total,
                'deposits' => (int) $row->n,
                'withdrawn' => 0.0,
            ];
        }
        foreach ($withdrawals as $userId => $row) {
            $player = $players[$userId] ?? ['last_at' => '', 'deposited' => 0.0, 'deposits' => 0, 'withdrawn' => 0.0];
            $player['withdrawn'] = (float) $row->total;
            $player['last_at'] = max($player['last_at'], (string) $row->last_at);
            $players[$userId] = $player;
        }

        $today = $now->copy()->startOfDay()->format('Y-m-d H:i:s');
        $weekAgo = $now->copy()->subDays(7)->format('Y-m-d H:i:s');
        $activeToday = $active7 = $active30 = $atRisk = $lost = $lost90 = 0;
        $lostIds = $riskIds = [];
        $ninetyAgo = $now->copy()->subDays(90)->format('Y-m-d H:i:s');
        foreach ($players as $userId => $player) {
            $last = $player['last_at'];
            if ($last >= $today) {
                $activeToday++;
            }
            if ($last >= $weekAgo) {
                $active7++;
            }
            if ($last >= $lostBefore) {
                $active30++;
                if ($last < $riskBefore) {
                    $atRisk++;
                    $riskIds[] = $userId;
                }
            } else {
                $lost++;
                $lostIds[] = $userId;
                if ($last < $ninetyAgo) {
                    $lost90++;
                }
            }
        }

        $dayAgo = $now->copy()->subDay()->format('Y-m-d H:i:s');
        $monthAgo = $now->copy()->subDays(30)->format('Y-m-d H:i:s');
        $seen24h = $seen7d = $banned = $agents = $recentSignups = $recentNoDeposit = 0;
        foreach ($users as $user) {
            $seen = (string) $user->last_seen_at;
            if ($seen !== '' && $seen >= $dayAgo) {
                $seen24h++;
            }
            if ($seen !== '' && $seen >= $weekAgo) {
                $seen7d++;
            }
            if ($user->status === 'banned') {
                $banned++;
            }
            if ($user->user_type === 'agent') {
                $agents++;
            }
            if ((string) $user->created_at >= $monthAgo) {
                $recentSignups++;
                if (!isset($deposits[$user->id])) {
                    $recentNoDeposit++;
                }
            }
        }

        $totalUsers = $users->count();
        $depositors = $deposits->count();
        $lifetimeDeposits = round((float) $deposits->sum('total'), 2);
        $lifetimeWithdrawals = round((float) $withdrawals->sum('total'), 2);
        $depositCount = (int) $deposits->sum('n');
        $lostValue = 0.0;
        foreach ($lostIds as $userId) {
            $lostValue += $players[$userId]['deposited'] - $players[$userId]['withdrawn'];
        }

        return [
            'generated_at' => $now->toIso8601String(),
            'branch_id' => $branchId,
            'rules' => [
                'lost_after_days' => self::LOST_AFTER_DAYS,
                'at_risk_after_days' => self::AT_RISK_AFTER_DAYS,
            ],
            'snapshot' => [
                'total_users' => $totalUsers,
                'banned_users' => $banned,
                'agents' => $agents,
                'depositors' => $depositors,
                'never_deposited' => max(0, $totalUsers - $depositors),
                'conversion_rate' => $this->percent($depositors, $totalUsers),
                'active_today' => $activeToday,
                'active_7d' => $active7,
                'active_30d' => $active30,
                'at_risk' => $atRisk,
                'lost' => $lost,
                'lost_90d' => $lost90,
                'lost_net_value' => round($lostValue, 2),
                'seen_24h' => $seen24h,
                'seen_7d' => $seen7d,
                'signups_30d' => $recentSignups,
                'signups_30d_no_deposit' => $recentNoDeposit,
                'lifetime_deposits' => $lifetimeDeposits,
                'lifetime_withdrawals' => $lifetimeWithdrawals,
                'lifetime_net' => round($lifetimeDeposits - $lifetimeWithdrawals, 2),
                'avg_deposit' => $depositCount > 0 ? round($lifetimeDeposits / $depositCount, 2) : 0,
                'avg_net_per_depositor' => $depositors > 0 ? round(($lifetimeDeposits - $lifetimeWithdrawals) / $depositors, 2) : 0,
            ],
            'month_to_date' => $this->monthToDate($branchId, $thisMonth, $now),
            'months' => $months,
            'cohorts' => $this->cohorts($users, $activeIn, $deposits, $thisMonth),
            'peak_hours' => $this->peakHours($branchId, $now),
            'lost_players' => $this->playerList($this->topByDeposit($lostIds, $players), $players, $now),
            'at_risk_players' => $this->playerList($this->topByDeposit($riskIds, $players), $players, $now),
            'top_players' => $this->topThisMonth($branchId, $thisMonth, $players, $now),
            'branches' => $branchId ? [] : $this->branchBreakdown($users, $players, $thisMonth, $lostBefore),
        ];
    }

    private function approved(string $table, ?int $branchId): Builder
    {
        return DB::table($table)
            ->where('status', 'approved')
            ->whereNotNull('user_id')
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId));
    }

    /** @return array<string, array{total: float, count: int, payers: int}> */
    private function monthlyMoney(string $table, ?int $branchId, Carbon $from): array
    {
        $month = $this->monthSql('created_at');

        return $this->approved($table, $branchId)
            ->where('created_at', '>=', $from)
            ->groupBy('m')
            ->selectRaw("{$month} as m, COUNT(*) as n, SUM(amount) as total, COUNT(DISTINCT user_id) as payers")
            ->get()
            ->mapWithKeys(fn ($row) => [$row->m => [
                'total' => round((float) $row->total, 2),
                'count' => (int) $row->n,
                'payers' => (int) $row->payers,
            ]])
            ->all();
    }

    /**
     * This month so far against the same number of days last month, so the
     * first week of a month does not read as a collapse.
     *
     * @return array<string, mixed>
     */
    private function monthToDate(?int $branchId, Carbon $thisMonth, Carbon $now): array
    {
        $lastMonth = $thisMonth->copy()->subMonth();
        $lastSameDay = $lastMonth->copy()->addSeconds($thisMonth->diffInSeconds($now));
        if ($lastSameDay >= $thisMonth) {
            $lastSameDay = $thisMonth->copy()->subSecond();
        }

        $current = $this->period($branchId, $thisMonth, $now);
        $previous = $this->period($branchId, $lastMonth, $lastSameDay);
        $growth = [];
        foreach ($current as $key => $value) {
            $growth[$key] = $this->growth($value, $previous[$key]);
        }

        return [
            'day' => (int) $now->day,
            'current' => $current,
            'previous' => $previous,
            'growth' => $growth,
        ];
    }

    /** @return array{deposits: float, withdrawals: float, net: float, active: int, signups: int, first_deposits: int} */
    private function period(?int $branchId, Carbon $from, Carbon $to): array
    {
        $deposits = (float) $this->approved('deposits', $branchId)->whereBetween('created_at', [$from, $to])->sum('amount');
        $withdrawals = (float) $this->approved('withdrawals', $branchId)->whereBetween('created_at', [$from, $to])->sum('amount');
        $active = (int) DB::query()
            ->fromSub(
                $this->approved('deposits', $branchId)->whereBetween('created_at', [$from, $to])->select('user_id')
                    ->union($this->approved('withdrawals', $branchId)->whereBetween('created_at', [$from, $to])->select('user_id')),
                'activity'
            )
            ->count();
        $signups = DB::table('users')
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->whereBetween('created_at', [$from, $to])
            ->count();
        $firstDeposits = DB::query()
            ->fromSub(
                $this->approved('deposits', $branchId)->groupBy('user_id')->selectRaw('user_id, MIN(created_at) as first_at'),
                'firsts'
            )
            ->whereBetween('first_at', [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')])
            ->count();

        return [
            'deposits' => round($deposits, 2),
            'withdrawals' => round($withdrawals, 2),
            'net' => round($deposits - $withdrawals, 2),
            'active' => $active,
            'signups' => $signups,
            'first_deposits' => $firstDeposits,
        ];
    }

    /**
     * Of the players who signed up in a month, the share active in that month
     * and in each month after it.
     *
     * @param  array<string, array<int|string, bool>>  $activeIn
     * @return list<array<string, mixed>>
     */
    private function cohorts($users, array $activeIn, $deposits, Carbon $thisMonth): array
    {
        $members = [];
        foreach ($users as $user) {
            $members[substr((string) $user->created_at, 0, 7)][] = $user->id;
        }

        $rows = [];
        for ($i = self::COHORTS - 1; $i >= 0; $i--) {
            $cohort = $thisMonth->copy()->subMonths($i);
            $ids = $members[$cohort->format('Y-m')] ?? [];
            $size = count($ids);
            $converted = 0;
            foreach ($ids as $id) {
                if (isset($deposits[$id])) {
                    $converted++;
                }
            }
            $retention = [];
            for ($k = 0; $k <= $i; $k++) {
                $label = $cohort->copy()->addMonths($k)->format('Y-m');
                $active = 0;
                foreach ($ids as $id) {
                    if (isset($activeIn[$label][$id])) {
                        $active++;
                    }
                }
                $retention[] = $this->percent($active, $size);
            }
            $rows[] = [
                'month' => $cohort->format('Y-m'),
                'size' => $size,
                'depositors' => $converted,
                'conversion_rate' => $this->percent($converted, $size),
                'retention' => $retention,
            ];
        }

        return $rows;
    }

    /** Deposit requests per hour of day over the last 30 days — when the desk is busiest. */
    private function peakHours(?int $branchId, Carbon $now): array
    {
        $hour = DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%H', created_at) AS INTEGER)"
            : 'HOUR(created_at)';

        $counts = DB::table('deposits')
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->where('created_at', '>=', $now->copy()->subDays(30))
            ->groupBy('h')
            ->selectRaw("{$hour} as h, COUNT(*) as n")
            ->pluck('n', 'h');

        $hours = [];
        for ($h = 0; $h < 24; $h++) {
            $hours[] = (int) ($counts[$h] ?? 0);
        }

        return $hours;
    }

    /**
     * @param  list<int|string>  $ids
     * @param  array<int|string, array<string, mixed>>  $players
     * @return list<int|string>
     */
    private function topByDeposit(array $ids, array $players): array
    {
        usort($ids, fn ($a, $b) => $players[$b]['deposited'] <=> $players[$a]['deposited']);

        return array_slice($ids, 0, self::LIST_SIZE);
    }

    /**
     * @param  list<int|string>  $ids
     * @param  array<int|string, array<string, mixed>>  $players
     * @return list<array<string, mixed>>
     */
    private function playerList(array $ids, array $players, Carbon $now, array $extra = []): array
    {
        if (!$ids) {
            return [];
        }

        $details = DB::table('users')
            ->leftJoin('branches', 'branches.id', '=', 'users.branch_id')
            ->whereIn('users.id', $ids)
            ->get(['users.id', 'users.name', 'users.play_id', 'users.phone', 'users.status', 'branches.name as branch_name'])
            ->keyBy('id');

        $rows = [];
        foreach ($ids as $id) {
            $user = $details[$id] ?? null;
            $player = $players[$id] ?? ['last_at' => '', 'deposited' => 0.0, 'deposits' => 0, 'withdrawn' => 0.0];
            $last = $player['last_at'] !== '' ? Carbon::parse($player['last_at']) : null;
            $rows[] = array_merge([
                'id' => (int) $id,
                'name' => $user->name ?? null,
                'play_id' => $user->play_id ?? null,
                'phone' => $user->phone ?? null,
                'status' => $user->status ?? null,
                'branch' => $user->branch_name ?? null,
                'last_activity_at' => $last?->toIso8601String(),
                'days_inactive' => $last ? (int) $last->copy()->startOfDay()->diffInDays($now->copy()->startOfDay()) : null,
                'lifetime_deposits' => round($player['deposited'], 2),
                'lifetime_withdrawals' => round($player['withdrawn'], 2),
                'lifetime_net' => round($player['deposited'] - $player['withdrawn'], 2),
                'deposits_count' => $player['deposits'],
            ], $extra[$id] ?? []);
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function topThisMonth(?int $branchId, Carbon $thisMonth, array $players, Carbon $now): array
    {
        $rows = $this->approved('deposits', $branchId)
            ->where('created_at', '>=', $thisMonth)
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(amount) as total, COUNT(*) as n')
            ->orderByDesc('total')
            ->limit(self::LIST_SIZE)
            ->get();

        $extra = [];
        foreach ($rows as $row) {
            $extra[$row->user_id] = [
                'month_deposits' => round((float) $row->total, 2),
                'month_deposits_count' => (int) $row->n,
            ];
        }

        return $this->playerList($rows->pluck('user_id')->all(), $players, $now, $extra);
    }

    /** @return list<array<string, mixed>> */
    private function branchBreakdown($users, array $players, Carbon $thisMonth, string $lostBefore): array
    {
        $branches = DB::table('branches')->orderBy('id')->get(['id', 'name', 'code', 'is_active']);
        if ($branches->count() < 2) {
            return [];
        }

        $stats = [];
        $monthStart = $thisMonth->format('Y-m-d H:i:s');
        foreach ($users as $user) {
            $id = (int) $user->branch_id;
            $stats[$id] ??= ['users' => 0, 'signups_month' => 0, 'active_30d' => 0, 'lost' => 0];
            $stats[$id]['users']++;
            if ((string) $user->created_at >= $monthStart) {
                $stats[$id]['signups_month']++;
            }
            $player = $players[$user->id] ?? null;
            if ($player) {
                $player['last_at'] >= $lostBefore ? $stats[$id]['active_30d']++ : $stats[$id]['lost']++;
            }
        }

        $money = fn (string $table) => DB::table($table)
            ->where('status', 'approved')
            ->where('created_at', '>=', $thisMonth)
            ->groupBy('branch_id')
            ->selectRaw('branch_id, SUM(amount) as total')
            ->pluck('total', 'branch_id');
        $deposits = $money('deposits');
        $withdrawals = $money('withdrawals');

        return $branches->map(function ($branch) use ($stats, $deposits, $withdrawals) {
            $row = $stats[$branch->id] ?? ['users' => 0, 'signups_month' => 0, 'active_30d' => 0, 'lost' => 0];
            $dep = round((float) ($deposits[$branch->id] ?? 0), 2);
            $wd = round((float) ($withdrawals[$branch->id] ?? 0), 2);

            return [
                'id' => (int) $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
                'is_active' => (bool) $branch->is_active,
                'users' => $row['users'],
                'signups_month' => $row['signups_month'],
                'active_30d' => $row['active_30d'],
                'lost' => $row['lost'],
                'deposits_month' => $dep,
                'withdrawals_month' => $wd,
                'net_month' => round($dep - $wd, 2),
            ];
        })->values()->all();
    }

    private function monthSql(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', {$column})"
            : "DATE_FORMAT({$column}, '%Y-%m')";
    }

    /** Change against the previous value in percent; null when there is nothing to compare with. */
    private function growth(float|int $current, float|int $previous): ?float
    {
        return $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null;
    }

    private function percent(float|int $part, float|int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }
}
