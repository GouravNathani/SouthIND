<?php

namespace App\Support\Club;

use App\Models\Club;
use App\Models\ClubMember;
use App\Models\Deposit;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Puts every user in the right club, automatically.
 *
 * A user's "active days" = the number of distinct days (IST) in the last
 * `club_window_days` days (default 30, today included) on which one of their
 * deposits or withdrawals was approved. The user joins the branch club with the
 * highest `min_active_days` they reach; below the lowest club they are in no
 * club at all.
 *
 * Runs for everyone every night (clubs:refresh), for one branch when its clubs
 * change, and for one user right after a deposit/withdrawal decision.
 */
class ClubAssigner
{
    public const DEFAULT_WINDOW_DAYS = 30;

    public const MAX_WINDOW_DAYS = 365;

    /** The four clubs a new branch starts with: name, min active days, colour. */
    public const DEFAULTS = [
        ['Diamond Club', 21, '#0ea5e9'],
        ['Gold Club', 14, '#f59e0b'],
        ['Silver Club', 7, '#64748b'],
        ['Comeback Club', 3, '#10b981'],
    ];

    /** Days counted back from today (global, set by the super admin). */
    public static function windowDays(): int
    {
        try {
            if (! Schema::hasColumn('global_settings', 'club_window_days')) {
                return self::DEFAULT_WINDOW_DAYS;
            }
            $days = (int) DB::table('global_settings')->orderBy('id')->value('club_window_days');
        } catch (\Throwable) {
            return self::DEFAULT_WINDOW_DAYS;
        }

        return $days >= 1 ? min($days, self::MAX_WINDOW_DAYS) : self::DEFAULT_WINDOW_DAYS;
    }

    public static function setWindowDays(int $days): int
    {
        $days = max(1, min($days, self::MAX_WINDOW_DAYS));
        $id = DB::table('global_settings')->orderBy('id')->value('id');

        if ($id === null) {
            DB::table('global_settings')->insert([
                'club_window_days' => $days,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('global_settings')->where('id', $id)->update(['club_window_days' => $days, 'updated_at' => now()]);
        }

        return $days;
    }

    /** The first day (IST, 00:00) the window counts. */
    public static function windowStart(?int $days = null): Carbon
    {
        return Carbon::now()->startOfDay()->subDays(($days ?? self::windowDays()) - 1);
    }

    /**
     * Add the default clubs a branch is missing (matched by name). Returns how
     * many were created.
     */
    public function seedDefaults(int $branchId): int
    {
        $existing = Club::query()->where('branch_id', $branchId)->pluck('name')->map(fn ($name) => mb_strtolower($name))->all();
        $created = 0;

        foreach (self::DEFAULTS as [$name, $days, $color]) {
            if (in_array(mb_strtolower($name), $existing, true)) {
                continue;
            }
            // A custom club may already use this day count; keep theirs.
            if (Club::query()->where('branch_id', $branchId)->where('min_active_days', $days)->exists()) {
                continue;
            }
            Club::create(['branch_id' => $branchId, 'name' => $name, 'color' => $color, 'min_active_days' => $days]);
            $created++;
        }

        return $created;
    }

    /**
     * Every branch's users. Returns totals for the log.
     *
     * @return array{branches: int, users: int, members: int, removed: int}
     */
    public function refreshAll(): array
    {
        $totals = ['branches' => 0, 'users' => 0, 'members' => 0, 'removed' => 0];

        $branchIds = User::query()->whereNotNull('branch_id')->distinct()->pluck('branch_id');
        foreach ($branchIds as $branchId) {
            $result = $this->refreshBranch((int) $branchId);
            $totals['branches']++;
            $totals['users'] += $result['users'];
            $totals['members'] += $result['members'];
            $totals['removed'] += $result['removed'];
        }

        // Users who left every branch (or were deleted from one) lose their club.
        $totals['removed'] += ClubMember::query()
            ->whereNotIn('user_id', User::query()->whereNotNull('branch_id')->select('id'))
            ->delete();

        return $totals;
    }

    /**
     * One branch's users.
     *
     * @return array{users: int, members: int, removed: int}
     */
    public function refreshBranch(int $branchId): array
    {
        $clubs = $this->clubsOf($branchId);
        $userIds = User::query()->where('branch_id', $branchId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $days = $clubs->isEmpty() ? [] : $this->activeDays($branchId);

        $now = Carbon::now();
        $rows = [];
        foreach ($userIds as $userId) {
            $count = $days[$userId] ?? 0;
            $club = $this->clubFor($clubs, $count);
            if ($club) {
                $rows[] = [
                    'user_id' => $userId,
                    'club_id' => $club->id,
                    'branch_id' => $branchId,
                    'active_days' => $count,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        $removed = 0;
        DB::transaction(function () use ($rows, $userIds, $branchId, &$removed) {
            foreach (array_chunk($rows, 500) as $chunk) {
                ClubMember::query()->upsert($chunk, ['user_id'], ['club_id', 'branch_id', 'active_days', 'updated_at']);
            }

            $keep = array_column($rows, 'user_id');
            $drop = array_values(array_diff($userIds, $keep));
            foreach (array_chunk($drop, 500) as $chunk) {
                $removed += ClubMember::query()->whereIn('user_id', $chunk)->delete();
            }
            // Rows still tagged with this branch whose user moved elsewhere.
            $removed += ClubMember::query()
                ->where('branch_id', $branchId)
                ->whereNotIn('user_id', User::query()->where('branch_id', $branchId)->select('id'))
                ->delete();
        });

        return ['users' => count($userIds), 'members' => count($rows), 'removed' => $removed];
    }

    /** One user, e.g. right after a deposit was approved. */
    public function refreshUser(int $userId): ?ClubMember
    {
        $user = User::query()->find($userId);
        if (! $user || ! $user->branch_id) {
            ClubMember::query()->where('user_id', $userId)->delete();

            return null;
        }

        $branchId = (int) $user->branch_id;
        $clubs = $this->clubsOf($branchId);
        $count = $clubs->isEmpty() ? 0 : ($this->activeDays($branchId, $userId)[$userId] ?? 0);
        $club = $this->clubFor($clubs, $count);

        if (! $club) {
            ClubMember::query()->where('user_id', $userId)->delete();

            return null;
        }

        return ClubMember::query()->updateOrCreate(
            ['user_id' => $userId],
            ['club_id' => $club->id, 'branch_id' => $branchId, 'active_days' => $count],
        );
    }

    /**
     * Never throws: a club refresh must not break the deposit/withdrawal
     * decision that triggered it. The nightly run fixes anything missed.
     */
    public static function refreshUserQuietly($userId): void
    {
        if (! $userId) {
            return;
        }

        try {
            app(self::class)->refreshUser((int) $userId);
        } catch (\Throwable $e) {
            Log::warning('Club refresh for a user failed.', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Active days per user of a branch (or one user): distinct IST days in the
     * window with an approved deposit (approved_at) or approved withdrawal
     * (processed_at).
     *
     * @return array<int, int> user_id => days
     */
    public function activeDays(int $branchId, ?int $userId = null): array
    {
        $since = self::windowStart();

        // Distinct (user, day) pairs from each table — at most users × window
        // rows — merged here, so the SQL stays plain on MySQL and SQLite alike.
        $pairs = fn (string $table, string $column, string $status) => DB::table($table)
            ->join('users', 'users.id', '=', "{$table}.user_id")
            ->where('users.branch_id', $branchId)
            ->where("{$table}.status", $status)
            ->where("{$table}.{$column}", '>=', $since)
            ->when($userId, fn ($q) => $q->where("{$table}.user_id", $userId))
            ->selectRaw("{$table}.user_id as user_id, DATE({$table}.{$column}) as day")
            ->distinct()
            ->get();

        $days = [];
        foreach ([
            $pairs('deposits', 'approved_at', Deposit::STATUS_APPROVED),
            $pairs('withdrawals', 'processed_at', Withdrawal::STATUS_APPROVED),
        ] as $rows) {
            foreach ($rows as $row) {
                $days[(int) $row->user_id][(string) $row->day] = true;
            }
        }

        return array_map('count', $days);
    }

    /** @return \Illuminate\Support\Collection<int, Club> highest day count first */
    private function clubsOf(int $branchId)
    {
        return Club::query()
            ->where('branch_id', $branchId)
            ->where('min_active_days', '>=', 1)
            ->orderByDesc('min_active_days')
            ->get();
    }

    private function clubFor($clubs, int $days): ?Club
    {
        foreach ($clubs as $club) {
            if ($days >= $club->min_active_days) {
                return $club;
            }
        }

        return null;
    }
}
