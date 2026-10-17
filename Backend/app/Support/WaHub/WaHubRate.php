<?php

namespace App\Support\WaHub;

use App\Support\Wallet\WalletService;
use Illuminate\Support\Facades\Log;

/**
 * What one outgoing WA Hub message costs, in coins.
 *
 * The hub bills the BookFlow Control wallet itself, once per message that is
 * actually sent; the panel never charges for it. The panel only needs the rate
 * to price a campaign before it goes out and to show it beside the usage.
 *
 * The rate is the wallet's `wahub_out_cost`, set per wallet in Control and read
 * the same network-free way every other message cost is read (Control's cached
 * or last-known value, else the self-wallet default in config/wallet.php).
 */
class WaHubRate
{
    /** Coins per message when the wallet cannot be read at all. */
    public const DEFAULT_COINS = 0.25;

    /** The current rate, to 2 decimals. Never throws. */
    public static function coinsPerMessage(): float
    {
        try {
            $rate = (float) app(WalletService::class)->costFor('wahub_out_cost');
        } catch (\Throwable $e) {
            Log::warning('WA Hub rate unavailable; using the default.', ['error' => $e->getMessage()]);

            return self::DEFAULT_COINS;
        }

        if (!is_finite($rate) || $rate < 0) {
            return self::DEFAULT_COINS;
        }

        return round($rate, 2);
    }

    /** What `$messages` sent messages cost, to 2 decimals. */
    public static function coinsFor(int $messages): float
    {
        return round($messages * self::coinsPerMessage(), 2);
    }
}
