<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'deposit_offer_text',
        'withdrawal_offer_text',
        'instagram_link',
        'whatsapp_number',
        'deposit_wa',
        'withdrawal_wa',
        'whatsapp_link',
        'telegram_link',
        'logo_path',
        'owner_admin_id',
        'created_by',
        'branch_id',
        'support_auto_reply_enabled',
        'support_auto_reply_text',
        'bonus_deposit_enabled',
    ];

    protected $casts = [
        'owner_admin_id' => 'integer',
        'created_by' => 'integer',
        'branch_id' => 'integer',
        'support_auto_reply_enabled' => 'boolean',
        'bonus_deposit_enabled' => 'boolean',
    ];

    public function owner()
    {
        return $this->belongsTo(Admin::class, 'owner_admin_id');
    }

    public function creator()
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
