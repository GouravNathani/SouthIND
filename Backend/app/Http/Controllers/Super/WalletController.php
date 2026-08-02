<?php

namespace App\Http\Controllers\Super;

use App\Support\Query\DateBucket;
use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\WalletCharge;
use App\Models\WalletDailyPayout;
use App\Support\Wallet\WalletService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Wallet overview for the Super Admin panel (shown on the MonthWise Payout page).
 * Reads the live balance/rates/costs from the BookFlow wallet API (cached) and
 * augments them with this month's local usage + any owed (unsettled) coins.
 */
class WalletController extends Controller
{
    public function __construct(private readonly WalletService $wallet)
    {
    }

    public function show(): JsonResponse
    {
        $snapshot = $this->wallet->snapshot();

        $monthStart = Carbon::now(config('app.timezone'))->startOfMonth();

        $rows = WalletCharge::query()
            ->where('created_at', '>=', $monthStart)
            // Deleted messages' charges are voided — they no longer count as usage.
            ->where('status', '!=', WalletCharge::STATUS_VOIDED)
            ->selectRaw('channel, direction, COUNT(*) as cnt, COALESCE(SUM(coins),0) as coins')
            ->groupBy('channel', 'direction')
            ->get();

        $usage = [
            'month' => $monthStart->format('Y-m'),
            'support_in' => ['count' => 0, 'coins' => 0],
            'support_out' => ['count' => 0, 'coins' => 0],
            'media_in' => ['count' => 0, 'coins' => 0],
            'media_out' => ['count' => 0, 'coins' => 0],
            'whatsapp_in' => ['count' => 0, 'coins' => 0],
            'whatsapp_out' => ['count' => 0, 'coins' => 0],
            'total_coins' => 0,
            'total_messages' => 0,
        ];

        foreach ($rows as $row) {
            $key = "{$row->channel}_{$row->direction}";
            if (isset($usage[$key])) {
                $usage[$key] = ['count' => (int) $row->cnt, 'coins' => (float) $row->coins];
            }
            $usage['total_coins'] += (float) $row->coins;
            $usage['total_messages'] += (int) $row->cnt;
        }

        $recent = WalletCharge::query()
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (WalletCharge $c) => [
                'id' => $c->id,
                'channel' => $c->channel,
                'direction' => $c->direction,
                'cost_value' => (float) $c->cost_value,
                'coins' => (float) $c->coins,
                'status' => $c->status,
                'error_code' => $c->error_code,
                'created_at' => $c->created_at,
            ]);

        return response()->json([
            'data' => [
                'wallet' => $snapshot, // balance, name, number, status, monthly_payout_percent, pending_payout, costs, stale, self, rate_source
                // Kept for older panel builds: a snapshot is now always present
                // (self wallet), so this only reports whether Control answered.
                'available' => ($snapshot['rate_source'] ?? null) !== WalletCharge::SOURCE_SELF,
                'rate_source' => $this->wallet->rateSource(),
                'is_self' => $this->wallet->isSelf(),
                'local_balance' => $this->wallet->localBalance(),
                'usage' => $usage,
                // Days whose single 3 AM payout failed — real money still due.
                'owed_coins' => $this->wallet->owedCoins(),
                // Same, split per calendar month: this is what Control clears.
                'owed_by_month' => $this->wallet->owedByMonth(),
                // Booked today, will be billed by tonight's payout. Not owed.
                'pending_today_coins' => $this->wallet->pendingTodayCoins(),
                'recent_charges' => $recent,
                'recent_payouts' => WalletDailyPayout::query()
                    ->orderByDesc('payout_date')
                    ->limit(14)
                    ->get()
                    ->map(fn (WalletDailyPayout $p) => [
                        'date' => $p->payout_date->toDateString(),
                        'coins' => (float) $p->coins,
                        'charge_count' => $p->charge_count,
                        'status' => $p->status,
                        'error_code' => $p->error_code,
                        'attempts' => $p->attempts,
                        'settled_at' => $p->settled_at,
                    ]),
            ],
        ]);
    }

    /**
     * Day-wise deduction ledger for a month (YYYY-MM).
     *
     * Answers "kahan kahan deduct hua": every day's message charges split by
     * channel/direction, plus that day's developer payout, plus how much of it
     * is still owed because Control could not be reached. This is the record
     * that survives a wallet outage — charges are logged locally either way.
     */
    public function daily(Request $request): JsonResponse
    {
        $tz = config('app.timezone');

        $month = (string) $request->query('month', now($tz)->format('Y-m'));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $month = now($tz)->format('Y-m');
        }

        $monthStart = Carbon::createFromFormat('Y-m-d H:i:s', "{$month}-01 00:00:00", $tz);
        $nextStart = $monthStart->copy()->addMonthNoOverflow();

        $days = [];
        $dayFor = function (string $date) use (&$days): string {
            if (!isset($days[$date])) {
                $days[$date] = [
                    'date' => $date,
                    'payout' => 0.0,
                    'approved_total' => 0.0,
                    'deposit_count' => 0,
                    'support_in' => ['count' => 0, 'coins' => 0.0],
                    'support_out' => ['count' => 0, 'coins' => 0.0],
                    'media_in' => ['count' => 0, 'coins' => 0.0],
                    'media_out' => ['count' => 0, 'coins' => 0.0],
                    'whatsapp_in' => ['count' => 0, 'coins' => 0.0],
                    'whatsapp_out' => ['count' => 0, 'coins' => 0.0],
                    'messages_coins' => 0.0,
                    'messages_count' => 0,
                    // Charges booked but not yet paid for — either today's (the
                    // 3 AM payout hasn't run) or a day whose payout failed.
                    'unbilled_coins' => 0.0,
                    'unbilled_count' => 0,
                    // Set from that day's single payout row (see below).
                    'payout_status' => null,
                    'payout_error' => null,
                    'billed_coins' => 0.0,
                    'owed_coins' => 0.0,
                    // How many of the day's charges were priced from config
                    // because Control was unreachable.
                    'self_priced_count' => 0,
                    'total' => 0.0,
                ];
            }

            return $date;
        };

        // ── Message charges, grouped per day + channel/direction ──────────────
        $charges = WalletCharge::query()
            ->where('created_at', '>=', $monthStart)
            ->where('created_at', '<', $nextStart)
            // Deleted messages' charges are voided — they are not usage.
            ->where('status', '!=', WalletCharge::STATUS_VOIDED)
            ->selectRaw(DateBucket::day('created_at') . ' as day, channel, direction, status, rate_source')
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(coins), 0) as coins')
            ->groupBy('day', 'channel', 'direction', 'status', 'rate_source')
            ->get();

        foreach ($charges as $row) {
            $date = $dayFor((string) $row->day);
            $key = "{$row->channel}_{$row->direction}";
            $count = (int) $row->cnt;
            $coins = (float) $row->coins;

            if (isset($days[$date][$key])) {
                $days[$date][$key]['count'] += $count;
                $days[$date][$key]['coins'] = round($days[$date][$key]['coins'] + $coins, 2);
            }

            $days[$date]['messages_count'] += $count;
            $days[$date]['messages_coins'] = round($days[$date]['messages_coins'] + $coins, 2);

            if ($row->status === WalletCharge::STATUS_UNSETTLED) {
                $days[$date]['unbilled_count'] += $count;
                $days[$date]['unbilled_coins'] = round($days[$date]['unbilled_coins'] + $coins, 2);
            }

            if ($row->rate_source === WalletCharge::SOURCE_SELF) {
                $days[$date]['self_priced_count'] += $count;
            }
        }

        // ── The one payout per day that actually billed those charges ─────────
        $payouts = WalletDailyPayout::query()
            ->where('payout_date', '>=', $monthStart->toDateString())
            ->where('payout_date', '<', $nextStart->toDateString())
            ->get();

        foreach ($payouts as $payout) {
            $date = $dayFor($payout->payout_date->toDateString());

            $days[$date]['payout_status'] = $payout->status;
            $days[$date]['payout_error'] = $payout->error_code;
            $days[$date]['billed_coins'] = (float) $payout->coins;
            // Only a failed payout is money we still owe Control.
            $days[$date]['owed_coins'] = $payout->isOwed() ? (float) $payout->coins : 0.0;
        }

        // ── Developer payout, from the same approved deposits the payout page
        //    bills on. Payout is invoiced monthly, not deducted per message, so
        //    it is shown per day but never counted as owed coins.
        $percent = $this->wallet->payoutPercent();
        $dbTz = config('database.connections.' . config('database.default') . '.timezone') ?: $tz;

        $deposits = Deposit::query()
            ->where('status', Deposit::STATUS_APPROVED)
            ->whereRaw('COALESCE(approved_at, created_at) >= ? AND COALESCE(approved_at, created_at) < ?', [
                $monthStart->copy()->setTimezone($dbTz)->toDateTimeString(),
                $nextStart->copy()->setTimezone($dbTz)->toDateTimeString(),
            ])
            ->selectRaw(DateBucket::day('COALESCE(approved_at, created_at)') . ' as day')
            ->selectRaw('COALESCE(SUM(amount), 0) as total, COUNT(*) as cnt')
            ->groupBy('day')
            ->get();

        foreach ($deposits as $row) {
            $date = $dayFor((string) $row->day);
            $total = (float) $row->total;
            $days[$date]['approved_total'] = round($total, 2);
            $days[$date]['deposit_count'] = (int) $row->cnt;
            $days[$date]['payout'] = round($total * $percent / 100, 2);
        }

        // Newest day first — the day an operator cares about is today.
        krsort($days);

        $summary = [
            'month' => $month,
            'label' => $monthStart->format('F Y'),
            'percent' => $percent,
            'payout' => 0.0,
            'messages_coins' => 0.0,
            'messages_count' => 0,
            'owed_coins' => 0.0,
            'unbilled_coins' => 0.0,
            'self_priced_count' => 0,
            'total' => 0.0,
        ];

        $data = [];
        foreach ($days as $day) {
            $day['total'] = round($day['payout'] + $day['messages_coins'], 2);

            $summary['payout'] = round($summary['payout'] + $day['payout'], 2);
            $summary['messages_coins'] = round($summary['messages_coins'] + $day['messages_coins'], 2);
            $summary['messages_count'] += $day['messages_count'];
            $summary['owed_coins'] = round($summary['owed_coins'] + $day['owed_coins'], 2);
            $summary['unbilled_coins'] = round($summary['unbilled_coins'] + $day['unbilled_coins'], 2);
            $summary['self_priced_count'] += $day['self_priced_count'];
            $summary['total'] = round($summary['total'] + $day['total'], 2);

            $data[] = $day;
        }

        return response()->json([
            'data' => $data,
            'summary' => $summary,
            'rate_source' => $this->wallet->rateSource(),
            'is_self' => $this->wallet->isSelf(),
        ]);
    }

    /**
     * Run the daily payout right now instead of waiting for 03:00 — bills any
     * unpaid completed day and retries everything still owed.
     */
    public function settle(): JsonResponse
    {
        $result = $this->wallet->retryUnsettled();

        // Keep Control's owed figure in step with what just happened.
        $this->wallet->reportOwed();

        return response()->json([
            'data' => [
                'attempted' => $result['attempted'],
                'settled' => $result['settled'],
                'owed_coins' => $this->wallet->owedCoins(),
                'owed_by_month' => $this->wallet->owedByMonth(),
            ],
        ]);
    }
}
