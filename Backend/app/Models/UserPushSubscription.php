<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPushSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'endpoint',
        'public_key',
        'auth_token',
        'content_encoding',
        'user_agent',
        'branch_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
