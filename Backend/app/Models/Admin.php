<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'phone',
        'password',
        'must_change_password',
        'role',
        'is_active',
        'allow_profit_view',
        'unique_number',
        'domain',
        'parent_id',
        'branch_id',
        'last_active_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'allow_profit_view' => 'boolean',
        'password' => 'hashed',
        'must_change_password' => 'boolean',
        'last_login_at' => 'datetime',
        'last_active_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Admin $admin): void {
            if (blank($admin->unique_number)) {
                $admin->unique_number = self::generateUniqueNumber();
            }

            if ($admin->role === 'staff' && blank($admin->branch_id) && $admin->parent_id) {
                $admin->branch_id = optional($admin->parent)->branch_id;
            }
        });
    }

    protected static function generateUniqueNumber(): string
    {
        do {
            $value = 'ADM-' . Str::upper(Str::random(10));
        } while (static::where('unique_number', $value)->exists());

        return $value;
    }


    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function resolveBranchId(): ?int
    {
        if ($this->branch_id) {
            return $this->branch_id;
        }

        if ($this->role === 'staff' && $this->parent_id) {
            return optional($this->parent)->branch_id;
        }

        return null;
    }

    public function staff()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

}
