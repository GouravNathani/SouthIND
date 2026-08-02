<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'domain',
        'is_active',
        'min_deposit_amount',
        'min_withdrawal_amount',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'min_deposit_amount' => 'integer',
        'min_withdrawal_amount' => 'integer',
    ];

    public function creator()
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function admins()
    {
        return $this->hasMany(Admin::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function accounts()
    {
        return $this->hasMany(Account::class);
    }

    public function banners()
    {
        return $this->hasMany(Banner::class);
    }

    public function appSetting()
    {
        return $this->hasOne(AppSetting::class);
    }

    /**
     * Referral / agent programme configuration. Absent until the programme is
     * first touched for this branch — treat a missing row as "off".
     */
    public function referralSetting()
    {
        return $this->hasOne(ReferralSetting::class);
    }
}
