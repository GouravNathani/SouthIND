<?php

namespace App\Http\Controllers\Super;

use App\Support\Query\DateBucket;
use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\WalletMonthlyPayout;
use App\Support\Wallet\WalletService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Developer payout / billing for the Super Admin panel.
 *
 * The bill is a percentage (config payout.developer_percent, default 0.001%)
 * of APPROVED deposits. Super Admin sees all branches by default; an optional
 * ?branch_id filters to one branch.
 */
class PayoutController extends Controller
{
    public function __construct(private readonly WalletService $wallet)
    {
    }

    /**
     * Payout percent comes from the wallet's monthly_payout_percent (read from
     * the BookFlow API), falling back to the config default if unavailable.
     */
    private function payoutPercent(): float
    {
        return $this->wallet->payoutPercent();
    }

    /**
     * Month-wise payout overview for a given year (all 12 months).
     */
    public function monthly(Request $request): JsonResponse
    {
        $percent = $this->payoutPercent();
        [$tz, $dbTz] = $this->timezones();

        $nowApp = now($tz);
        $currentYear = (int) $nowApp->year;
        $currentMonth = (int) $nowApp->month;

        $year = (int) $request->query('year', $currentYear);
        if ($year < 2000 || $year > 2100) {
            $year = $currentYear;
        }
        $branchId = $request->query('branch_id');

        // Billing "data start": earliest approved deposit (global / branch-scoped).
        $earliestRaw = $this->approvedDepositQuery($branchId)
            ->selectRaw('MIN(COALESCE(approved_at, created_at)) as m')
            ->value('m');
        $earliest = $earliestRaw ? Carbon::parse($earliestRaw, $dbTz)->setTimezone($tz) : null;

        // Monthly Payout billing began in config('payout.start_month'). Hide any
        // month before it: floor the data-start to that month so older approved
        // deposits never surface in the payout views.
        $floor = $this->payoutStartFloor($tz);
        if ($floor && (!$earliest || $earliest->lt($floor))) {
            $earliest = $floor;
        }

        // Half-open interval [yearStart, nextYearStart) avoids 23:59:59 edge gaps.
        $start = Carbon::create($year, 1, 1, 0, 0, 0, $tz)->setTimezone($dbTz);
        $nextStart = Carbon::create($year + 1, 1, 1, 0, 0, 0, $tz)->setTimezone($dbTz);

        $rows = $this->approvedDepositQuery($branchId)
            ->whereRaw('COALESCE(approved_at, created_at) >= ? AND COALESCE(approved_at, created_at) < ?', [
                $start->toDateTimeString(),
                $nextStart->toDateTimeString(),
            ])
            ->selectRaw(DateBucket::month('COALESCE(approved_at, created_at)') . " as ym")
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->selectRaw('COUNT(*) as cnt')
            ->groupBy('ym')
            ->get()
            ->keyBy('ym');

        // Visible months: from the data-start month through the current month.
        // No future months, and no empty past months before data began.
        $endMonth = $year < $currentYear ? 12 : ($year > $currentYear ? 0 : $currentMonth);
        if ($earliest) {
            $earliestYear = (int) $earliest->year;
            $earliestMonthNum = (int) $earliest->month;
            $startMonth = $year < $earliestYear
                ? 13
                : ($year > $earliestYear ? 1 : $earliestMonthNum);
        } else {
            // No approved deposits yet: show only the current month (current year).
            $startMonth = $year === $currentYear ? $currentMonth : 13;
        }

        // What the nightly wallet:monthly-payout deducted for each month. Bills are
        // network-wide, so a single-branch view shows none.
        $deductions = !$branchId && Schema::hasTable('wallet_monthly_payouts')
            ? WalletMonthlyPayout::query()->where('month', 'like', sprintf('%04d-%%', $year))->get()->keyBy('month')
            : collect();

        $data = [];
        $yearTotal = 0.0;
        $yearCount = 0;
        $yearPayout = 0.0;
        for ($m = $startMonth; $m <= $endMonth; $m++) {
            $ym = sprintf('%04d-%02d', $year, $m);
            $row = $rows->get($ym);
            $total = (float) ($row->total ?? 0);
            $count = (int) ($row->cnt ?? 0);
            $rowPayout = $this->payout($total, $percent);
            $yearTotal += $total;
            $yearCount += $count;
            $yearPayout += $rowPayout;

            $data[] = [
                'month' => $ym,
                'label' => Carbon::create($year, $m, 1)->format('M Y'),
                'approved_total' => round($total, 2),
                'deposit_count' => $count,
                'payout' => $rowPayout,
                'deduction' => ($deduction = $deductions->get($ym)) ? [
                    'status' => $deduction->status,
                    'coins' => (float) $deduction->coins,
                    'settled_at' => $deduction->settled_at?->toIso8601String(),
                ] : null,
            ];
        }

        return response()->json([
            'data' => $data,
            // Summary payout = sum of the per-row payouts so the table footer
            // always matches the rows (avoids round(sum) vs sum(round) drift).
            'summary' => [
                'year' => $year,
                'approved_total' => round($yearTotal, 2),
                'deposit_count' => $yearCount,
                'payout' => round($yearPayout, 2),
                'percent' => $percent,
            ],
            'earliest_month' => $earliest ? $earliest->format('Y-m') : null,
            'branch_id' => $branchId ? (int) $branchId : null,
        ]);
    }

    /**
     * Day-wise payout breakdown for a given month (YYYY-MM).
     */
    public function daily(Request $request): JsonResponse
    {
        $percent = $this->payoutPercent();
        $month = (string) $request->query('month', now()->format('Y-m'));
        // Strict YYYY-MM with a valid 01-12 month (regex alone allows 13/00).
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $month = now()->format('Y-m');
        }
        $branchId = $request->query('branch_id');

        [$tz, $dbTz] = $this->timezones();

        // Never serve a month before Monthly Payout billing began.
        $floor = $this->payoutStartFloor($tz);
        if ($floor && $month < $floor->format('Y-m')) {
            $month = $floor->format('Y-m');
        }

        $year = (int) substr($month, 0, 4);
        $monthNum = (int) substr($month, 5, 2);
        $monthStart = Carbon::create($year, $monthNum, 1, 0, 0, 0, $tz);
        // Half-open interval [monthStart, nextMonthStart) in app tz.
        $start = $monthStart->copy()->setTimezone($dbTz);
        $nextStart = $monthStart->copy()->addMonthNoOverflow()->setTimezone($dbTz);

        $rows = $this->approvedDepositQuery($branchId)
            ->whereRaw('COALESCE(approved_at, created_at) >= ? AND COALESCE(approved_at, created_at) < ?', [
                $start->toDateTimeString(),
                $nextStart->toDateTimeString(),
            ])
            ->selectRaw(DateBucket::day('COALESCE(approved_at, created_at)') . ' as day')
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->selectRaw('COUNT(*) as cnt')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $data = [];
        $monthTotal = 0.0;
        $monthCount = 0;
        $monthPayout = 0.0;
        foreach ($rows as $row) {
            $total = (float) $row->total;
            $count = (int) $row->cnt;
            $rowPayout = $this->payout($total, $percent);
            $monthTotal += $total;
            $monthCount += $count;
            $monthPayout += $rowPayout;

            $data[] = [
                'date' => (string) $row->day,
                'approved_total' => round($total, 2),
                'deposit_count' => $count,
                'payout' => $rowPayout,
            ];
        }

        return response()->json([
            'data' => $data,
            // Summary payout = sum of per-day payouts so the footer matches rows.
            'summary' => [
                'month' => $month,
                'label' => $monthStart->format('F Y'),
                'approved_total' => round($monthTotal, 2),
                'deposit_count' => $monthCount,
                'payout' => round($monthPayout, 2),
                'percent' => $percent,
            ],
            'branch_id' => $branchId ? (int) $branchId : null,
        ]);
    }

    /**
     * Base query: approved deposits, optionally scoped to a branch.
     */
    private function approvedDepositQuery($branchId)
    {
        return Deposit::query()
            ->where('status', Deposit::STATUS_APPROVED)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId));
    }

    private function payout(float $approvedTotal, float $percent): float
    {
        return round($approvedTotal * $percent / 100, 2);
    }

    /**
     * First day of the configured Monthly Payout start month, or null when no
     * floor is configured (config('payout.start_month') empty).
     */
    private function payoutStartFloor(string $tz): ?Carbon
    {
        $start = (string) config('payout.start_month', '');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $start)) {
            return null;
        }

        return Carbon::create(
            (int) substr($start, 0, 4),
            (int) substr($start, 5, 2),
            1,
            0,
            0,
            0,
            $tz,
        );
    }

    /**
     * @return array{0: string, 1: string} [appTimezone, dbTimezone]
     */
    private function timezones(): array
    {
        $tz = config('app.timezone');
        $connection = config('database.default');
        $dbTz = config('database.connections.' . $connection . '.timezone') ?: $tz;

        return [$tz, $dbTz];
    }
}
