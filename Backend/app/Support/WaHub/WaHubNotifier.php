<?php

namespace App\Support\WaHub;

use App\Jobs\SendWaHubMessage;
use App\Models\Admin;
use App\Models\BonusCodeRedemption;
use App\Models\Branch;
use App\Models\Deposit;
use App\Models\User;
use App\Models\Withdrawal;
use App\Support\Cache\GlobalSettingCache;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Event messages (deposit/withdrawal decided, welcome, MPIN reset, bonus
 * approved) sent to the user's WhatsApp from their branch's linked number.
 *
 * The hooks only queue a SendWaHubMessage job carrying ids, the event key, the
 * idempotency key and the acting admin — never the MPIN or the phone. The job
 * loads the records and builds the variables at send time (buildMessage), so
 * a retry always sends what is current and secrets never sit in the queue.
 *
 * Whether an event is on is decided by the hub (per branch, set by its admin):
 * every message is posted and comes back `skipped: event_off` when it is off.
 * Hooks never throw — a WhatsApp problem must never break the request.
 */
class WaHubNotifier
{
    public const DEPOSIT_APPROVED = 'deposit.approved';
    public const DEPOSIT_REJECTED = 'deposit.rejected';
    public const WITHDRAWAL_APPROVED = 'withdrawal.approved';
    public const WITHDRAWAL_REJECTED = 'withdrawal.rejected';
    public const USER_WELCOME = 'user.welcome';
    public const MPIN_RESET = 'mpin.reset';
    public const BONUS_APPROVED = 'bonus.approved';

    /** Dates in messages are always shown in IST. */
    private const TIMEZONE = 'Asia/Kolkata';

    // ---- Hooks (called right after the change is saved) --------------------

    public static function depositStatus(Deposit $deposit, mixed $actor = null): void
    {
        $event = match ($deposit->status) {
            Deposit::STATUS_APPROVED => self::DEPOSIT_APPROVED,
            Deposit::STATUS_REJECTED, Deposit::STATUS_FAILED => self::DEPOSIT_REJECTED,
            default => null,
        };

        if ($event !== null) {
            self::queue($event, 'deposit', (int) $deposit->id, "deposit-{$deposit->id}-{$deposit->status}", $actor);
        }
    }

    public static function withdrawalStatus(Withdrawal $withdrawal, mixed $actor = null): void
    {
        $event = match ($withdrawal->status) {
            Withdrawal::STATUS_APPROVED => self::WITHDRAWAL_APPROVED,
            Withdrawal::STATUS_REJECTED, Withdrawal::STATUS_FAILED => self::WITHDRAWAL_REJECTED,
            default => null,
        };

        if ($event !== null) {
            self::queue($event, 'withdrawal', (int) $withdrawal->id, "withdrawal-{$withdrawal->id}-{$withdrawal->status}", $actor);
        }
    }

    public static function userWelcome(User $user, mixed $actor = null): void
    {
        if (blank($user->phone)) {
            return;
        }

        self::queue(self::USER_WELCOME, 'user', (int) $user->id, "user-{$user->id}-welcome", $actor);
    }

    /** Every reset is its own message, so the key carries the reset time. */
    public static function mpinReset(User $user, mixed $actor = null): void
    {
        if (blank($user->phone)) {
            return;
        }

        self::queue(self::MPIN_RESET, 'user', (int) $user->id, "user-{$user->id}-mpin-" . now()->getTimestamp(), $actor);
    }

    public static function bonusApproved(BonusCodeRedemption $redemption, mixed $actor = null): void
    {
        if ($redemption->status !== BonusCodeRedemption::STATUS_FULFILLED) {
            return;
        }

        self::queue(self::BONUS_APPROVED, 'bonus_redemption', (int) $redemption->id, "bonus-{$redemption->id}-approved", $actor);
    }

    /** Switched on by the super admin AND the hub keys are set. */
    public static function available(): bool
    {
        return GlobalSettingCache::waHubEnabled() && app(WaHubClient::class)->configured();
    }

    /**
     * The acting admin as the hub records it (staff count as `admin`).
     * Anything but an Admin → no actor.
     *
     * @return array{type: string, id: string, name: string}|null
     */
    public static function actor(mixed $admin): ?array
    {
        if (!$admin instanceof Admin) {
            return null;
        }

        return [
            'type' => $admin->isSuperAdmin() ? 'super' : 'admin',
            'id' => (string) $admin->id,
            'name' => (string) $admin->name,
        ];
    }

    private static function queue(string $event, string $refType, int $refId, string $idempotencyKey, mixed $actor): void
    {
        try {
            if (!self::available()) {
                return;
            }

            SendWaHubMessage::dispatch($event, $refType, $refId, $idempotencyKey, self::actor($actor));
        } catch (\Throwable $e) {
            Log::warning('WA Hub message could not be queued.', [
                'event' => $event,
                'ref' => "{$refType}-{$refId}",
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ---- Send time (SendWaHubMessage) --------------------------------------

    /**
     * The POST /v1/messages body for one event, built from the current records.
     * Null when there is nothing to send (record gone, no user, no phone).
     *
     * @param  array{type: string, id: string, name: string}|null  $actor
     * @return array<string, mixed>|null
     */
    public static function buildMessage(string $event, string $refType, int $refId, string $idempotencyKey, ?array $actor = null): ?array
    {
        $built = match ($refType) {
            'deposit' => self::depositVars($event, $refId),
            'withdrawal' => self::withdrawalVars($event, $refId),
            'user' => self::userVars($event, $refId),
            'bonus_redemption' => self::bonusVars($refId),
            default => null,
        };

        if ($built === null) {
            return null;
        }

        [$user, $branchId, $vars] = $built;

        $to = WaHubPhone::normalize($user->phone);
        if ($to === '' || !$branchId) {
            return null;
        }

        $payload = [
            'connection' => WaHubClient::connectionKey($branchId),
            'event' => $event,
            'to' => $to,
            'name' => (string) $user->name,
            'vars' => $vars,
            'ref' => [
                'type' => $refType,
                'id' => (string) $refId,
                'user_id' => (string) $user->id,
            ],
            'idempotency_key' => $idempotencyKey,
        ];

        if ($actor !== null) {
            $payload['actor'] = $actor;
        }

        return $payload;
    }

    /** @return array{0: User, 1: ?int, 2: array<string, string>}|null */
    private static function depositVars(string $event, int $id): ?array
    {
        $deposit = Deposit::query()->with(['user', 'branch'])->find($id);
        $user = $deposit?->user;
        if (!$deposit || !$user) {
            return null;
        }

        $branch = $deposit->branch ?? $user->branch;

        $vars = [
            'name' => (string) $user->name,
            'amount' => self::amount($deposit->amount),
            'play_id' => (string) ($deposit->play_id ?: $user->play_id),
            'utr' => (string) $deposit->utr_number,
            'date' => self::date($deposit->approved_at ?? $deposit->updated_at),
            'branch_name' => (string) $branch?->name,
        ];

        if ($event === self::DEPOSIT_REJECTED) {
            $vars['reason'] = (string) $deposit->notes;
        }

        return [$user, $branch?->id, $vars];
    }

    /** @return array{0: User, 1: ?int, 2: array<string, string>}|null */
    private static function withdrawalVars(string $event, int $id): ?array
    {
        $withdrawal = Withdrawal::query()->with(['user', 'branch'])->find($id);
        $user = $withdrawal?->user;
        if (!$withdrawal || !$user) {
            return null;
        }

        $branch = $withdrawal->branch ?? $user->branch;

        $vars = [
            'name' => (string) $user->name,
            'amount' => self::amount($withdrawal->amount),
            'play_id' => (string) ($withdrawal->play_id ?: $user->play_id),
        ];

        if ($event === self::WITHDRAWAL_REJECTED) {
            $vars['reason'] = (string) $withdrawal->notes;
        } else {
            $vars['destination'] = self::destination($withdrawal);
        }

        $vars['date'] = self::date($withdrawal->processed_at ?? $withdrawal->updated_at);
        $vars['branch_name'] = (string) $branch?->name;

        return [$user, $branch?->id, $vars];
    }

    /** @return array{0: User, 1: ?int, 2: array<string, string>}|null */
    private static function userVars(string $event, int $id): ?array
    {
        $user = User::query()->with('branch')->find($id);
        if (!$user) {
            return null;
        }

        $vars = [
            'name' => (string) $user->name,
            'phone' => (string) $user->phone,
        ];

        if ($event === self::USER_WELCOME) {
            $vars['play_id'] = (string) $user->play_id;
        }

        // Read now, never carried in the queue: a reset after this one was
        // queued simply sends the newest MPIN.
        $vars['mpin'] = (string) $user->mpin;
        $vars['login_link'] = self::loginLink($user->branch);
        $vars['branch_name'] = (string) $user->branch?->name;

        return [$user, $user->branch_id ? (int) $user->branch_id : null, $vars];
    }

    /** @return array{0: User, 1: ?int, 2: array<string, string>}|null */
    private static function bonusVars(int $id): ?array
    {
        $redemption = BonusCodeRedemption::query()->with('user.branch')->find($id);
        $user = $redemption?->user;
        if (!$redemption || !$user) {
            return null;
        }

        // A global code's redemption still belongs to the user's branch.
        $branchId = $redemption->branch_id ?: $user->branch_id;
        $branch = (int) $branchId === (int) $user->branch_id ? $user->branch : Branch::query()->find($branchId);

        return [$user, $branchId ? (int) $branchId : null, [
            'name' => (string) $user->name,
            'bonus_code' => (string) $redemption->code,
            'amount' => $redemption->amount === null ? '' : self::amount($redemption->amount),
            'reward_label' => (string) $redemption->reward_label,
            'play_id' => (string) $user->play_id,
            'branch_name' => (string) $branch?->name,
        ]];
    }

    // ---- Formatting --------------------------------------------------------

    /**
     * Indian digit grouping, no decimals when whole: 125000 → "1,25,000",
     * 1234.5 → "1,234.50".
     */
    public static function amount(int|float|string|null $value): string
    {
        $number = round((float) $value, 2);
        $sign = $number < 0 ? '-' : '';
        [$whole, $fraction] = explode('.', number_format(abs($number), 2, '.', ''));

        if (strlen($whole) > 3) {
            $head = substr($whole, 0, -3);
            $head = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $head);
            $whole = $head . ',' . substr($whole, -3);
        }

        return $sign . $whole . ($fraction === '00' ? '' : '.' . $fraction);
    }

    /** "07 Oct 2026, 09:15 PM" in IST. */
    public static function date(?CarbonInterface $at): string
    {
        return Carbon::instance($at ?? now())->setTimezone(self::TIMEZONE)->format('d M Y, h:i A');
    }

    /** "UPI ••••kar@ybl" or "A/C ••••1234" — never the full destination. */
    public static function destination(Withdrawal $withdrawal): string
    {
        $upi = trim((string) $withdrawal->upi_id);
        $account = preg_replace('/\s+/', '', (string) $withdrawal->account_number) ?? '';

        if ($withdrawal->destination_type === 'upi' || ($account === '' && $upi !== '')) {
            if ($upi === '') {
                return 'UPI';
            }

            if (!str_contains($upi, '@')) {
                $tail = min(4, max(0, mb_strlen($upi) - 1));

                return 'UPI ••••' . ($tail > 0 ? mb_substr($upi, -$tail) : '');
            }

            [$local, $handle] = explode('@', $upi, 2);
            $tail = min(3, max(0, mb_strlen($local) - 1));

            return 'UPI ••••' . ($tail > 0 ? mb_substr($local, -$tail) : '') . '@' . $handle;
        }

        return $account === '' ? 'A/C' : 'A/C ••••' . substr($account, -4);
    }

    /** https:// + the branch's domain, else the configured default. */
    public static function loginLink(?Branch $branch): string
    {
        $domain = trim((string) $branch?->domain);

        if ($domain === '') {
            return rtrim((string) config('services.wahub.login_link'), '/');
        }

        if (!preg_match('#^https?://#i', $domain)) {
            $domain = 'https://' . $domain;
        }

        return rtrim($domain, '/');
    }
}
