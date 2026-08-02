<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppAccount extends Model
{
    protected $table = 'whatsapp_accounts';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'display_name',
        'waba_id',
        'phone_number_id',
        'access_token',
        'phone_number',
        'webhook_verify_token',
        'status',
        'created_by',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
    ];

    protected $hidden = [
        'access_token',
    ];

    public function templates()
    {
        return $this->hasMany(WhatsAppTemplate::class, 'whatsapp_account_id');
    }

    public function conversations()
    {
        return $this->hasMany(WhatsAppConversation::class, 'whatsapp_account_id');
    }

    public function messages()
    {
        return $this->hasMany(WhatsAppMessage::class, 'whatsapp_account_id');
    }
}
