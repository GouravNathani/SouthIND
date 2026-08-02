<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BonusCodeRedemption extends Model
{
    // Applied to a deposit that has not been approved yet — invisible to the
    // admin payout queue until the deposit clears.
    public const STATUS_AWAITING_DEPOSIT = 'awaiting_deposit';
    public const STATUS_PENDING = 'pending';     // unlocked, awaiting admin payout
    public const STATUS_FULFILLED = 'fulfilled'; // admin approved + reward handed over
    public const STATUS_REJECTED = 'rejected';   // admin declined (or deposit failed)

    public const STATUSES = [
        self::STATUS_AWAITING_DEPOSIT,
        self::STATUS_PENDING,
        self::STATUS_FULFILLED,
        self::STATUS_REJECTED,
    ];

    protected $fillable = [
        'bonus_code_id',
        'user_id',
        'branch_id',
        'code',
        'amount',
        'reward_label',
        'status',
        'period_key',
        'deposit_id',
        'redeemed_at',
        'fulfilled_by',
        'fulfilled_at',
        'notes',
        'ip_address',
        'device_hash',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'redeemed_at' => 'datetime',
        'fulfilled_at' => 'datetime',
    ];

    public function bonusCode()
    {
        return $this->belongsTo(BonusCode::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function deposit()
    {
        return $this->belongsTo(Deposit::class);
    }

    public function fulfiller()
    {
        return $this->belongsTo(Admin::class, 'fulfilled_by');
    }
}
