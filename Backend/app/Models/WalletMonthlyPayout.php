<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One finished month's Monthly Payout (the developer's percent of that month's
 * approved deposits), billed to the external wallet as a SINGLE deduction.
 *
 * Statuses mirror WalletDailyPayout. The amount and percent are frozen when the
 * month is first billed; an owed month is retried with the same amount and
 * reference until it is paid or cleared by hand from the Control panel.
 */
class WalletMonthlyPayout extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_OWED = 'owed';

    public const STATUS_CLEARED = 'cleared';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'month',
        'approved_total',
        'deposit_count',
        'percent',
        'coins',
        'reference',
        'status',
        'external_transaction_id',
        'balance_after',
        'error_code',
        'attempts',
        'last_attempt_at',
        'settled_at',
        'cleared_reference',
    ];

    protected $casts = [
        'approved_total' => 'decimal:2',
        'deposit_count' => 'integer',
        'percent' => 'decimal:6',
        'coins' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'attempts' => 'integer',
        'last_attempt_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    public function scopeOwed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OWED);
    }

    /** Paid, written off or nothing to bill — never billed again. */
    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_SETTLED, self::STATUS_CLEARED, self::STATUS_SKIPPED], true);
    }
}
