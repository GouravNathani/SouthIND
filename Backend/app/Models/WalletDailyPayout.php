<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One day's message charges, billed to the external wallet as a SINGLE
 * deduction at 03:00 the next morning.
 *
 * Messages themselves never call the wallet API — they only write WalletCharge
 * rows. This table is where a whole day of those rows is rolled up, paid for,
 * and (when the payment fails) tracked as owed until it is either retried
 * successfully or cleared by hand from the Control panel.
 */
class WalletDailyPayout extends Model
{
    /** Rolled up, not yet attempted (or being retried right now). */
    public const STATUS_PENDING = 'pending';

    /** Deducted from the wallet — the day is paid for. */
    public const STATUS_SETTLED = 'settled';

    /** The deduction failed (API down, insufficient funds). Retried nightly. */
    public const STATUS_OWED = 'owed';

    /** An operator collected this by hand from the Control panel. */
    public const STATUS_CLEARED = 'cleared';

    /** Nothing chargeable left for that day (e.g. every message was deleted). */
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'payout_date',
        'coins',
        'charge_count',
        'breakdown',
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
        'payout_date' => 'date',
        'coins' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'charge_count' => 'integer',
        'attempts' => 'integer',
        'breakdown' => 'array',
        'last_attempt_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    /** Days Control could not collect — this is what "owed" means. */
    public function scopeOwed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OWED);
    }

    public function isOwed(): bool
    {
        return $this->status === self::STATUS_OWED;
    }
}
