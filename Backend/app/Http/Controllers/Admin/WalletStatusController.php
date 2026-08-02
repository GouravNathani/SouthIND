<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Wallet\WalletService;
use Illuminate\Http\JsonResponse;

/**
 * Read-only expiry status for the Admin panel's blocking popup. Admin only
 * gets is_expired/expires_at — the full balance/costs snapshot stays
 * SuperAdmin-only.
 */
class WalletStatusController extends Controller
{
    public function __construct(private readonly WalletService $wallet)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->wallet->expiryStatus()]);
    }
}
