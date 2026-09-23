<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One status change of a payment account — append-only history behind the
 * "latest change" columns on the account row.
 */
class AccountStatusEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'account_id',
        'from_status',
        'to_status',
        'reason',
        'actor_id',
        'actor_role',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function actor()
    {
        return $this->belongsTo(Admin::class, 'actor_id');
    }
}
