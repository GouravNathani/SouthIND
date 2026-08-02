<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BonusCode extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';

    public const FREQ_ONCE = 'once';           // lifetime, one time only
    public const FREQ_DAILY = 'daily';
    public const FREQ_WEEKLY = 'weekly';
    public const FREQ_MONTHLY = 'monthly';
    public const FREQ_UNLIMITED = 'unlimited';

    public const FREQUENCIES = [
        self::FREQ_ONCE,
        self::FREQ_DAILY,
        self::FREQ_WEEKLY,
        self::FREQ_MONTHLY,
        self::FREQ_UNLIMITED,
    ];

    protected $fillable = [
        'code',
        'branch_id',
        'title',
        'terms_text',
        'reward_amount',
        'reward_label',
        'frequency',
        'per_user_limit',
        'max_redemptions',
        'redeemed_count',
        'starts_at',
        'expires_at',
        'status',
        'auto_approve',
        'requires_deposit',
        'min_deposit',
        'new_user_days',
        'created_by',
        'created_by_role',
    ];

    protected $casts = [
        'reward_amount' => 'decimal:2',
        'min_deposit' => 'decimal:2',
        'per_user_limit' => 'integer',
        'max_redemptions' => 'integer',
        'redeemed_count' => 'integer',
        'new_user_days' => 'integer',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'auto_approve' => 'boolean',
        'requires_deposit' => 'boolean',
    ];

    /**
     * Codes are typed by hand on a phone — always normalise before storing or
     * comparing so "welcome100 " and "WELCOME100" are the same code.
     */
    public static function normalise(?string $code): string
    {
        return Str::upper(trim((string) $code));
    }

    protected static function booted(): void
    {
        static::saving(function (self $bonusCode): void {
            $bonusCode->code = self::normalise($bonusCode->code);
        });
    }

    public function redemptions()
    {
        return $this->hasMany(BonusCodeRedemption::class);
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'bonus_code_tag');
    }

    public function creator()
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function isExhausted(): bool
    {
        return $this->max_redemptions !== null
            && $this->redeemed_count >= $this->max_redemptions;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function hasStarted(): bool
    {
        return $this->starts_at === null || !$this->starts_at->isFuture();
    }

    /**
     * Live state for the admin list — `status` alone does not tell the whole
     * story once a code expires or runs out.
     */
    public function effectiveStatus(): string
    {
        if ($this->status === self::STATUS_PAUSED) {
            return self::STATUS_PAUSED;
        }

        if ($this->isExpired()) {
            return 'expired';
        }

        if ($this->isExhausted()) {
            return 'exhausted';
        }

        if (!$this->hasStarted()) {
            return 'scheduled';
        }

        return self::STATUS_ACTIVE;
    }

    public function remainingRedemptions(): ?int
    {
        if ($this->max_redemptions === null) {
            return null;
        }

        return max(0, $this->max_redemptions - $this->redeemed_count);
    }

    /**
     * Codes with branch_id = null work in every branch.
     */
    public function scopeUsableInBranch($query, ?int $branchId)
    {
        return $query->where(function ($q) use ($branchId) {
            $q->whereNull('branch_id');

            if ($branchId) {
                $q->orWhere('branch_id', $branchId);
            }
        });
    }
}
