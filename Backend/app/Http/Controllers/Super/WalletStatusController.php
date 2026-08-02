<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Support\Wallet\WalletService;
use Illuminate\Http\JsonResponse;

/**
 * Lightweight expiry status for the SuperAdmin blocking popup, polled
 * app-wide. Only exposes is_expired/expires_at.
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
