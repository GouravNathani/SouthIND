<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One line of the commission ledger. Append-only — see the migration for why
 * a reversed deposit is flagged rather than automatically clawed back.
 */
class CommissionEntry extends Model
{
    /** Earned off an approved deposit. Written by the system. */
    public const TYPE_ACCRUAL = 'accrual';
    /** Manual correction by an admin. Signed either way. */
    public const TYPE_ADJUSTMENT = 'adjustment';
    /** Money leaving the account for a payout. Always negative. */
    public const TYPE_PAYOUT = 'payout';
    /** Discretionary credit (joining bonus, milestone). Always positive. */
    public const TYPE_BONUS = 'bonus';

    public const TYPES = [
        self::TYPE_ACCRUAL,
        self::TYPE_ADJUSTMENT,
        self::TYPE_PAYOUT,
        self::TYPE_BONUS,
    ];

    /** Inside the holding window — counted, not yet spendable. */
    public const STATUS_PENDING = 'pending';
    /** Holding window elapsed — part of the available balance. */
    public const STATUS_AVAILABLE = 'available';
    /** Settled out through a payout. */
    public const STATUS_PAID = 'paid';
    /** Cancelled before it ever counted (e.g. a rejected payout hold). */
    public const STATUS_VOID = 'void';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_AVAILABLE,
        self::STATUS_PAID,
        self::STATUS_VOID,
    ];

    protected $fillable = [
        'agent_id',
        'branch_id',
        'type',
        'status',
        'from_user_id',
        'deposit_id',
        'payout_id',
        'level',
        'base_amount',
        'percent',
        'tier_label',
        'amount',
        'available_at',
        'released_at',
        'deposit_status_at_accrual',
        'deposit_status_now',
        'flagged_at',
        'flag_reason',
        'resolved_at',
        'resolved_by',
        'from_display_name',
        'from_display_play_id',
        'note',
        'created_by',
        'meta',
    ];

    protected $casts = [
        'agent_id' => 'integer',
        'branch_id' => 'integer',
        'from_user_id' => 'integer',
        'deposit_id' => 'integer',
        'payout_id' => 'integer',
        'level' => 'integer',
        'base_amount' => 'decimal:2',
        'percent' => 'decimal:3',
        'amount' => 'decimal:2',
        'available_at' => 'datetime',
        'released_at' => 'datetime',
        'flagged_at' => 'datetime',
        'resolved_at' => 'datetime',
        'meta' => 'array',
    ];

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function fromUser()
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function deposit()
    {
        return $this->belongsTo(Deposit::class);
    }

    public function payout()
    {
        return $this->belongsTo(CommissionPayout::class, 'payout_id');
    }

    /**
     * Flagged and not yet dealt with by an admin — the review queue.
     */
    public function scopeNeedsReview($query)
    {
        return $query->whereNotNull('flagged_at')->whereNull('resolved_at');
    }

    public function scopeCounting($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_AVAILABLE, self::STATUS_PAID]);
    }
}
