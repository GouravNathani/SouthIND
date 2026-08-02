<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Immutable trail of every referral attribution change. Written, never edited.
 */
class ReferralAudit extends Model
{
    public const ACTION_ATTACH = 'attach';
    public const ACTION_DETACH = 'detach';
    public const ACTION_REATTACH = 'reattach';
    public const ACTION_PROMOTE = 'promote';
    public const ACTION_DEMOTE = 'demote';
    public const ACTION_SUSPEND = 'suspend';
    public const ACTION_RESUME = 'resume';
    public const ACTION_ADJUST = 'adjust';
    /** An admin paid an agent ahead of their balance. */
    public const ACTION_ADVANCE = 'advance';
    public const ACTION_SETTINGS = 'settings';

    protected $fillable = [
        'branch_id',
        'user_id',
        'action',
        'old_referrer_id',
        'new_referrer_id',
        'actor_type',
        'actor_id',
        'reason',
        'meta',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'user_id' => 'integer',
        'old_referrer_id' => 'integer',
        'new_referrer_id' => 'integer',
        'actor_id' => 'integer',
        'meta' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function actor()
    {
        return $this->belongsTo(Admin::class, 'actor_id');
    }

    /**
     * Convenience writer — every call site wants the same shape.
     */
    public static function record(
        int $branchId,
        int $userId,
        string $action,
        ?int $oldReferrerId = null,
        ?int $newReferrerId = null,
        string $actorType = 'admin',
        ?int $actorId = null,
        ?string $reason = null,
        array $meta = [],
    ): self {
        return static::create([
            'branch_id' => $branchId,
            'user_id' => $userId,
            'action' => $action,
            'old_referrer_id' => $oldReferrerId,
            'new_referrer_id' => $newReferrerId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'reason' => $reason,
            'meta' => $meta ?: null,
        ]);
    }
}
