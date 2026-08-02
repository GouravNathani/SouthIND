<?php

namespace App\Support\Push;

use App\Models\Deposit;
use App\Models\PushSubscription;
use App\Models\Withdrawal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class AdminPushNotifier
{
    public static function notifyDeposit(Deposit $deposit): void
    {
        $title = 'New deposit';
        $body = sprintf('Deposit of %s received.', (string) $deposit->amount);
        self::sendToBranch($deposit->branch_id, [
            'title' => $title,
            'body' => $body,
            'url' => '/deposits',
        ]);
    }

    public static function notifyWithdrawal(Withdrawal $withdrawal): void
    {
        $title = 'New withdrawal';
        $body = sprintf('Withdrawal of %s received.', (string) $withdrawal->amount);
        self::sendToBranch($withdrawal->branch_id, [
            'title' => $title,
            'body' => $body,
            'url' => '/withdrawals',
        ]);
    }

    public static function notifySupportMessage(?int $branchId, ?string $userName, ?string $preview): void
    {
        self::sendToBranch($branchId, [
            'title' => 'New support message',
            'body' => trim(($userName ? $userName . ': ' : '') . ($preview ?: 'New message')),
            'url' => '/support',
        ]);
    }

    /**
     * Broadcast to every super admin (used by global features like WhatsApp
     * inbound, which are not tied to any single branch).
     */
    public static function notifySuperAdmins(string $title, string $body, string $url): void
    {
        self::dispatch(self::subscriptionsForSuperAdmins(), [
            'title' => $title,
            'body' => $body,
            'url' => $url,
        ]);
    }

    private static function sendToBranch(?int $branchId, array $payload): void
    {
        self::dispatch(self::subscriptionsForBranch($branchId), $payload);
    }

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

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
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
                ['TTL' => 60],
            );
        }

        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                continue;
            }

            $response = $report->getResponse();
            $statusCode = $response?->getStatusCode();
            $endpoint = $report->getRequest()?->getUri()?->__toString();

            if ($statusCode === 404 || $statusCode === 410) {
                PushSubscription::query()
                    ->where('endpoint', $endpoint)
                    ->delete();
                continue;
            }

            Log::warning('Web push failed.', [
                'status' => $statusCode,
                'endpoint' => $endpoint,
            ]);
        }
    }

    /**
     * @return Collection<int, PushSubscription>
     */
    private static function subscriptionsForSuperAdmins(): Collection
    {
        return PushSubscription::query()
            ->with('admin')
            ->whereHas('admin', function ($query) {
                $query->where('role', 'super_admin');
            })
            ->get();
    }

    /**
     * @return Collection<int, PushSubscription>
     */
    private static function subscriptionsForBranch(?int $branchId): Collection
    {
        return PushSubscription::query()
            ->with('admin')
            ->whereHas('admin', function ($query) use ($branchId) {
                $query->whereIn('role', ['super_admin', 'admin']);
                if ($branchId) {
                    $query->where(function ($inner) use ($branchId) {
                        $inner->where('role', 'super_admin')
                            ->orWhere('branch_id', $branchId);
                    });
                }
            })
            ->get();
    }
}
