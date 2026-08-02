<?php

namespace App\Support\Push;

use App\Models\Deposit;
use App\Models\UserPushSubscription;
use App\Models\Withdrawal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Web-push notifications targeted at end USERS (their own deposit/withdrawal
 * status changes). Mirrors AdminPushNotifier but scopes to the owning user
 * instead of a whole branch's admins.
 *
 * NOTE (BC port): trimmed to deposit/withdrawal status + support replies.
 * Referral commission notifications are below; notifyStreakLost() is still
 * missing and can be added when the Streak feature needs it.
 */
class UserPushNotifier
{
    public static function notifyDepositStatus(Deposit $deposit): void
    {
        if (!$deposit->user_id) {
            return;
        }

        $payload = self::statusPayload('Deposit', (string) $deposit->amount, $deposit->status);
        if (!$payload) {
            return;
        }

        self::sendToUser((int) $deposit->user_id, $payload);
    }

    public static function notifyWithdrawalStatus(Withdrawal $withdrawal): void
    {
        if (!$withdrawal->user_id) {
            return;
        }

        $payload = self::statusPayload('Withdrawal', (string) $withdrawal->amount, $withdrawal->status);
        if (!$payload) {
            return;
        }

        self::sendToUser((int) $withdrawal->user_id, $payload);
    }

    public static function notifySupportReply(int $userId, ?string $preview): void
    {
        self::sendToUser($userId, [
            'title' => 'Support replied 💬',
            'body' => $preview ?: 'You have a new reply from support.',
            'url' => '/#/chat',
        ]);
    }

    public static function notifyBonusApproved(int $userId, $amount, ?string $label): void
    {
        $reward = trim((string) $amount . ' ' . (string) $label);
        self::sendToUser($userId, [
            'title' => 'Bonus approved 🎉',
            'body' => $reward !== '' ? "You earned {$reward}!" : 'Your bonus code reward was approved!',
            'url' => '/#/bonus',
        ]);
    }

    /**
     * An agent earned commission on a team member's approved deposit.
     */
    public static function notifyCommissionEarned(int $agentId, $amount, ?string $fromName): void
    {
        $from = trim((string) $fromName);

        self::sendToUser($agentId, [
            'title' => 'Commission earned 💰',
            'body' => $from !== ''
                ? "₹{$amount} commission {$from} ke deposit se aapke account me aaya."
                : "₹{$amount} commission aapke account me aaya.",
            'url' => '/#/account',
        ]);
    }

    /**
     * A new user joined under this agent.
     */
    public static function notifyReferralJoined(int $agentId, ?string $memberName): void
    {
        $name = trim((string) $memberName);

        self::sendToUser($agentId, [
            'title' => 'New member joined 🎉',
            'body' => $name !== ''
                ? "{$name} aapke referral se juda."
                : 'Aapke referral se ek naya user juda.',
            'url' => '/#/account',
        ]);
    }

    /**
     * A commission payout request was approved or rejected.
     */
    public static function notifyCommissionPayout(int $agentId, $amount, string $status): void
    {
        $payload = $status === 'approved'
            ? ['title' => 'Payout approved ✅', 'body' => "Aapka ₹{$amount} commission payout approve ho gaya."]
            : ['title' => 'Payout declined', 'body' => "Aapka ₹{$amount} commission payout decline ho gaya."];

        self::sendToUser($agentId, $payload + ['url' => '/#/account']);
    }

    /**
     * Build a user-facing title/body for a terminal status, or null for
     * statuses that should not notify (pending/processing).
     *
     * @return array{title:string, body:string, url:string}|null
     */
    private static function statusPayload(string $label, string $amount, string $status): ?array
    {
        return match ($status) {
            Deposit::STATUS_APPROVED => [
                'title' => "{$label} approved ✅",
                'body' => "Aapka {$label} of {$amount} approve ho gaya.",
                'url' => '/#/history',
            ],
            Deposit::STATUS_REJECTED, Deposit::STATUS_FAILED => [
                'title' => "{$label} declined",
                'body' => "Aapka {$label} of {$amount} decline ho gaya.",
                'url' => '/#/history',
            ],
            default => null,
        };
    }

    /**
     * Winner Streak: broadcast a freshly closed cycle's top winner to every
     * active user in the branch.
     */
    public static function notifyWinnerAnnouncement(?int $branchId, string $title, string $body): void
    {
        if (!$branchId) {
            return;
        }

        $subscriptions = UserPushSubscription::query()
            ->whereHas('user', function ($query) use ($branchId) {
                $query->where('branch_id', $branchId)
                    ->where('status', \App\Models\User::STATUS_ACTIVE);
            })
            ->get();

        self::dispatch($subscriptions, [
            'title' => $title,
            'body' => $body,
            'url' => '/#/dashboard',
        ]);
    }

    private static function sendToUser(?int $userId, array $payload): void
    {
        if (!$userId) {
            return;
        }

        self::dispatch(self::subscriptionsForUser($userId), $payload);
    }

    /**
     * @param  Collection<int, UserPushSubscription>  $subscriptions
     */
    private static function dispatch(Collection $subscriptions, array $payload): void
    {
        $publicKey = config('webpush.vapid.public_key');
        $privateKey = config('webpush.vapid.private_key');
        $subject = config('webpush.vapid.subject');

        if (!$publicKey || !$privateKey || !$subject) {
            return;
        }

        if ($subscriptions->isEmpty()) {
            return;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => $subject,
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ],
        ]);

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payloadJson === false) {
            return;
        }

        foreach ($subscriptions as $subscription) {
            $contentEncoding = $subscription->content_encoding ?: 'aesgcm';
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->public_key,
                    'authToken' => $subscription->auth_token,
                    'contentEncoding' => $contentEncoding,
                ]),
                $payloadJson,
                ['TTL' => 600],
            );
        }

        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                continue;
            }

            $response = $report->getResponse();
            $statusCode = $response?->getStatusCode();
            $endpoint = $report->getRequest()?->getUri()?->__toString();
            $reason = trim((string) $report->getReason());

            $looksUnauthorized = $statusCode === 403
                || str_contains(strtolower($reason), 'unauthorized');

            if (($statusCode === 404 || $statusCode === 410 || $looksUnauthorized) && $endpoint) {
                UserPushSubscription::query()
                    ->where('endpoint', $endpoint)
                    ->delete();

                Log::warning('User web push subscription removed after failed delivery.', [
                    'status' => $statusCode,
                    'endpoint' => $endpoint,
                    'reason' => $reason,
                ]);
                continue;
            }

            Log::warning('User web push failed.', [
                'status' => $statusCode,
                'endpoint' => $endpoint,
                'reason' => $reason,
            ]);
        }
    }

    /**
     * @return Collection<int, UserPushSubscription>
     */
    private static function subscriptionsForUser(int $userId): Collection
    {
        return UserPushSubscription::query()
            ->where('user_id', $userId)
            ->get();
    }
}
