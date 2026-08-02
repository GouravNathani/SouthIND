<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-branch, per-period configuration of the Winner Streak board.
 */
class WinnerStreakSetting extends Model
{
    public const PERIOD_DAILY = 'daily';
    public const PERIOD_WEEKLY = 'weekly';
    public const PERIOD_MONTHLY = 'monthly';

    public const PERIODS = [
        self::PERIOD_DAILY,
        self::PERIOD_WEEKLY,
        self::PERIOD_MONTHLY,
    ];

    protected $fillable = [
        'branch_id',
        'period',
        'enabled',
        'tag_id',
        'exclude_tag_id',
        'top_n',
        'loss_board_enabled',
        'loss_board_public',
        'min_turnover',
        'min_transactions',
        'reset_time',
        'reset_weekday',
        'reset_day_of_month',
        'last_reset_at',
        'next_reset_at',
        'show_name',
        'show_play_id',
        'show_phone',
        'show_amount',
        'show_profit_loss',
        'show_reward',
        'mask_amount_bucket',
        'mask_name',
        'reward_amount',
        'reward_label',
        'announce_push',
        'winner_cooldown_cycles',
        'quiet_hours_enabled',
        'quiet_from',
        'quiet_to',
        'updated_by',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'enabled' => 'boolean',
        'tag_id' => 'integer',
        'exclude_tag_id' => 'integer',
        'top_n' => 'integer',
        'loss_board_enabled' => 'boolean',
        'loss_board_public' => 'boolean',
        'min_turnover' => 'decimal:2',
        'min_transactions' => 'integer',
        'reset_weekday' => 'integer',
        'reset_day_of_month' => 'integer',
        'last_reset_at' => 'datetime',
        'next_reset_at' => 'datetime',
        'show_name' => 'boolean',
        'show_play_id' => 'boolean',
        'show_phone' => 'boolean',
        'show_amount' => 'boolean',
        'show_profit_loss' => 'boolean',
        'show_reward' => 'boolean',
        'mask_amount_bucket' => 'boolean',
        'mask_name' => 'boolean',
        'reward_amount' => 'decimal:2',
        'announce_push' => 'boolean',
        'winner_cooldown_cycles' => 'integer',
        'quiet_hours_enabled' => 'boolean',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function tag()
    {
        return $this->belongsTo(Tag::class);
    }

    public function excludeTag()
    {
        return $this->belongsTo(Tag::class, 'exclude_tag_id');
    }

    public function cycles()
    {
        return $this->hasMany(WinnerStreakCycle::class, 'branch_id', 'branch_id')
            ->where('period', $this->period);
    }

    /**
     * Config for a branch/period, created with defaults on first touch.
     */
    public static function forBranch(int $branchId, string $period): self
    {
        $settings = static::query()->firstOrCreate(
            ['branch_id' => $branchId, 'period' => $period],
            []
        );

        // Every default lives in the migration, and a freshly inserted model
        // carries only the attributes we passed in — without this re-read,
        // top_n / reset_time / the display flags are all null on first touch.
        if ($settings->wasRecentlyCreated) {
            $settings->refresh();
        }

        return $settings;
    }

    public static function isValidPeriod(?string $period): bool
    {
        return in_array($period, self::PERIODS, true);
    }
}
