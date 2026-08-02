<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppConversation extends Model
{
    protected $table = 'whatsapp_conversations';

    protected $fillable = [
        'whatsapp_account_id',
        'contact_phone',
        'contact_name',
        'last_message_at',
        'last_message_preview',
        'last_inbound_at',
        'unread_count',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'last_inbound_at' => 'datetime',
        'unread_count' => 'integer',
    ];

    public function account()
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }

    public function messages()
    {
        return $this->hasMany(WhatsAppMessage::class, 'conversation_id');
    }
}
