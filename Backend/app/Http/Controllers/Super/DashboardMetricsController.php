<?php

namespace App\Http\Controllers\Super;

use App\Support\Query\DateBucket;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class DashboardMetricsController extends Controller
{
    public function userActivity(Request $request): JsonResponse
    {
        $mode = (string) $request->query('mode', '30days');
        $mode = $mode === 'months' ? 'months' : '30days';
        $branchId = $request->query('branch_id');

        if ($mode === 'months') {
            $start = now()->startOfMonth()->subMonths(11);
            $end = now()->endOfMonth();
            $labels = [];
            $cursor = $start->copy();
            while ($cursor <= $end) {
                $labels[] = $cursor->format('Y-m');
                $cursor->addMonth();
            }

            $newUsers = DB::table('users')
                ->selectRaw(DateBucket::month('created_at') . ' as bucket, COUNT(*) as total')
                ->whereBetween('created_at', [$start, $end])
                ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                ->groupBy('bucket')
                ->pluck('total', 'bucket');

            $activeUsers = DB::query()
                ->fromSub(
                    DB::table('deposits')
                        ->selectRaw(DateBucket::month('created_at') . ' as bucket, user_id')
                        ->whereNotNull('user_id')
                        ->whereBetween('created_at', [$start, $end])
                        ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                        ->unionAll(
                            DB::table('withdrawals')
                                ->selectRaw(DateBucket::month('created_at') . ' as bucket, user_id')
                                ->whereNotNull('user_id')
                                ->whereBetween('created_at', [$start, $end])
                                ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                        ),
                    'activity'
                )
                ->selectRaw('bucket, COUNT(DISTINCT user_id) as total')
                ->groupBy('bucket')
                ->pluck('total', 'bucket');

            $data = array_map(function (string $label) use ($newUsers, $activeUsers) {
                return [
                    'label' => $label,
                    'new_users' => (int) ($newUsers[$label] ?? 0),
                    'active_users' => (int) ($activeUsers[$label] ?? 0),
                ];
            }, $labels);

            return response()->json(['data' => $data], Response::HTTP_OK);
        }

        $start = now()->startOfDay()->subDays(29);
        $end = now()->endOfDay();
        $labels = [];
        $cursor = $start->copy();
        while ($cursor <= $end) {
            $labels[] = $cursor->format('Y-m-d');
            $cursor->addDay();
        }

        $newUsers = DB::table('users')
            ->selectRaw(DateBucket::day('created_at') . ' as bucket, COUNT(*) as total')
            ->whereBetween('created_at', [$start, $end])
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $activeUsers = DB::query()
            ->fromSub(
                DB::table('deposits')
                    ->selectRaw(DateBucket::day('created_at') . ' as bucket, user_id')
                    ->whereNotNull('user_id')
                    ->whereBetween('created_at', [$start, $end])
                    ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                    ->unionAll(
                        DB::table('withdrawals')
                            ->selectRaw(DateBucket::day('created_at') . ' as bucket, user_id')
                            ->whereNotNull('user_id')
                            ->whereBetween('created_at', [$start, $end])
                            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                    ),
                'activity'
            )
            ->selectRaw('bucket, COUNT(DISTINCT user_id) as total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $data = array_map(function (string $label) use ($newUsers, $activeUsers) {
            return [
                'label' => $label,
                'new_users' => (int) ($newUsers[$label] ?? 0),
                'active_users' => (int) ($activeUsers[$label] ?? 0),
            ];
        }, $labels);

        return response()->json(['data' => $data], Response::HTTP_OK);
    }

    public function userAverages(Request $request): JsonResponse
    {
        $branchId = $request->query('branch_id');

        $timezone = config('app.timezone');
        $connection = config('database.default');
        $dbTimezone = config('database.connections.' . $connection . '.timezone') ?: $timezone;

        $startDate = trim((string) $request->query('start_date', ''));
        $endDate = trim((string) $request->query('end_date', ''));

        $start = null;
        $end = null;

        if ($startDate !== '' && $endDate !== '') {
            try {
                $start = Carbon::parse($startDate, $timezone)->startOfDay()->setTimezone($dbTimezone);
                $end = Carbon::parse($endDate, $timezone)->endOfDay()->setTimezone($dbTimezone);
            } catch (\Throwable) {
                $start = null;
                $end = null;
            }
        }

        if (!$start || !$end) {
            $minUser = DB::table('users')->min('created_at');
            $minDeposit = DB::table('deposits')->min('created_at');
            $minWithdrawal = DB::table('withdrawals')->min('created_at');
            $minDates = array_filter([$minUser, $minDeposit, $minWithdrawal]);
            if (!$minDates) {
                return response()->json([
                    'data' => [
                        'avg_new_users' => 0,
                        'avg_active_users' => 0,
                    ],
                ], Response::HTTP_OK);
            }
            $start = Carbon::parse(min($minDates), $dbTimezone)->startOfDay();
            $end = now($dbTimezone)->endOfDay();
        }

        $labels = [];
        $cursor = $start->copy();
        while ($cursor <= $end) {
            $labels[] = $cursor->format('Y-m-d');
            $cursor->addDay();
        }

        $newUsers = DB::table('users')
            ->selectRaw(DateBucket::day('created_at') . ' as bucket, COUNT(*) as total')
            ->whereBetween('created_at', [$start, $end])
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $activeUsers = DB::query()
            ->fromSub(
                DB::table('deposits')
                    ->selectRaw(DateBucket::day('created_at') . ' as bucket, user_id')
                    ->whereNotNull('user_id')
                    ->whereBetween('created_at', [$start, $end])
                    ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                    ->unionAll(
                        DB::table('withdrawals')
                            ->selectRaw(DateBucket::day('created_at') . ' as bucket, user_id')
                            ->whereNotNull('user_id')
                            ->whereBetween('created_at', [$start, $end])
                            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                    ),
                'activity'
            )
            ->selectRaw('bucket, COUNT(DISTINCT user_id) as total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $daysCount = max(1, count($labels));
        $totalNew = 0;
        $totalActive = 0;
        foreach ($labels as $label) {
            $totalNew += (int) ($newUsers[$label] ?? 0);
            $totalActive += (int) ($activeUsers[$label] ?? 0);
        }

        return response()->json([
            'data' => [
                'total_new_users' => $totalNew,
                'total_active_users' => $totalActive,
                'days' => $daysCount,
                'avg_new_users' => round($totalNew / $daysCount, 2),
                'avg_active_users' => round($totalActive / $daysCount, 2),
            ],
        ], Response::HTTP_OK);
    }
}
