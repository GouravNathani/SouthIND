<?php

namespace App\Support\Wallet;

use App\Models\Admin;
use Illuminate\Support\Facades\Cache;

/**
 * Decides which wallet id this panel bills against.
 *
 * Normally that is the id BookFlowControl issued, set as WALLET_ID. Until a
 * wallet is connected there is no such id, so the panel falls back to the Super
 * Admin's own phone number: that is the same number an operator types into
 * Control when the wallet is finally created (Control makes the account number
 * the wallet's permanent id), so the self-wallet's ledger lines up with the real
 * wallet on the day it connects.
 *
 * Nothing here throws — an unresolvable id degrades to null, which callers read
 * as "no wallet at all".
 */
class WalletIdentity
{
    private const CACHE_KEY = 'wallet:super-admin-number';

    private const CACHE_TTL = 300;

    /**
     * The wallet id to bill against, or null when neither a configured id nor a
     * usable Super Admin number exists.
     */
    public function walletId(): ?string
    {
        return $this->configuredId() ?? $this->superAdminNumber();
    }

    /**
     * True while no wallet id is configured — i.e. the panel is running on its
     * own self wallet rather than a Control-issued one. Note this is about
     * configuration only; a configured wallet whose API is merely down is NOT
     * "self" (see WalletService::snapshot, which prefers the cached state).
     */
    public function isSelf(): bool
    {
        return $this->configuredId() === null;
    }

    /**
     * The WALLET_ID from config, normalised, or null when unset/unusable.
     */
    public function configuredId(): ?string
    {
        return self::normalise((string) config('wallet.wallet_id'));
    }

    /**
     * The first Super Admin's phone number, digits only. Cached briefly so the
     * per-message charge path never adds a query per send.
     */
    public function superAdminNumber(): ?string
    {
        try {
            // Cache a '' sentinel rather than null: Cache::remember treats null
            // as a miss and would re-run the query on every single call.
            $number = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): string {
                $phone = Admin::query()
                    ->where('role', 'super_admin')
                    ->whereNotNull('phone')
                    ->orderBy('id')
                    ->value('phone');

                return self::normalise((string) $phone) ?? '';
            });
        } catch (\Throwable) {
            // No DB yet (install/migrate) — a wallet id is never worth throwing for.
            return null;
        }

        return $number === '' ? null : $number;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Control only accepts digit-only ids 3–32 chars long, so strip separators
     * (+91, spaces, dashes) and reject anything that could never be a wallet id.
     */
    private static function normalise(string $value): ?string
    {
        $digits = preg_replace('/\D/', '', trim($value)) ?? '';

        return strlen($digits) >= 3 && strlen($digits) <= 32 ? $digits : null;
    }
}
