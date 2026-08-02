<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One competition window (a day / week / month) for a branch.
 */
class WinnerStreakCycle extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'branch_id',
        'period',
        'label',
        'starts_at',
        'ends_at',
        'closed_at',
        'status',
        'tag_id',
        'top_n',
        'participants_count',
        'announced',
        'closed_by',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'closed_at' => 'datetime',
        'tag_id' => 'integer',
        'top_n' => 'integer',
        'participants_count' => 'integer',
        'announced' => 'boolean',
        'closed_by' => 'integer',
    ];

    public function entries()
    {
        return $this->hasMany(WinnerStreakEntry::class, 'cycle_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeClosed($query)
    {
        return $query->where('status', self::STATUS_CLOSED);
    }
}
