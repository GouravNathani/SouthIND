<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Local cache of the external wallet's balance and rates (one row per wallet id).
 */
class WalletState extends Model
{
    protected $fillable = [
        'wallet_id',
        'name',
        'number',
        'status',
        'balance',
        'monthly_payout_percent',
        'pending_payout',
        'costs',
        'expires_at',
        'is_expired',
        'synced_at',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'monthly_payout_percent' => 'decimal:4',
        'pending_payout' => 'array',
        'costs' => 'array',
        'expires_at' => 'date',
        'is_expired' => 'boolean',
        'synced_at' => 'datetime',
    ];
}
