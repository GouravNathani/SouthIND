<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\WithdrawalRequest;
use App\Http\Resources\User\WithdrawalResource;
use App\Models\Withdrawal;
use App\Support\Cache\WithdrawalCache;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class WithdrawalController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $cacheKey = 'user:' . $user->id;

        $pendingWithdrawals = WithdrawalCache::rememberForPlay($cacheKey, function () use ($user) {
            return Withdrawal::query()
                ->where('user_id', $user->id)
                ->whereIn('status', [Withdrawal::STATUS_PENDING, Withdrawal::STATUS_ON_PROCESS])
                ->latest()
                ->get();
        });

        $successfulWithdrawals = Withdrawal::query()
            ->where('user_id', $user->id)
            ->where('status', Withdrawal::STATUS_APPROVED)
            ->latest()
            ->limit(2)
            ->get();

        $failedWithdrawals = Withdrawal::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [Withdrawal::STATUS_FAILED, Withdrawal::STATUS_REJECTED])
            ->latest()
            ->limit(2)
            ->get();

        return response()->json([
            'pending' => WithdrawalResource::collection($pendingWithdrawals),
            'recent_success' => WithdrawalResource::collection($successfulWithdrawals),
            'recent_failed' => WithdrawalResource::collection($failedWithdrawals),
        ]);
    }

    public function store(WithdrawalRequest $request)
    {
        $data = $request->validated();
        $user = $request->user();
        $amount = $data['amount'];
        $destinationType = $data['destination_type'];
        $duplicateMessage = 'Same Req Already Added Please wait..';
        $duplicateQuery = Withdrawal::query()
            ->where('user_id', $user?->id)
            ->whereIn('status', [Withdrawal::STATUS_PENDING, Withdrawal::STATUS_ON_PROCESS])
            ->where('amount', $amount)
            ->where('destination_type', $destinationType);

        if ($destinationType === 'upi') {
            $upiId = isset($data['upi_id']) ? trim((string) $data['upi_id']) : '';
            $duplicateQuery->where('upi_id', $upiId);
        } else {
            $accountNumber = isset($data['account_number'])
                ? trim((string) $data['account_number'])
                : '';
            $ifscCode = isset($data['ifsc_code']) ? trim((string) $data['ifsc_code']) : '';
            $duplicateQuery->where('account_number', $accountNumber)->where('ifsc_code', $ifscCode);
        }

        if ($duplicateQuery->exists()) {
            return response()->json([
                'message' => $duplicateMessage,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $lockedPlayId = trim((string) ($user?->play_id ?? ''));
        $incomingPlayId = trim((string) ($data['play_id'] ?? ''));
        if ($lockedPlayId !== '') {
            if ($incomingPlayId !== '' && $incomingPlayId !== $lockedPlayId) {
                throw ValidationException::withMessages([
                    'play_id' => ['User ID is locked for this account.'],
                ]);
            }
            $playId = $lockedPlayId;
        } else {
            $playId = $incomingPlayId !== '' ? $incomingPlayId : ($user?->unique_number ?? '');
        }
        $branchId = $user?->branch_id;

        $withdrawal = Withdrawal::create([
            'user_id' => $user?->id,
            'play_id' => $playId,
            'branch_id' => $branchId,
            'amount' => $data['amount'],
            'destination_type' => $data['destination_type'],
            'upi_id' => $data['upi_id'] ?? null,
            'account_number' => $data['account_number'] ?? null,
            'ifsc_code' => $data['ifsc_code'] ?? null,
            'account_name' => $data['account_name'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => Withdrawal::STATUS_PENDING,
        ]);

        WithdrawalCache::forgetForPlay('user:' . $user?->id);
        WithdrawalCache::flushAdmin();

        return response()->json([
            'message' => 'Withdrawal request received',
            'withdrawal' => new WithdrawalResource($withdrawal),
        ], Response::HTTP_CREATED);
    }
}
