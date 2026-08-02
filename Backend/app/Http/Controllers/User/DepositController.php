<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\DepositRequest;
use App\Http\Resources\User\DepositResource;
use App\Models\Account;
use App\Models\Deposit;
use App\Support\Bonus\BonusCodeRedeemer;
use App\Support\Cache\DepositCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class DepositController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $cacheKey = 'user:' . $user->id;

        $pendingDeposits = DepositCache::rememberForPlay($cacheKey, function () use ($user) {
            return Deposit::query()
                ->where('user_id', $user->id)
                ->whereIn('status', [Deposit::STATUS_PENDING, Deposit::STATUS_ON_PROCESS])
                ->with(['account'])
                ->latest()
                ->get();
        });

        $successfulDeposits = Deposit::query()
            ->where('user_id', $user->id)
            ->where('status', Deposit::STATUS_APPROVED)
            ->with(['account'])
            ->latest()
            ->limit(2)
            ->get();

        $failedDeposits = Deposit::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [Deposit::STATUS_FAILED, Deposit::STATUS_REJECTED])
            ->with(['account'])
            ->latest()
            ->limit(2)
            ->get();

        return response()->json([
            'pending' => DepositResource::collection($pendingDeposits),
            'recent_success' => DepositResource::collection($successfulDeposits),
            'recent_failed' => DepositResource::collection($failedDeposits),
        ]);
    }

    public function store(DepositRequest $request)
    {
        $data = $request->validated();
        $user = $request->user();
        $branchId = $user?->branch_id;

        $account = Account::query()
            ->active()
            ->forUse('deposit')
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->findOrFail($data['account_id']);

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
        // Validate the applied bonus code before anything is written, so an
        // unusable code fails the request instead of creating a code-less deposit.
        $bonusCodeInput = trim((string) ($data['bonus_code'] ?? ''));
        $bonusCode = $bonusCodeInput !== ''
            ? BonusCodeRedeemer::validateFor($user, $bonusCodeInput, (float) $data['amount'])
            : null;

        $receiptUrl = $data['receipt_image_path'] ?? null;

        if ($request->hasFile('receipt_image')) {
            $receiptUrl = $this->storeReceiptFile($request->file('receipt_image'));
        } elseif ($request->hasFile('proof_image')) {
            $receiptUrl = $this->storeReceiptFile($request->file('proof_image'));
        } elseif (!empty($data['proof_image'])) {
            $receiptUrl = $this->storeBase64Receipt((string) $data['proof_image']) ?? $receiptUrl;
        }

        unset($data['receipt_image']);
        unset($data['receipt_image_path']);
        unset($data['proof_image']);
        unset($data['bonus_code']);

        $deposit = Deposit::create([
            'user_id' => $user?->id,
            'play_id' => $playId,
            'account_id' => $account->id,
            'branch_id' => $branchId,
            'amount' => $data['amount'],
            'utr_number' => $data['utr_number'] ?? null,
            'notes' => $data['notes'] ?? null,
            'receipt_image_path' => $receiptUrl,
            'bonus_code_id' => $bonusCode?->id,
            'status' => Deposit::STATUS_PENDING,
        ]);

        $bonusMessage = null;
        if ($bonusCode) {
            try {
                BonusCodeRedeemer::redeem($user, $bonusCode->code, $deposit, $request);
                $bonusMessage = 'Bonus code applied. It unlocks once this deposit is approved.';
            } catch (\Throwable $e) {
                // The deposit itself is valid — never fail it over a bonus that
                // was taken between validation and this call.
                Log::warning('Bonus code could not be applied to deposit.', [
                    'deposit_id' => $deposit->id,
                    'code' => $bonusCode->code,
                    'error' => $e->getMessage(),
                ]);
                $deposit->forceFill(['bonus_code_id' => null])->save();
                $bonusMessage = 'Deposit received, but the bonus code could not be applied.';
            }
        }

        DepositCache::forgetForPlay('user:' . $user?->id);
        DepositCache::flushAdmin();

        return response()->json([
            'message' => 'Deposit request received',
            'bonus_message' => $bonusMessage,
            'deposit' => new DepositResource($deposit->load('account')),
        ], Response::HTTP_CREATED);
    }

    protected function storeReceiptFile($file): string
    {
        $storedPath = $file->store('deposit-receipts', 'public');

        return Storage::url($storedPath);
    }

    protected function storeBase64Receipt(string $payload): ?string
    {
        if (!preg_match('/^data:image\/(\w+);base64,/', $payload, $matches)) {
            return null;
        }

        $extension = strtolower($matches[1]);
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        $data = substr($payload, strpos($payload, ',') + 1);
        $binary = base64_decode($data, true);

        if ($binary === false) {
            return null;
        }

        $relativePath = 'deposit-receipts/' . Str::random(40) . '.' . $extension;
        Storage::disk('public')->put($relativePath, $binary);

        return Storage::url($relativePath);
    }
}
