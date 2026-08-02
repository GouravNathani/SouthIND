<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppTemplate extends Model
{
    protected $table = 'whatsapp_templates';

    protected $fillable = [
        'whatsapp_account_id',
        'name',
        'language',
        'category',
        'status',
        'components',
        'synced_at',
    ];

    protected $casts = [
        'components' => 'array',
        'synced_at' => 'datetime',
    ];

    public function account()
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }
}
