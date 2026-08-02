<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An agent's Commission Account — cached totals over commission_entries.
 *
 * Not related to WalletState/WalletCharge, which are the external message
 * billing wallet. The user-facing app calls this simply "Account".
 */
class CommissionAccount extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'user_id',
        'branch_id',
        'available_balance',
        'pending_balance',
        'lifetime_earned',
        'lifetime_paid',
        'lifetime_adjusted',
        'team_count',
        'team_active_count',
        'team_deposit_total',
        'tier_label',
        'tier_percent',
        'status',
        'last_accrual_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'branch_id' => 'integer',
        'available_balance' => 'decimal:2',
        'pending_balance' => 'decimal:2',
        'lifetime_earned' => 'decimal:2',
        'lifetime_paid' => 'decimal:2',
        'lifetime_adjusted' => 'decimal:2',
        'team_count' => 'integer',
        'team_active_count' => 'integer',
        'team_deposit_total' => 'decimal:2',
        'tier_percent' => 'decimal:3',
        'last_accrual_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function entries()
    {
        return $this->hasMany(CommissionEntry::class, 'agent_id', 'user_id');
    }

    /**
     * The account for an agent, opened on first touch. Opening an account is
     * free and side-effect free — it is what "Uska Wallet create ho jaye" means
     * here: a row with zero balances that the ledger then fills.
     */
    public static function forUser(User $user): self
    {
        $account = static::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'branch_id' => $user->branch_id,
                'status' => $user->agent_status === 'suspended'
                    ? self::STATUS_SUSPENDED
                    : self::STATUS_ACTIVE,
            ],
        );

        if ($account->wasRecentlyCreated) {
            $account->refresh();
        }

        return $account;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }
}
