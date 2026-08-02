<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDepositStatusRequest;
use App\Http\Resources\Super\DepositResource;
use App\Models\Deposit;
use App\Support\Bonus\BonusCodeEnforcer;
use App\Support\Cache\DepositCache;
use App\Support\Push\UserPushNotifier;
use App\Support\Query\DurationAggregate;
use App\Support\Referral\CommissionAccrual;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class DepositController extends Controller
{
    public function index(Request $request)
    {
        $query = Deposit::query()->with(['user', 'account', 'approver', 'bonusRedemption']);

        if ($branchId = $request->query('branch_id')) {
            $query->where('branch_id', $branchId);
        }

        if ($status = $request->query('status')) {
            if (in_array($status, [Deposit::STATUS_PENDING, Deposit::STATUS_ON_PROCESS, Deposit::STATUS_APPROVED, Deposit::STATUS_REJECTED, Deposit::STATUS_FAILED], true)) {
                $query->where('status', $status);
            }
        }

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($playId = $request->query('play_id')) {
            $query->where('play_id', $playId);
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $pattern = '%' . $search . '%';
                $query->where('play_id', 'like', $pattern)
                    ->orWhere('status', 'like', $pattern)
                    ->orWhere('amount', 'like', $pattern)
                    ->orWhere('utr_number', 'like', $pattern)
                    ->orWhere('notes', 'like', $pattern)
                    ->orWhereHas('user', function ($query) use ($pattern) {
                        $query->where('name', 'like', $pattern)
                            ->orWhere('phone', 'like', $pattern)
                            ->orWhere('unique_number', 'like', $pattern)
                            ->orWhere('play_id', 'like', $pattern);
                    })
                    ->orWhereHas('account', function ($query) use ($pattern) {
                        $query->where('name', 'like', $pattern)
                            ->orWhere('account_number', 'like', $pattern)
                            ->orWhere('ifsc_code', 'like', $pattern)
                            ->orWhere('upi_id', 'like', $pattern);
                    });
                if (ctype_digit($search)) {
                    $query->orWhere('id', (int) $search);
                }
            });
        }

        $startDate = trim((string) $request->query('start_date', ''));
        $endDate = trim((string) $request->query('end_date', ''));
        if ($startDate !== '' && $endDate !== '') {
            try {
                $timezone = config('app.timezone');
                $connection = config('database.default');
                $dbTimezone = config('database.connections.' . $connection . '.timezone') ?: $timezone;
                $start = Carbon::parse($startDate, $timezone)->startOfDay()->setTimezone($dbTimezone);
                $end = Carbon::parse($endDate, $timezone)->endOfDay()->setTimezone($dbTimezone);
                $query->whereBetween('created_at', [$start, $end]);
            } catch (\Throwable) {
                // ignore invalid range
            }
        }

        $summaryRow = (clone $query)
            ->toBase()
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) as approved_total',
                [Deposit::STATUS_APPROVED],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status IN (?, ?) THEN amount ELSE 0 END), 0) as rejected_total',
                [Deposit::STATUS_REJECTED, Deposit::STATUS_FAILED],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as approved_count',
                [Deposit::STATUS_APPROVED],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END), 0) as rejected_count',
                [Deposit::STATUS_REJECTED, Deposit::STATUS_FAILED],
            )
            ->selectRaw('COUNT(*) as count')
            ->first();
        $avgProcessingRow = (clone $query)
            ->where('status', Deposit::STATUS_APPROVED)
            ->whereNotNull('approved_at')
            ->toBase()
            ->selectRaw(DurationAggregate::avgSecondsExpression('created_at', 'approved_at') . ' as avg_processing_seconds')
            ->first();
        $summary = [
            'total' => (float) ($summaryRow->total ?? 0),
            'approvedTotal' => (float) ($summaryRow->approved_total ?? 0),
            'rejectedTotal' => (float) ($summaryRow->rejected_total ?? 0),
            'approvedCount' => (int) ($summaryRow->approved_count ?? 0),
            'rejectedCount' => (int) ($summaryRow->rejected_count ?? 0),
            'count' => (int) ($summaryRow->count ?? 0),
            'avgProcessingSeconds' => (float) ($avgProcessingRow->avg_processing_seconds ?? 0),
        ];

        $deposits = $query->latest()->get();

        return DepositResource::collection($deposits)->additional([
            'summary' => $summary,
        ]);
    }

    public function show(Deposit $deposit)
    {
        return new DepositResource($deposit->load(['user', 'account', 'approver', 'bonusRedemption']));
    }

    public function updateStatus(UpdateDepositStatusRequest $request, Deposit $deposit)
    {
        if (!in_array($deposit->status, [Deposit::STATUS_PENDING, Deposit::STATUS_ON_PROCESS], true)) {
            return response()->json(['message' => 'Only pending or in-process deposits can be updated.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $request->validated();

        $payload = [
            'status' => $data['status'],
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ];

        if (array_key_exists('notes', $data)) {
            $payload['notes'] = $data['notes'];
        }

        $deposit->update($payload);

        // Unlock (or release) a bonus code applied on the deposit form.
        try {
            if ($data['status'] === Deposit::STATUS_APPROVED) {
                BonusCodeEnforcer::onDepositApproved($deposit);
            } else {
                BonusCodeEnforcer::onDepositDeclined($deposit);
            }
        } catch (\Throwable $e) {
            Log::warning('Bonus code handling failed for deposit.', [
                'deposit_id' => $deposit->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Referral commission. An approval pays the referral chain; any other
        // status only FLAGS an existing accrual for admin review — nothing is
        // clawed back automatically (see CommissionAccrual).
        try {
            if ($data['status'] === Deposit::STATUS_APPROVED) {
                CommissionAccrual::onDepositApproved($deposit);
            } else {
                CommissionAccrual::onDepositStatusChanged($deposit);
            }
        } catch (\Throwable $e) {
            Log::warning('Referral commission handling failed for deposit.', [
                'deposit_id' => $deposit->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            UserPushNotifier::notifyDepositStatus($deposit);
        } catch (\Throwable $e) {
            Log::warning('User push after deposit status failed.', ['error' => $e->getMessage()]);
        }

        DepositCache::forgetForPlay($deposit->user_id ? 'user:' . $deposit->user_id : null);
        DepositCache::forgetForPlay($deposit->play_id);
        DepositCache::flushAdmin();

        return new DepositResource($deposit->refresh()->load(['user', 'account', 'approver', 'bonusRedemption']));
    }
}
