<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GlobalSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'instagram_link',
        'telegram_link',
        'whatsapp_link',
        'mask_user_phone',
        'user_panel_maintenance_enabled',
        'bonus_deposit_enabled',
        'created_by',
    ];

    protected $casts = [
        'mask_user_phone' => 'boolean',
        'user_panel_maintenance_enabled' => 'boolean',
        'bonus_deposit_enabled' => 'boolean',
    ];

    public function creator()
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }
}
