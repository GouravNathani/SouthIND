<?php

namespace App\Support\Bonus;

use App\Models\BonusCode;
use App\Models\BonusCodeRedemption;
use App\Models\Deposit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Single entry point for every bonus-code check and redemption, so the user
 * app, the deposit form and the admin panels all enforce identical rules.
 */
class BonusCodeRedeemer
{
    /**
     * Resolve a code for a user and run every eligibility rule, without
     * consuming it. Used by the deposit form's "check code" step.
     *
     * @throws ValidationException when the code cannot be used.
     */
    public static function validateFor(User $user, string $rawCode, ?float $depositAmount = null): BonusCode
    {
        $code = BonusCode::normalise($rawCode);

        if ($code === '') {
            self::fail('Enter a bonus code.');
        }

        $bonusCode = BonusCode::query()
            ->where('code', $code)
            ->usableInBranch($user->branch_id ? (int) $user->branch_id : null)
            ->first();

        // Deliberately identical message for "no such code" and "wrong branch"
        // so codes cannot be enumerated across branches.
        if (!$bonusCode) {
            self::fail('This bonus code is not valid.');
        }

        self::assertUsable($bonusCode, $user, $depositAmount);

        return $bonusCode;
    }

    /**
     * Consume the code. Returns the created redemption.
     *
     * The whole check-and-insert runs inside a transaction with the code row
     * locked, so the total cap can never be overshot by concurrent requests.
     *
     * @throws ValidationException
     */
    public static function redeem(
        User $user,
        string $rawCode,
        ?Deposit $deposit = null,
        ?Request $request = null,
    ): BonusCodeRedemption {
        $resolved = self::validateFor($user, $rawCode, $deposit ? (float) $deposit->amount : null);

        return DB::transaction(function () use ($resolved, $user, $deposit, $request) {
            /** @var BonusCode $bonusCode */
            $bonusCode = BonusCode::query()->lockForUpdate()->findOrFail($resolved->id);

            // Re-check under the lock — another request may have taken the last
            // slot (or an admin may have paused the code) since validation.
            self::assertUsable($bonusCode, $user, $deposit ? (float) $deposit->amount : null);

            $periodKey = self::claimPeriodKey($bonusCode, $user);

            // A deposit-attached code stays parked until that deposit is
            // approved; a standalone one either auto-fulfils or queues.
            $status = match (true) {
                $deposit !== null => BonusCodeRedemption::STATUS_AWAITING_DEPOSIT,
                $bonusCode->auto_approve => BonusCodeRedemption::STATUS_FULFILLED,
                default => BonusCodeRedemption::STATUS_PENDING,
            };

            $redemption = BonusCodeRedemption::create([
                'bonus_code_id' => $bonusCode->id,
                'user_id' => $user->id,
                'branch_id' => $user->branch_id,
                'code' => $bonusCode->code,
                'amount' => $bonusCode->reward_amount,
                'reward_label' => $bonusCode->reward_label,
                'status' => $status,
                'period_key' => $periodKey,
                'deposit_id' => $deposit?->id,
                'redeemed_at' => now(),
                'fulfilled_at' => $status === BonusCodeRedemption::STATUS_FULFILLED ? now() : null,
                'ip_address' => $request?->ip(),
                'device_hash' => self::deviceHash($request),
            ]);

            $bonusCode->increment('redeemed_count');

            return $redemption;
        });
    }

    /**
     * Release a redemption's slot and cap seat — used when the deposit it was
     * attached to is rejected, so the user may try the code again.
     */
    public static function release(BonusCodeRedemption $redemption, string $reason): void
    {
        if ($redemption->status === BonusCodeRedemption::STATUS_REJECTED) {
            return;
        }

        DB::transaction(function () use ($redemption, $reason) {
            $bonusCode = BonusCode::query()->lockForUpdate()->find($redemption->bonus_code_id);

            $redemption->status = BonusCodeRedemption::STATUS_REJECTED;
            // Void the period key so the unique index stops blocking a retry.
            $redemption->period_key = 'void#' . $redemption->id;
            $redemption->notes = trim((string) $redemption->notes . ' ' . $reason);
            $redemption->fulfilled_at = now();
            $redemption->save();

            if ($bonusCode && $bonusCode->redeemed_count > 0) {
                $bonusCode->decrement('redeemed_count');
            }
        });
    }

    /**
     * Every eligibility rule in one place.
     *
     * @throws ValidationException
     */
    protected static function assertUsable(BonusCode $bonusCode, User $user, ?float $depositAmount): void
    {
        if ($user->status === User::STATUS_BANNED) {
            self::fail('Your account cannot redeem bonus codes.');
        }

        if ($bonusCode->status !== BonusCode::STATUS_ACTIVE) {
            self::fail('This bonus code is not active.');
        }

        if (!$bonusCode->hasStarted()) {
            self::fail('This bonus code has not started yet.');
        }

        if ($bonusCode->isExpired()) {
            self::fail('This bonus code has expired.');
        }

        if ($bonusCode->isExhausted()) {
            self::fail('This bonus code has reached its limit.');
        }

        if ($bonusCode->requires_deposit && $depositAmount === null) {
            self::fail('This code can only be applied to a deposit.');
        }

        $minDeposit = (float) $bonusCode->min_deposit;
        if ($minDeposit > 0) {
            if ($depositAmount === null) {
                self::fail('This code can only be applied to a deposit.');
            }

            if ($depositAmount < $minDeposit) {
                self::fail('A deposit of at least ' . rtrim(rtrim(number_format($minDeposit, 2), '0'), '.') . ' is required for this code.');
            }
        }

        if ($bonusCode->new_user_days !== null) {
            $cutoff = now()->subDays($bonusCode->new_user_days);

            if ($user->created_at === null || $user->created_at->lt($cutoff)) {
                self::fail('This bonus code is only for new accounts.');
            }
        }

        $tagIds = $bonusCode->tags()->pluck('tags.id');
        if ($tagIds->isNotEmpty()) {
            $matches = $user->tags()->whereIn('tags.id', $tagIds)->exists();

            if (!$matches) {
                self::fail('This bonus code is not available for your account.');
            }
        }

        if (self::remainingUserSlots($bonusCode, $user) < 1) {
            self::fail('You have ' . BonusCodePeriod::label($bonusCode->frequency) . ' used this bonus code.');
        }
    }

    /**
     * Free slots left for this user inside the current period.
     */
    public static function remainingUserSlots(BonusCode $bonusCode, User $user): int
    {
        $limit = max(1, (int) $bonusCode->per_user_limit);
        $baseKey = BonusCodePeriod::keyFor($bonusCode);

        // Unlimited codes mint a fresh key each attempt, so nothing is ever taken.
        if ($bonusCode->frequency === BonusCode::FREQ_UNLIMITED) {
            return $limit;
        }

        $used = BonusCodeRedemption::query()
            ->where('bonus_code_id', $bonusCode->id)
            ->where('user_id', $user->id)
            ->where(function ($q) use ($baseKey) {
                $q->where('period_key', $baseKey)
                    ->orWhere('period_key', 'like', $baseKey . '#%');
            })
            ->count();

        return max(0, $limit - $used);
    }

    /**
     * First free slot key for this user/period. Callers must already hold the
     * code's row lock.
     */
    protected static function claimPeriodKey(BonusCode $bonusCode, User $user): string
    {
        $limit = max(1, (int) $bonusCode->per_user_limit);
        $baseKey = BonusCodePeriod::keyFor($bonusCode);

        for ($slot = 1; $slot <= $limit; $slot++) {
            $key = BonusCodePeriod::withSlot($baseKey, $slot);

            $taken = BonusCodeRedemption::query()
                ->where('bonus_code_id', $bonusCode->id)
                ->where('user_id', $user->id)
                ->where('period_key', $key)
                ->exists();

            if (!$taken) {
                return $key;
            }
        }

        self::fail('You have ' . BonusCodePeriod::label($bonusCode->frequency) . ' used this bonus code.');
    }

    protected static function deviceHash(?Request $request): ?string
    {
        $deviceId = trim((string) $request?->header('X-Device-Id', ''));

        return $deviceId === '' ? null : hash('sha256', $deviceId);
    }

    /**
     * @throws ValidationException
     */
    protected static function fail(string $message): never
    {
        throw ValidationException::withMessages(['code' => [$message]]);
    }
}
