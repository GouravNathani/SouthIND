<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Str;
use App\Models\Branch;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_BANNED = 'banned';
    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_BANNED,
    ];

    /** A plain player. */
    public const TYPE_USER = 'user';
    /** Earns commission on the approved deposits of the users they referred. */
    public const TYPE_AGENT = 'agent';

    public const TYPES = [
        self::TYPE_USER,
        self::TYPE_AGENT,
    ];

    public const AGENT_ACTIVE = 'active';
    /** Keeps the account and its ledger, but stops accrual and payouts. */
    public const AGENT_SUSPENDED = 'suspended';

    public const AGENT_STATUSES = [
        self::AGENT_ACTIVE,
        self::AGENT_SUSPENDED,
    ];

    /**
     * Number of consecutive wrong MPIN attempts before the account is locked.
     */
    public const MPIN_MAX_ATTEMPTS = 20;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'unique_number',
        'play_id',
        'mpin',
        'mpin_failed_attempts',
        'mpin_locked_at',
        'status',
        'branch_id',
        'user_type',
        'agent_status',
        'referral_code',
        'referred_by',
        'referred_at',
        'referral_source',
        'commission_from',
        'commission_percent_override',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'mpin_locked_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'referred_at' => 'datetime',
            'commission_from' => 'datetime',
            'commission_percent_override' => 'decimal:3',
        ];
    }

    /**
     * Whether the account is currently locked out of MPIN authentication.
     */
    public function isMpinLocked(): bool
    {
        return !is_null($this->mpin_locked_at);
    }

    /**
     * Record a wrong MPIN attempt, locking the account once the threshold is hit.
     */
    public function registerFailedMpin(): void
    {
        $attempts = (int) $this->mpin_failed_attempts + 1;
        $this->mpin_failed_attempts = $attempts;
        if ($attempts >= self::MPIN_MAX_ATTEMPTS && is_null($this->mpin_locked_at)) {
            $this->mpin_locked_at = now();
        }
        $this->save();
    }

    /**
     * Clear the failed-attempt counter and unlock the account (on success or an
     * admin PIN regeneration).
     */
    public function clearMpinLock(): void
    {
        if ((int) $this->mpin_failed_attempts === 0 && is_null($this->mpin_locked_at)) {
            return;
        }
        $this->mpin_failed_attempts = 0;
        $this->mpin_locked_at = null;
        $this->save();
    }

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (blank($user->unique_number)) {
                $user->unique_number = self::generateUniqueNumber($user->branch_id);
            }

            if (blank($user->status)) {
                $user->status = self::STATUS_ACTIVE;
            }
        });
    }

    protected static function generateUniqueNumber(?int $branchId = null): string
    {
        if ($branchId) {
            $branch = Branch::query()->find($branchId);
            $branchCode = $branch?->code ?? '';
            $normalized = Str::upper(preg_replace('/[^A-Za-z0-9]+/', '', (string) $branchCode));
            $prefix = $normalized !== '' ? substr($normalized, 0, 10) : '';

            if ($prefix !== '') {
                $max = 0;
                $pattern = '/^' . preg_quote($prefix, '/') . '-(\\d+)$/';
                $existing = static::query()
                    ->where('branch_id', $branchId)
                    ->where('unique_number', 'like', $prefix . '-%')
                    ->pluck('unique_number');

                foreach ($existing as $value) {
                    if (!is_string($value)) {
                        continue;
                    }
                    if (preg_match($pattern, $value, $matches)) {
                        $num = (int) $matches[1];
                        if ($num > $max) {
                            $max = $num;
                        }
                    }
                }

                $next = $max + 1;
                do {
                    $candidate = sprintf('%s-%06d', $prefix, $next);
                    $next++;
                } while (static::where('unique_number', $candidate)->exists());

                return $candidate;
            }
        }

        do {
            $value = 'USR-' . Str::upper(Str::random(12));
        } while (static::where('unique_number', $value)->exists());

        return $value;
    }

    public static function generateMpin(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function deposits()
    {
        return $this->hasMany(Deposit::class);
    }

    public function withdrawals()
    {
        return $this->hasMany(Withdrawal::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'tag_user');
    }

    // ---- Referral -------------------------------------------------------

    /** The agent this user was referred by. Always in the same branch. */
    public function referrer()
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    /** Users this agent brought in — their direct team. */
    public function referrals()
    {
        return $this->hasMany(User::class, 'referred_by');
    }

    public function commissionAccount()
    {
        return $this->hasOne(CommissionAccount::class);
    }

    public function commissionEntries()
    {
        return $this->hasMany(CommissionEntry::class, 'agent_id');
    }

    public function commissionPayouts()
    {
        return $this->hasMany(CommissionPayout::class, 'agent_id');
    }

    public function isAgent(): bool
    {
        return $this->user_type === self::TYPE_AGENT;
    }

    /**
     * An agent who is allowed to earn right now — suspended agents keep their
     * balance and history but accrue nothing.
     */
    public function isEarningAgent(): bool
    {
        return $this->isAgent()
            && $this->agent_status !== self::AGENT_SUSPENDED
            && $this->status === self::STATUS_ACTIVE;
    }
}
