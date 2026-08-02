<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportConversation extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'user_id',
        'branch_id',
        'status',
        'flagged',
        'awaiting_reply',
        'assigned_admin_id',
        'last_message_at',
        'last_message_preview',
        'last_auto_reply_at',
        'user_unread_count',
        'admin_unread_count',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'last_auto_reply_at' => 'datetime',
        'flagged' => 'boolean',
        'awaiting_reply' => 'boolean',
        'user_unread_count' => 'integer',
        'admin_unread_count' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'conversation_id');
    }
}
