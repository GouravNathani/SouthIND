<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupportMessage extends Model
{
    use SoftDeletes;

    public const SENDER_USER = 'user';
    public const SENDER_ADMIN = 'admin';

    /** Users only see the last week of messages; the rest auto-hides from their side. */
    public const USER_VISIBLE_DAYS = 7;

    /**
     * How long after sending a support message it stays editable / deletable by
     * staff. Admin has the base window; SuperAdmin gets double.
     */
    public const ADMIN_EDIT_WINDOW_HOURS = 6;
    public const ADMIN_DELETE_WINDOW_HOURS = 12;
    public const SUPER_EDIT_WINDOW_HOURS = 12;
    public const SUPER_DELETE_WINDOW_HOURS = 24;

    /** Messages (and their media) are purged everywhere after two weeks. */
    public const RETENTION_DAYS = 14;

    protected $fillable = [
        'conversation_id',
        'sender_type',
        'sender_id',
        'body',
        'image_path',
        'audio_path',
        'read_at',
        'edited_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'edited_at' => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(SupportConversation::class, 'conversation_id');
    }
}
