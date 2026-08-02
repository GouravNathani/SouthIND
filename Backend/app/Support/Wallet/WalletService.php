<?php

namespace App\Support\Wallet;

use App\Models\SupportMessage;
use App\Models\WalletCharge;
use App\Models\WalletDailyPayout;
use App\Models\WalletState;
use App\Models\WhatsAppMessage;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the outbound BookFlowControl wallet integration: reads the
 * balance/rates (cached), charges per-message costs by deducting whole coins,
 * and tracks anything that couldn't be deducted as "owed" for later settlement.
 *
 * Nothing here throws to its callers — a wallet outage must never block a chat
 * or WhatsApp message.
 */
class WalletService
{
    /** Set while Control is failing, to skip the HTTP timeout on every read. */
    private const DOWN_CACHE_KEY = 'wallet:api-down';

    /**
     * Resolved snapshot for this instance's lifetime (one request / one job).
     *
     * Without it a single request would re-run the whole resolution — including
     * the HTTP call — once per costFor()/payoutPercent()/isSelf() call. With
     * Control down, each of those blocks for the full timeout.
     *
     * @var array<string, mixed>|null
     */
    private ?array $memo = null;

    /**
     * Same idea as $memo, but for the network-free pricing snapshot. Kept
     * separate so a pricing read never satisfies (or poisons) a UI read that
     * genuinely wants to talk to Control.
     *
     * @var array<string, mixed>|null
     */
    private ?array $pricingMemo = null;

    public function __construct(
        private readonly BookFlowWalletClient $client,
        private readonly WalletIdentity $identity,
    ) {
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * Balance + rates + costs for the wallet, in order of authority:
     *
     *   1. live   — a fresh read from Control (cached for a short TTL)
     *   2. stale  — the last-known persisted read, when Control won't answer
     *   3. self   — config defaults, when Control has never answered at all
     *
     * Control always wins while it responds; the self wallet is a fallback, not
     * an alternative. This never returns null, so a message is always priced.
     *
     * @return array<string, mixed>
     */
    public function snapshot(bool $fresh = false): array
    {
        if (!$fresh && $this->memo !== null) {
            return $this->memo;
        }

        return $this->memo = $this->resolveSnapshot($fresh);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSnapshot(bool $fresh): array
    {
        $walletId = $this->identity->configuredId();
        if ($walletId === null) {
            // No wallet connected yet — nothing to call, nothing to cache.
            return $this->selfSnapshot();
        }

        $cacheKey = "wallet:snapshot:{$walletId}";
        if (!$fresh) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        // Control was unreachable moments ago — don't spend another timeout on
        // every read until the cool-off passes, or a wallet outage slows the
        // whole panel to a crawl.
        if (!$fresh && Cache::get(self::DOWN_CACHE_KEY)) {
            return $this->stateAsSnapshot() ?? $this->selfSnapshot();
        }

        $res = $this->client->getBalance();
        if ($res['ok'] && is_array($res['data'])) {
            $data = $res['data'];
            $data['stale'] = false;
            $data['self'] = false;
            $data['rate_source'] = WalletCharge::SOURCE_LIVE;
            $this->persistState($data);
            Cache::forget(self::DOWN_CACHE_KEY);
            Cache::put($cacheKey, $data, (int) config('wallet.balance_cache_ttl', 30));

            return $data;
        }

        // 'not_configured' is a permanent local state, not an outage — no point
        // cooling off on a call that never left the process.
        if (($res['code'] ?? null) !== 'not_configured') {
            Cache::put(self::DOWN_CACHE_KEY, true, (int) config('wallet.down_cache_ttl', 10));
        }

        return $this->stateAsSnapshot() ?? $this->selfSnapshot();
    }

    /**
     * Rates for pricing a message, resolved WITHOUT ever touching the network.
     *
     * Sending a message must cost zero outbound HTTP — the nightly payout job is
     * the only thing that talks to Control now. Order of authority:
     *
     *   1. a snapshot this request already resolved (UI pages do a real read)
     *   2. the short-lived cache
     *   3. the persisted WalletState
     *   4. config defaults (self wallet)
     *
     * `wallet:sync-rates` keeps 2 and 3 warm, so the numbers stay current.
     *
     * @return array<string, mixed>
     */
    public function pricingSnapshot(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        if ($this->pricingMemo !== null) {
            return $this->pricingMemo;
        }

        $walletId = $this->identity->configuredId();

        if ($walletId !== null) {
            $cached = Cache::get("wallet:snapshot:{$walletId}");
            if (is_array($cached)) {
                return $this->pricingMemo = $cached;
            }
        }

        return $this->pricingMemo = $this->stateAsSnapshot() ?? $this->selfSnapshot();
    }

    /**
     * Where the rates being charged right now came from: live | stale | self.
     */
    public function rateSource(): string
    {
        return (string) ($this->pricingSnapshot()['rate_source'] ?? WalletCharge::SOURCE_SELF);
    }

    /**
     * Is the panel running on its own self wallet right now — either because no
     * wallet is connected, or because Control has never been reachable?
     */
    public function isSelf(): bool
    {
        return $this->rateSource() === WalletCharge::SOURCE_SELF;
    }

    /**
     * The self wallet's balance: it holds no funds, so it opens at zero (config)
     * and every charge that has not been settled against Control drives it
     * negative. The resulting minus IS the amount owed.
     */
    public function localBalance(): float
    {
        return round((float) config('wallet.self.opening_balance', 0) - $this->unbilledCoins(), 2);
    }

    /**
     * Every charge that has not been paid to Control yet — today's not-yet-billed
     * ones plus anything sitting in owed. This is the true "not paid for" total.
     */
    public function unbilledCoins(): float
    {
        return (float) WalletCharge::query()
            ->where('status', WalletCharge::STATUS_UNSETTLED)
            ->sum('coins');
    }

    /**
     * Rates/costs straight from config, used only when Control has told us
     * nothing. Shaped exactly like a Control balance payload so every caller
     * (UI included) reads one format regardless of where the numbers came from.
     *
     * @return array<string, mixed>
     */
    private function selfSnapshot(): array
    {
        $costs = [];
        foreach ((array) config('wallet.self.costs', []) as $field => $value) {
            $costs[$field] = ['value' => (float) $value, 'pending' => null];
        }

        $walletId = $this->identity->walletId();

        return [
            'wallet_id' => $walletId,
            'name' => (string) config('app.name'),
            'number' => $walletId,
            'status' => 'self',
            'balance' => $this->localBalance(),
            'monthly_payout_percent' => (float) config('wallet.self.payout_percent', 0.01),
            'pending_payout' => null,
            'costs' => $costs,
            'expires_at' => null,
            // A self wallet is never "expired" — an unconnected wallet must not
            // trip the expiry popup and lock staff out of their own panel.
            'is_expired' => false,
            'stale' => false,
            'self' => true,
            'rate_source' => WalletCharge::SOURCE_SELF,
            // True when even the Super Admin has no usable phone number, so the
            // ledger is running without any wallet id to reconcile against.
            'unidentified' => $walletId === null,
        ];
    }

    /**
     * Developer payout percent from the wallet, falling back to config.
     */
    public function payoutPercent(): float
    {
        $snap = $this->snapshot();
        $pct = $snap['monthly_payout_percent'] ?? null;

        return $pct !== null ? (float) $pct : (float) config('wallet.fallback_payout_percent', 0.001);
    }

    /**
     * The money cost for a cost field (e.g. support_in_cost).
     *
     * Always resolves to a number: Control's live/cached value when we have one,
     * otherwise the self-wallet default. It must never return null — a message
     * whose cost we can't look up still has to be priced and recorded, or the
     * usage is lost for good.
     */
    public function costFor(string $field): float
    {
        $value = $this->pricingSnapshot()['costs'][$field]['value'] ?? null;

        return (float) ($value ?? config("wallet.self.costs.{$field}", 0));
    }

    // ── Owed ─────────────────────────────────────────────────────────────────

    /**
     * Coins Control could not collect: every past day whose single payout failed
     * (API down, insufficient funds, anything). Today's charges are NOT owed —
     * they have not been billed yet; see pendingTodayCoins().
     */
    public function owedCoins(): float
    {
        return (float) WalletDailyPayout::query()->owed()->sum('coins');
    }

    /**
     * Owed split by calendar month (YYYY-MM => coins), newest month first. This
     * is what Control shows and clears against.
     *
     * @return array<string, float>
     */
    public function owedByMonth(): array
    {
        $months = [];

        foreach (WalletDailyPayout::query()->owed()->orderByDesc('payout_date')->get() as $payout) {
            $key = $payout->payout_date->format('Y-m');
            $months[$key] = round(($months[$key] ?? 0) + (float) $payout->coins, 2);
        }

        return $months;
    }

    /**
     * Charges booked today that tonight's 3 AM payout will bill. Shown separately
     * from owed so staff can tell "not billed yet" from "billing failed".
     */
    public function pendingTodayCoins(): float
    {
        $today = Carbon::now(config('app.timezone'))->startOfDay();

        return (float) WalletCharge::query()
            ->where('status', WalletCharge::STATUS_UNSETTLED)
            ->where('created_at', '>=', $today)
            ->sum('coins');
    }

    // ── Charging ───────────────────────────────────────────────────────────────

    public function chargeForSupportMessage(SupportMessage $message, string $direction): ?WalletCharge
    {
        // Every support message is billed at the support text rate. A message
        // that also carries media (image/voice) is billed the media rate on top
        // (media_in_cost/media_out_cost) — a separate, idempotent charge.
        $charge = $this->chargeMessage(WalletCharge::CHANNEL_SUPPORT, $direction, $message);

        if ($message->image_path || $message->audio_path) {
            $this->chargeMessage(WalletCharge::CHANNEL_MEDIA, $direction, $message);
        }

        return $charge;
    }

    public function chargeForWhatsAppMessage(WhatsAppMessage $message, string $direction): ?WalletCharge
    {
        return $this->chargeMessage(WalletCharge::CHANNEL_WHATSAPP, $direction, $message);
    }

    /**
     * Book one message's cost (down to ₹0.01) against the wallet.
     *
     * This writes to the local ledger ONLY — no wallet API call is made here, by
     * design. Every charge booked during a day is billed as a single deduction
     * by `wallet:daily-payout` at 03:00 the next morning. Never throws, and is
     * idempotent per (channel,direction,id).
     */
    public function chargeMessage(string $channel, string $direction, Model $message): ?WalletCharge
    {
        try {
            $field = "{$channel}_{$direction}_cost";
            // Both read the same memoised snapshot — one resolution per request.
            $source = $this->rateSource();
            $cost = $this->costFor($field);

            if ($source === WalletCharge::SOURCE_SELF) {
                // Control told us nothing, so this row is priced from config. It
                // is still charged and still owed — `rate_source` marks it so the
                // amount can be re-checked against Control's real rate later.
                Log::info('Wallet rates unavailable; charging at self-wallet default.', [
                    'channel' => $channel,
                    'direction' => $direction,
                    'message_id' => $message->getKey(),
                    'cost' => $cost,
                ]);
            }

            $reference = $this->referenceFor($channel, $direction, $message);

            $charge = WalletCharge::query()->firstOrNew(['reference' => $reference]);
            // Already resolved by a previous call — idempotent no-op.
            if ($charge->exists && $charge->status !== WalletCharge::STATUS_UNSETTLED) {
                return $charge;
            }

            // Coins are money (1 coin = ₹1), kept to paise precision so a ₹0.01
            // cost deducts exactly 0.01 — no rounding up to a whole coin.
            $coins = round($cost * (float) config('wallet.coins_per_unit', 1), 2);

            $charge->fill([
                'channel' => $channel,
                'direction' => $direction,
                'cost_field' => $field,
                'cost_value' => $cost,
                'coins' => max(0, $coins),
                'reference' => $reference,
                'reference_type' => $message::class,
                'reference_id' => $message->getKey(),
                'rate_source' => $source,
            ]);

            if ($coins <= 0) {
                // A zero/negative cost is genuinely free — nothing to deduct.
                $charge->status = WalletCharge::STATUS_SKIPPED;
                $charge->save();

                return $charge;
            }

            // Unsettled = "booked, waiting for tonight's payout". It only counts
            // as owed once its day's payout has actually failed.
            $charge->status = WalletCharge::STATUS_UNSETTLED;
            $charge->save();

            return $charge;
        } catch (\Throwable $e) {
            Log::warning('Wallet charge failed.', [
                'channel' => $channel,
                'direction' => $direction,
                'message_id' => $message->getKey() ?? null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Void every charge tied to a message (e.g. when an admin deletes it) so it
     * stops counting toward the support cost. An unsettled charge is never
     * deducted; a settled one can't be refunded on the deduct-only external
     * wallet, but is dropped from local usage/owed totals. Never throws.
     */
    public function voidChargesFor(Model $message): int
    {
        try {
            $charges = WalletCharge::query()
                ->where('reference_type', $message::class)
                ->where('reference_id', $message->getKey())
                ->where('status', '!=', WalletCharge::STATUS_VOIDED)
                ->get();

            foreach ($charges as $charge) {
                $charge->status = WalletCharge::STATUS_VOIDED;
                $charge->voided_at = now();
                $charge->save();
            }

            return $charges->count();
        } catch (\Throwable $e) {
            Log::warning('Wallet charge void failed.', [
                'message_type' => $message::class,
                'message_id' => $message->getKey() ?? null,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    // ── Daily payout — the only path that deducts from the wallet ────────────

    /**
     * Bill every completed day that hasn't been paid for yet, oldest first, as
     * ONE deduction per day. This is what runs at 03:00.
     *
     * A day is all-or-nothing: if Control is down, or the balance can't cover
     * the whole day, the day is marked `owed` and its charges stay unsettled.
     * Owed days are retried on every later run — the per-day reference makes the
     * deduct idempotent, so a day that actually went through can never be
     * charged twice.
     *
     * @param  CarbonInterface|string|null  $date  bill only this day (retry/backfill)
     * @param  int  $maxDays  how far back to reach when catching up
     * @return array{days: int, settled: int, owed: int, coins: float}
     */
    public function runDailyPayout(CarbonInterface|string|null $date = null, int $maxDays = 60): array
    {
        $tz = config('app.timezone');
        $today = Carbon::now($tz)->startOfDay();

        if ($date !== null) {
            $days = [Carbon::parse($date, $tz)->startOfDay()];
        } else {
            $days = [];
            $cursor = $this->earliestUnbilledDay($today, $maxDays);
            while ($cursor->lt($today)) {
                $days[] = $cursor->copy();
                $cursor->addDay();
            }
        }

        $result = ['days' => 0, 'settled' => 0, 'owed' => 0, 'coins' => 0.0];

        foreach ($days as $day) {
            $payout = $this->buildDayPayout($day);
            if ($payout === null) {
                continue;
            }

            $result['days']++;
            $result['coins'] = round($result['coins'] + (float) $payout->coins, 2);

            if ($this->settleDay($payout)) {
                $result['settled']++;
            } else {
                $result['owed']++;
            }
        }

        return $result;
    }

    /**
     * Retained for the SuperAdmin "settle now" button and the `wallet:settle`
     * command: both simply mean "try the outstanding days again right now".
     *
     * @return array{attempted: int, settled: int}
     */
    public function retryUnsettled(int $limit = 60): array
    {
        $result = $this->runDailyPayout(null, $limit);

        return ['attempted' => $result['days'], 'settled' => $result['settled']];
    }

    /**
     * Tell Control how much this panel still owes, so an operator can see it and
     * clear it by hand. Best-effort — never throws, never blocks a payout run.
     */
    public function reportOwed(): bool
    {
        $res = $this->client->reportOwed([
            'owed_total' => $this->owedCoins(),
            'by_month' => (object) $this->owedByMonth(),
            'as_of' => now()->toIso8601String(),
        ]);

        if (!$res['ok']) {
            Log::info('Owed report to Control failed.', ['code' => $res['code'] ?? null]);
        }

        return (bool) $res['ok'];
    }

    /**
     * Control has collected the owed amount by hand (an operator debited the
     * wallet from the Control panel). Close the matching days locally so the
     * same money is never chased twice.
     *
     * @param  string  $month  "YYYY-MM", or "all" for every owed day
     * @return array{cleared_coins: float, days: int}
     */
    public function markOwedCleared(string $month, ?string $reference = null): array
    {
        $query = WalletDailyPayout::query()->owed();

        if ($month !== 'all') {
            $start = Carbon::createFromFormat('Y-m-d', "{$month}-01")->startOfDay();
            $query->whereDate('payout_date', '>=', $start->toDateString())
                ->whereDate('payout_date', '<=', $start->copy()->endOfMonth()->toDateString());
        }

        $coins = 0.0;
        $days = 0;

        foreach ($query->get() as $payout) {
            $coins = round($coins + (float) $payout->coins, 2);
            $days++;

            $payout->forceFill([
                'status' => WalletDailyPayout::STATUS_CLEARED,
                'cleared_reference' => $reference,
                'settled_at' => now(),
                'error_code' => null,
            ])->save();

            $this->closeChargesForDay($payout, null);
        }

        return ['cleared_coins' => $coins, 'days' => $days];
    }

    // ── Internals ──────────────────────────────────────────────────────────────

    /**
     * The oldest day we still have something to bill for, clamped to $maxDays
     * so a long-dormant panel can't fan out into hundreds of API calls. Returns
     * $today (i.e. "nothing to do") when the ledger is clean.
     *
     * Deliberately avoids SQL date functions — the panels run on MySQL in
     * production and SQLite locally, and the two don't share a date dialect.
     */
    private function earliestUnbilledDay(CarbonInterface $today, int $maxDays): Carbon
    {
        $tz = config('app.timezone');
        $floor = $today->copy()->subDays(max(1, $maxDays));

        $candidates = [];

        $firstCharge = WalletCharge::query()
            ->where('status', WalletCharge::STATUS_UNSETTLED)
            ->where('coins', '>', 0)
            ->where('created_at', '<', $today)
            ->min('created_at');

        if ($firstCharge) {
            $candidates[] = Carbon::parse($firstCharge)->setTimezone($tz)->startOfDay();
        }

        $firstOwed = WalletDailyPayout::query()
            ->whereIn('status', [WalletDailyPayout::STATUS_OWED, WalletDailyPayout::STATUS_PENDING])
            ->min('payout_date');

        if ($firstOwed) {
            $candidates[] = Carbon::parse($firstOwed, $tz)->startOfDay();
        }

        if ($candidates === []) {
            return $today->copy();
        }

        $earliest = min($candidates);

        return $earliest->lt($floor) ? $floor : $earliest->copy();
    }

    /**
     * Create or refresh the payout row for one day from its unsettled charges.
     * Returns null when there is nothing to bill (already paid, or no charges).
     */
    private function buildDayPayout(CarbonInterface $day): ?WalletDailyPayout
    {
        $start = $day->copy()->startOfDay();
        $end = $start->copy()->addDay();

        $existing = WalletDailyPayout::query()
            // whereDate, not a plain equality: the `date` column round-trips
            // through SQLite with a 00:00:00 time part, which a string compare
            // against "Y-m-d" would miss.
            ->whereDate('payout_date', $start->toDateString())
            ->first();

        // Already collected (or written off by Control) — never re-bill a day.
        if ($existing && in_array($existing->status, [
            WalletDailyPayout::STATUS_SETTLED,
            WalletDailyPayout::STATUS_CLEARED,
        ], true)) {
            return null;
        }

        $charges = WalletCharge::query()
            ->where('status', WalletCharge::STATUS_UNSETTLED)
            ->where('coins', '>', 0)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->get(['channel', 'direction', 'coins']);

        if ($charges->isEmpty() && $existing === null) {
            return null;
        }

        $breakdown = [];
        $total = 0.0;

        foreach ($charges as $charge) {
            $key = "{$charge->channel}_{$charge->direction}";
            $breakdown[$key]['count'] = ($breakdown[$key]['count'] ?? 0) + 1;
            $breakdown[$key]['coins'] = round(($breakdown[$key]['coins'] ?? 0) + (float) $charge->coins, 2);
            $total = round($total + (float) $charge->coins, 2);
        }

        $payout = $existing ?? new WalletDailyPayout(['payout_date' => $start->toDateString()]);

        $payout->forceFill([
            'payout_date' => $start->toDateString(),
            'coins' => $total,
            'charge_count' => $charges->count(),
            'breakdown' => $breakdown,
            'reference' => $this->dayReferenceFor($start),
            'status' => WalletDailyPayout::STATUS_PENDING,
        ])->save();

        return $payout;
    }

    /**
     * Deduct one day's total in a single call and record the outcome.
     */
    private function settleDay(WalletDailyPayout $payout): bool
    {
        $payout->attempts = (int) $payout->attempts + 1;
        $payout->last_attempt_at = now();

        // Everything for that day was voided (deleted messages) — nothing to collect.
        if ((float) $payout->coins <= 0) {
            $payout->forceFill([
                'status' => WalletDailyPayout::STATUS_SKIPPED,
                'error_code' => null,
                'settled_at' => now(),
            ])->save();

            return true;
        }

        $res = $this->client->deduct((float) $payout->coins, $payout->reference);

        if ($res['ok'] && is_array($res['data'])) {
            // A successful deduct proves Control is back — stop the cool-off so
            // the next read picks up live rates immediately.
            Cache::forget(self::DOWN_CACHE_KEY);

            $payout->forceFill([
                'status' => WalletDailyPayout::STATUS_SETTLED,
                'external_transaction_id' => $res['data']['transaction_id'] ?? null,
                'balance_after' => $res['data']['balance'] ?? null,
                'error_code' => null,
                'settled_at' => now(),
            ])->save();

            $this->closeChargesForDay($payout, $res['data']['transaction_id'] ?? null);
            $this->cacheBalance($res['data']['balance'] ?? null);

            return true;
        }

        $payout->forceFill([
            'status' => WalletDailyPayout::STATUS_OWED,
            'error_code' => $res['code'] ?? ('http_' . $res['status']),
        ])->save();

        return false;
    }

    /**
     * Mark a settled/cleared day's charges as done, stamping them with the one
     * transaction that paid for the whole day.
     */
    private function closeChargesForDay(WalletDailyPayout $payout, ?string $transactionId): void
    {
        $start = $payout->payout_date->copy()->startOfDay();

        WalletCharge::query()
            ->where('status', WalletCharge::STATUS_UNSETTLED)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $start->copy()->addDay())
            ->update([
                'status' => WalletCharge::STATUS_SETTLED,
                'external_transaction_id' => $transactionId,
                'error_code' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * Prefix every reference with this panel's own tag. All BookFlow panels bill
     * one shared wallet account and Control dedupes on the reference, so two
     * panels sharing a prefix would silently eat each other's charges as replays.
     */
    private function referencePrefix(): string
    {
        return trim((string) config('wallet.reference_prefix', 'sind'), '-');
    }

    private function referenceFor(string $channel, string $direction, Model $message): string
    {
        return "{$this->referencePrefix()}-{$channel}-{$direction}-" . $message->getKey();
    }

    /**
     * Idempotency key for a whole day's payout. Control dedupes on this, so a
     * retried day is a replay, never a second deduction.
     */
    private function dayReferenceFor(CarbonInterface $day): string
    {
        return $this->referencePrefix() . '-day-' . $day->format('Y-m-d');
    }

    /**
     * Persist the latest wallet read for durable fallback + UI display.
     *
     * @param  array<string, mixed>  $data
     */
    private function persistState(array $data): void
    {
        WalletState::query()->updateOrCreate(
            ['wallet_id' => (string) ($data['wallet_id'] ?? $this->identity->configuredId())],
            [
                'name' => $data['name'] ?? null,
                'number' => $data['number'] ?? null,
                'status' => $data['status'] ?? null,
                'balance' => $data['balance'] ?? null,
                'monthly_payout_percent' => $data['monthly_payout_percent'] ?? null,
                'pending_payout' => $data['pending_payout'] ?? null,
                'costs' => $data['costs'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
                'is_expired' => (bool) ($data['is_expired'] ?? false),
                'synced_at' => now(),
            ],
        );
    }

    /**
     * Rebuild a snapshot from the last-known persisted state (flagged stale).
     * Returns null when Control has never been read on this panel — the caller
     * then drops to the self wallet.
     *
     * @return array<string, mixed>|null
     */
    private function stateAsSnapshot(): ?array
    {
        $state = WalletState::query()
            ->where('wallet_id', (string) $this->identity->configuredId())
            ->first();

        // A state row with no rates cached is no more useful than no row at all;
        // the self wallet at least has real numbers to charge against.
        if (!$state || !is_array($state->costs) || $state->costs === []) {
            return null;
        }

        return [
            'wallet_id' => $state->wallet_id,
            'name' => $state->name,
            'number' => $state->number,
            'status' => $state->status,
            'balance' => $state->balance,
            'monthly_payout_percent' => $state->monthly_payout_percent !== null
                ? (float) $state->monthly_payout_percent
                : null,
            'pending_payout' => $state->pending_payout,
            'costs' => $state->costs,
            'expires_at' => optional($state->expires_at)->toDateString(),
            'is_expired' => (bool) $state->is_expired,
            'stale' => true,
            'self' => false,
            'rate_source' => WalletCharge::SOURCE_STALE,
            'synced_at' => optional($state->synced_at)->toIso8601String(),
        ];
    }

    /**
     * Minimal expiry status for the gating popup (Admin/SuperAdmin). Never
     * throws; an unreadable wallet reports not-expired so an outage doesn't
     * lock staff out of the panel.
     *
     * @return array{is_expired: bool, expires_at: ?string}
     */
    public function expiryStatus(): array
    {
        $snap = $this->snapshot();

        return [
            'is_expired' => (bool) ($snap['is_expired'] ?? false),
            'expires_at' => $snap['expires_at'] ?? null,
        ];
    }

    /**
     * Keep the cached balance + persisted state in step after a deduction.
     */
    private function cacheBalance(int|float|null $balance): void
    {
        if ($balance === null) {
            return;
        }

        // The memos hold the pre-deduction balance; drop them so later reads in
        // this same request don't report a balance we know has moved.
        $this->memo = null;
        $this->pricingMemo = null;

        $walletId = (string) $this->identity->configuredId();
        WalletState::query()->where('wallet_id', $walletId)->update(['balance' => $balance]);

        $cacheKey = "wallet:snapshot:{$walletId}";
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            $cached['balance'] = $balance;
            Cache::put($cacheKey, $cached, (int) config('wallet.balance_cache_ttl', 30));
        }
    }
}
