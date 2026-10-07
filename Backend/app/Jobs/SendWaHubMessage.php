<?php

namespace App\Jobs;

use App\Support\Cache\GlobalSettingCache;
use App\Support\WaHub\WaHubClient;
use App\Support\WaHub\WaHubNotifier;
use App\Support\WaHub\WaHubPhone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Posts one event message to the WA Hub.
 *
 * Carries ids, the event key, the idempotency key and the acting admin only —
 * never the MPIN or the phone. Everything else is read when the job runs. The
 * idempotency key makes a retry safe: the hub returns the same message instead
 * of sending it again.
 *
 * Database queue, drained by the one-minute scheduler cron (`queue:work
 * --stop-when-empty` in routes/console.php). Hub unreachable → retried after
 * 1 and 5 minutes; a 4xx answer is final and only logged.
 */
class SendWaHubMessage implements ShouldQueue, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /**
     * @param  array{type: string, id: string, name: string}|null  $actor
     */
    public function __construct(
        public string $event,
        public string $refType,
        public int $refId,
        public string $idempotencyKey,
        public ?array $actor = null,
    ) {
        $this->onConnection('database');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(WaHubClient $client): void
    {
        // Switched off (or keys removed) since it was queued: drop it.
        if (!GlobalSettingCache::waHubEnabled() || !$client->configured()) {
            return;
        }

        $payload = WaHubNotifier::buildMessage($this->event, $this->refType, $this->refId, $this->idempotencyKey, $this->actor);
        if ($payload === null) {
            return;
        }

        // Unavailable throws → the queue retries with the backoff above.
        $response = $client->sendMessage($payload);

        if (!$response->successful()) {
            Log::warning('WA Hub refused an event message.', [
                'event' => $this->event,
                'ref' => "{$this->refType}-{$this->refId}",
                'to' => WaHubPhone::last4($payload['to']),
                'status' => $response->status,
                'error' => $response->error(),
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('WA Hub event message dropped after retries.', [
            'event' => $this->event,
            'ref' => "{$this->refType}-{$this->refId}",
            'error' => $e->getMessage(),
        ]);
    }
}
