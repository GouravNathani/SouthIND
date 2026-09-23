<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Account extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'type',
        'upi_id',
        'account_number',
        'ifsc_code',
        'holder_name',
        'used_for',
        'logo_path',
        'notes',
        'status',
        'created_by',
        'owner_admin_id',
        'deposit_limit',
        'min_deposit',
        'max_deposit',
        'branch_id',
    ];

    protected $casts = [
        'deposit_limit' => 'decimal:2',
        'min_deposit' => 'decimal:2',
        'max_deposit' => 'decimal:2',
        'status_changed_at' => 'datetime',
        'status_meta' => 'array',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForUse($query, string $usage)
    {
        return $query->whereIn('used_for', [$usage, 'both']);
    }

    public function scopeOwnedBy($query, Admin $admin)
    {
        return $query->where('owner_admin_id', $admin->id);
    }

    public function scopeForBranch($query, int $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function creator()
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function owner()
    {
        return $this->belongsTo(Admin::class, 'owner_admin_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function deposits()
    {
        return $this->hasMany(Deposit::class);
    }

    /** Who made the latest status change (null when the system did it). */
    public function statusChangedBy()
    {
        return $this->belongsTo(Admin::class, 'status_changed_by_id');
    }

    public function statusEvents()
    {
        return $this->hasMany(AccountStatusEvent::class);
    }
}
