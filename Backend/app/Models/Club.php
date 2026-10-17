<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An activity tier of one branch, e.g. "Diamond Club" for users active on at
 * least 21 days of the club window. The system assigns members (ClubAssigner);
 * admins only set the name, colour and day count.
 */
class Club extends Model
{
    protected $fillable = ['branch_id', 'name', 'color', 'min_active_days'];

    protected $casts = [
        'branch_id' => 'integer',
        'min_active_days' => 'integer',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(ClubMember::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
