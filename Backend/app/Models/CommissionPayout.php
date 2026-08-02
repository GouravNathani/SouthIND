<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An agent's request to take money out of their Commission Account.
 */
class CommissionPayout extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    public const METHOD_UPI = 'upi';
    public const METHOD_BANK = 'bank';
    /** Settle into the game balance instead of cash. */
    public const METHOD_PLAY = 'play';

    public const METHODS = [
        self::METHOD_UPI,
        self::METHOD_BANK,
        self::METHOD_PLAY,
    ];

    protected $fillable = [
        'agent_id',
        'branch_id',
        'amount',
        'method',
        'upi_id',
        'account_number',
        'ifsc_code',
        'account_name',
        'status',
        'notes',
        'processed_by',
        'processed_at',
    ];

    protected $casts = [
        'agent_id' => 'integer',
        'branch_id' => 'integer',
        'amount' => 'decimal:2',
        'processed_at' => 'datetime',
    ];

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function entries()
    {
        return $this->hasMany(CommissionEntry::class, 'payout_id');
    }
}
