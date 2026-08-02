<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use App\Support\Push\AdminPushNotifier;

class Withdrawal extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'branch_id',
        'play_id',
        'amount',
        'destination_type',
        'upi_id',
        'account_number',
        'ifsc_code',
        'account_name',
        'status',
        'notes',
        'processed_by',
        'processed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'processed_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_ON_PROCESS = 'on_process';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';

    protected static function booted(): void
    {
        static::created(function (self $withdrawal): void {
            try {
                AdminPushNotifier::notifyWithdrawal($withdrawal);
            } catch (\Throwable $e) {
                Log::warning('Failed to send withdrawal push notification.', [
                    'withdrawal_id' => $withdrawal->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function processor()
    {
        return $this->belongsTo(Admin::class, 'processed_by');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
