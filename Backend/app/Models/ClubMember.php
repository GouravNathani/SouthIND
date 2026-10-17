<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which club a user is in right now (at most one), and the active-day count
 * that put them there. Written only by ClubAssigner.
 */
class ClubMember extends Model
{
    protected $table = 'club_user';

    protected $fillable = ['user_id', 'club_id', 'branch_id', 'active_days'];

    protected $casts = [
        'user_id' => 'integer',
        'club_id' => 'integer',
        'branch_id' => 'integer',
        'active_days' => 'integer',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
