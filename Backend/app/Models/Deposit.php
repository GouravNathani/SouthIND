<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use App\Support\Push\AdminPushNotifier;

class Deposit extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'account_id',
        'branch_id',
        'play_id',
        'amount',
        'utr_number',
        'bonus_code_id',
        'status',
        'notes',
        'receipt_image_path',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_ON_PROCESS = 'on_process';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';

    protected static function booted(): void
    {
        static::created(function (self $deposit): void {
            try {
                AdminPushNotifier::notifyDeposit($deposit);
            } catch (\Throwable $e) {
                Log::warning('Failed to send deposit push notification.', [
                    'deposit_id' => $deposit->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function account()
    {
        // Accounts are soft-deleted, so keep trashed ones attached — otherwise
        // historic deposits lose their account name/number in admin views.
        return $this->belongsTo(Account::class)->withTrashed();
    }

    public function approver()
    {
        return $this->belongsTo(Admin::class, 'approved_by');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function bonusCode()
    {
        return $this->belongsTo(BonusCode::class);
    }

    /**
     * The redemption created when the user applied a code on this deposit.
     */
    public function bonusRedemption()
    {
        return $this->hasOne(BonusCodeRedemption::class);
    }
}
