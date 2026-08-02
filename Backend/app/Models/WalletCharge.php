<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One chargeable message (support/whatsapp, in/out) and the result of deducting
 * its cost from the external wallet.
 */
class WalletCharge extends Model
{
    public const CHANNEL_SUPPORT = 'support';
    public const CHANNEL_WHATSAPP = 'whatsapp';
    /** A support message carrying media (image/voice) — billed at the media rate. */
    public const CHANNEL_MEDIA = 'media';

    public const DIRECTION_IN = 'in';
    public const DIRECTION_OUT = 'out';

    public const STATUS_SETTLED = 'settled';
    public const STATUS_UNSETTLED = 'unsettled';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_VOIDED = 'voided';

    /** Rate read from Control at charge time. */
    public const SOURCE_LIVE = 'live';

    /** Rate from the last-known cached read of Control. */
    public const SOURCE_STALE = 'stale';

    /** Rate from config defaults — Control had never answered (self wallet). */
    public const SOURCE_SELF = 'self';

    protected $fillable = [
        'channel',
        'direction',
        'cost_field',
        'cost_value',
        'rate_source',
        'coins',
        'reference',
        'status',
        'external_transaction_id',
        'balance_after',
        'error_code',
        'reference_type',
        'reference_id',
        'voided_at',
        'meta',
    ];

    protected $casts = [
        'cost_value' => 'decimal:2',
        'coins' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'voided_at' => 'datetime',
        'meta' => 'array',
    ];
}
