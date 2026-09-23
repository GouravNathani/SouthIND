<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateWithdrawalStatusRequest;
use App\Http\Resources\Admin\WithdrawalResource;
use App\Models\Withdrawal;
use App\Support\StatusTransition;
use App\Support\Cache\WithdrawalCache;
use App\Support\Push\UserPushNotifier;
use App\Support\Query\DurationAggregate;
use App\Support\ResolvesBranch;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;


class WithdrawalController extends Controller
{
    use ResolvesBranch;

    /** Rows returned when the list is requested without pagination. */
    private const RECENT_LIMIT = 100;

    public function index(Request $request)
    {
        $branchId = $this->resolveBranchId($request->user());
        $query = Withdrawal::query()
            ->where('branch_id', $branchId)
            ->with(['user', 'processor']);
        $perPage = (int) $request->query('per_page', 25);
        if ($perPage < 1) {
            $perPage = 25;
        }
        $perPage = min($perPage, 100);

        if ($status = $request->query('status')) {
            if (in_array($status, [
                Withdrawal::STATUS_PENDING,
                Withdrawal::STATUS_ON_PROCESS,
                Withdrawal::STATUS_APPROVED,
                Withdrawal::STATUS_REJECTED,
                Withdrawal::STATUS_FAILED,
            ], true)) {
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
                    ->orWhere('notes', 'like', $pattern)
                    ->orWhere('destination_type', 'like', $pattern)
                    ->orWhere('account_name', 'like', $pattern)
                    ->orWhere('account_number', 'like', $pattern)
                    ->orWhere('ifsc_code', 'like', $pattern)
                    ->orWhere('upi_id', 'like', $pattern)
                    ->orWhereHas('user', function ($query) use ($pattern) {
                        $query->where('name', 'like', $pattern)
                            ->orWhere('phone', 'like', $pattern)
                            ->orWhere('unique_number', 'like', $pattern)
                            ->orWhere('play_id', 'like', $pattern);
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

        $filters = array_filter([
            'branch_id' => $branchId,
            'status' => $status,
            'user_id' => $userId,
            'play_id' => $playId,
            'search' => $search,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ], fn ($value) => $value !== null && $value !== '');

        // The summary is a full aggregate over the branch's withdrawals and the list
        // is polled every ~35s by every open admin tab. Cache it briefly, keyed by
        // the active filters, so concurrent polls share one aggregate.
        $summary = Cache::remember('admin:withdrawals:summary:' . md5(json_encode($filters)), 30, function () use ($query) {
            $summaryRow = (clone $query)
                ->toBase()
                ->selectRaw('COALESCE(SUM(amount), 0) as total')
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) as approved_total',
                    [Withdrawal::STATUS_APPROVED],
                )
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN status IN (?, ?) THEN amount ELSE 0 END), 0) as rejected_total',
                    [Withdrawal::STATUS_REJECTED, Withdrawal::STATUS_FAILED],
                )
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as approved_count',
                    [Withdrawal::STATUS_APPROVED],
                )
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END), 0) as rejected_count',
                    [Withdrawal::STATUS_REJECTED, Withdrawal::STATUS_FAILED],
                )
                ->selectRaw('COUNT(*) as count')
                ->first();
            $avgProcessingRow = (clone $query)
                ->where('status', Withdrawal::STATUS_APPROVED)
                ->whereNotNull('processed_at')
                ->toBase()
                ->selectRaw(DurationAggregate::avgSecondsExpression('created_at', 'processed_at') . ' as avg_processing_seconds')
                ->first();

            return [
                'total' => (float) ($summaryRow->total ?? 0),
                'approvedTotal' => (float) ($summaryRow->approved_total ?? 0),
                'rejectedTotal' => (float) ($summaryRow->rejected_total ?? 0),
                'approvedCount' => (int) ($summaryRow->approved_count ?? 0),
                'rejectedCount' => (int) ($summaryRow->rejected_count ?? 0),
                'count' => (int) ($summaryRow->count ?? 0),
                'avgProcessingSeconds' => (float) ($avgProcessingRow->avg_processing_seconds ?? 0),
            ];
        });

        if ($request->has('page') || $request->has('per_page') || $search !== '' || $startDate !== '' || $endDate !== '') {
            $withdrawals = $query->latest()->paginate($perPage)->withQueryString();
            return WithdrawalResource::collection($withdrawals)->additional([
                'summary' => $summary,
            ]);
        }

        // Un-paginated calls (sidebar badges, notifications, dashboard) only need the
        // newest rows; the whole branch history made every poll multi-second.
        $withdrawals = $query->latest()->limit(self::RECENT_LIMIT)->get();

        return WithdrawalResource::collection($withdrawals)->additional([
            'summary' => $summary,
        ]);
    }

    public function show(Withdrawal $withdrawal)
    {
        $this->ensureOwnership($withdrawal, request()->user());

        return new WithdrawalResource($withdrawal->load(['user', 'processor']));
    }

    public function updateStatus(UpdateWithdrawalStatusRequest $request, Withdrawal $withdrawal)
    {
        $this->ensureOwnership($withdrawal, $request->user());

        if (!in_array($withdrawal->status, [Withdrawal::STATUS_PENDING, Withdrawal::STATUS_ON_PROCESS], true)) {
            return response()->json(['message' => 'Only pending or in-process withdrawals can be updated.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $request->validated();

        $payload = [
            'status' => $data['status'],
            'processed_by' => $request->user()->id,
            'processed_at' => now(),
        ];

        if (array_key_exists('notes', $data)) {
            $payload['notes'] = $data['notes'];
        }

        // Re-check under a row lock: two clicks (or two admins) landing together
        // both passed the check above, and every side effect below ran twice.
        if (!StatusTransition::claim($withdrawal, [Withdrawal::STATUS_PENDING, Withdrawal::STATUS_ON_PROCESS], $payload)) {
            return response()->json(['message' => 'Only pending or in-process withdrawals can be updated.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            UserPushNotifier::notifyWithdrawalStatus($withdrawal);
        } catch (\Throwable $e) {
            Log::warning('User push after withdrawal status failed.', ['error' => $e->getMessage()]);
        }

        // Clear user cache (new keying) and legacy play_id cache to avoid stale data
        WithdrawalCache::forgetForPlay($withdrawal->user_id ? 'user:' . $withdrawal->user_id : null);
        WithdrawalCache::forgetForPlay($withdrawal->play_id);
        WithdrawalCache::flushAdmin();

        return new WithdrawalResource($withdrawal->refresh()->load(['user', 'processor']));
    }

    public function destroy(Withdrawal $withdrawal): Response
    {
        $this->ensureOwnership($withdrawal, request()->user());

        $withdrawal->delete();

        // Clear user cache (new keying) and legacy play_id cache to avoid stale data
        WithdrawalCache::forgetForPlay($withdrawal->user_id ? 'user:' . $withdrawal->user_id : null);
        WithdrawalCache::forgetForPlay($withdrawal->play_id);
        WithdrawalCache::flushAdmin();

        return response()->noContent();
    }

    protected function ensureOwnership(Withdrawal $withdrawal, $actor): void
    {
        $branchId = $this->resolveBranchId($actor);

        if ((int) $withdrawal->branch_id !== (int) $branchId) {
            abort(Response::HTTP_NOT_FOUND, 'Withdrawal not found.');
        }
    }
}
