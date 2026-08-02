<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A frozen leaderboard row for a closed cycle.
 */
class WinnerStreakEntry extends Model
{
    public const KIND_PROFIT = 'profit';
    public const KIND_LOSS = 'loss';

    public const REWARD_PENDING = 'pending';
    public const REWARD_PAID = 'paid';
    public const REWARD_SKIPPED = 'skipped';

    public const REWARD_STATUSES = [
        self::REWARD_PENDING,
        self::REWARD_PAID,
        self::REWARD_SKIPPED,
    ];

    protected $fillable = [
        'cycle_id',
        'branch_id',
        'period',
        'user_id',
        'kind',
        'rank',
        'deposit_total',
        'withdrawal_total',
        'bonus_total',
        'net_amount',
        'transactions_count',
        'display_name',
        'display_play_id',
        'display_phone',
        'reward_amount',
        'reward_label',
        'reward_status',
        'paid_by',
        'paid_at',
        'notes',
    ];

    protected $casts = [
        'cycle_id' => 'integer',
        'branch_id' => 'integer',
        'user_id' => 'integer',
        'rank' => 'integer',
        'deposit_total' => 'decimal:2',
        'withdrawal_total' => 'decimal:2',
        'bonus_total' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'transactions_count' => 'integer',
        'reward_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function cycle()
    {
        return $this->belongsTo(WinnerStreakCycle::class, 'cycle_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payer()
    {
        return $this->belongsTo(Admin::class, 'paid_by');
    }
}
