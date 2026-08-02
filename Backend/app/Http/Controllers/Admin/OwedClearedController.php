<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Control tells this panel that an owed amount has been collected by hand.
 *
 * When a day's payout fails (API down, balance short) the day is marked `owed`
 * and reported to Control. An operator can then debit the wallet directly from
 * the Control panel; Control follows that with a signed call to this endpoint so
 * the panel closes the same days locally and stops chasing the money.
 *
 * Reached only through the shared-secret `wallet-hmac` gate, exactly like
 * maintenance/refresh-cache. The month travels in the PATH, not the query
 * string, because only the path is covered by the signature.
 */
class OwedClearedController extends Controller
{
    public function __construct(private readonly WalletService $wallet)
    {
    }

    public function __invoke(Request $request, string $month): JsonResponse
    {
        // "all" clears every owed day; otherwise it must be a real YYYY-MM.
        if ($month !== 'all' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return response()->json(['message' => 'Month must be YYYY-MM or "all".'], 422);
        }

        $result = $this->wallet->markOwedCleared($month, $request->query('reference'));

        return response()->json([
            'message' => $result['days'] === 0
                ? 'Nothing was owed for that period.'
                : "Cleared {$result['cleared_coins']} coins across {$result['days']} day(s).",
            'month' => $month,
            'cleared_coins' => $result['cleared_coins'],
            'days' => $result['days'],
        ]);
    }
}
