<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\Deposit;
use App\Models\User;
use App\Models\WinnerStreakSetting;
use App\Models\Withdrawal;
use App\Support\WinnerStreak\WinnerStreakCycleManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * LOCAL AND QA ONLY. Winner Streak demo data.
 *
 * DatabaseSeeder hands every player one approved deposit and a mostly-pending
 * withdrawal, so everyone finishes net-negative and the profit board stays
 * empty however many cycles close. This seeder supplies the missing half:
 * settled play across the last few weeks in which some players finish ahead.
 *
 * It writes transactions, never entries. Closing the cycles is left to the same
 * WinnerStreakCycleManager the scheduler uses, so the frozen boards are derived
 * from real deposits and withdrawals — the admin P/L screen and the user ribbon
 * agree because they are reading the same rows.
 *
 * Deterministic (no faker) and idempotent: a second run adds no duplicate
 * transactions and re-closes nothing.
 */
class WinnerStreakSeeder extends Seeder
{
    /** How far back to lay down settled play. */
    private const DAYS = 35;

    /** Reward advertised per board, in rupees. */
    private const REWARDS = ['daily' => 500, 'weekly' => 2000, 'monthly' => 5000];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('WinnerStreakSeeder is demo data and must not run in production.');

            return;
        }

        foreach (Branch::query()->with('users')->get() as $branch) {
            $admin = Admin::query()->where('branch_id', $branch->id)->first();
            $account = Account::query()->where('branch_id', $branch->id)->first();

            // Test accounts are what `exclude_tag_id` exists for; keep them off
            // the podium here too.
            $players = $branch->users
                ->where('status', User::STATUS_ACTIVE)
                ->reject(fn (User $u) => str_starts_with((string) $u->play_id, 'TEST'))
                ->values();

            if (!$admin || !$account || $players->isEmpty()) {
                $this->command?->warn(
                    "Winner Streak: skipping branch {$branch->code} (needs an admin, a deposit account and at least one player)."
                );

                continue;
            }

            $this->enableBoards($branch, $admin->id);
            $this->seedSettledPlay($branch, $admin->id, $account->id, $players);
        }

        // Close every window the seeded play covers through the production path.
        $result = app(WinnerStreakCycleManager::class)->tick();

        $this->command?->info(
            "Winner Streak: closed {$result['closed']} cycles, opened {$result['opened']}."
        );
    }

    private function enableBoards(Branch $branch, int $adminId): void
    {
        foreach (WinnerStreakSetting::PERIODS as $period) {
            WinnerStreakSetting::query()->updateOrCreate(
                ['branch_id' => $branch->id, 'period' => $period],
                [
                    'enabled' => true,
                    'top_n' => 3,
                    'reward_amount' => self::REWARDS[$period] ?? 0,
                    'reward_label' => 'Cash reward',
                    'loss_board_enabled' => true,
                    // Naming the biggest loser stays an explicit admin choice.
                    'loss_board_public' => false,
                    // A backfill closes a month of cycles at once; announcing
                    // each one would buzz the whole branch in a burst. Turn it
                    // on from the admin panel once the history is in place.
                    'announce_push' => false,
                    'updated_by' => $adminId,
                ],
            );
        }
    }

    /**
     * @param \Illuminate\Support\Collection<int, User> $players
     */
    private function seedSettledPlay(Branch $branch, int $adminId, int $accountId, $players): void
    {
        $marker = 'WS' . $branch->id . '-';

        // Day 0 included so the LIVE board has participants too — the admin
        // panel only renders the open cycle, and an empty one reads as broken.
        foreach (range(self::DAYS, 0) as $daysAgo) {
            foreach ($players as $index => $player) {
                // Nobody plays every single day.
                if ((($daysAgo + $index) % 3) === 0) {
                    continue;
                }

                $deposit = 2000 + (((($daysAgo * 7) + ($index * 13)) % 9) * 1000);
                // -4..+8 thousand, so the board carries genuine winners and
                // losers rather than a table where everyone is up.
                $swing = (((($daysAgo * 5) + ($index * 11)) % 13) - 4) * 1000;
                $withdrawal = max(500, $deposit + $swing);

                $at = $this->playedAt($daysAgo, $index);
                if (!$at) {
                    continue;
                }

                $reference = $marker . $daysAgo . '-' . $index;

                Deposit::query()->firstOrCreate(['utr_number' => $reference], [
                    'user_id' => $player->id,
                    'account_id' => $accountId,
                    'branch_id' => $branch->id,
                    'play_id' => $player->play_id,
                    'amount' => $deposit,
                    'status' => Deposit::STATUS_APPROVED,
                    'approved_by' => $adminId,
                    'approved_at' => $at,
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);

                $settled = $this->settledAt($at);

                Withdrawal::query()->firstOrCreate(
                    ['user_id' => $player->id, 'created_at' => $settled],
                    [
                        'branch_id' => $branch->id,
                        'play_id' => $player->play_id,
                        'amount' => $withdrawal,
                        'destination_type' => 'upi',
                        'upi_id' => strtolower(str_replace(' ', '', (string) $player->name)) . '@okhdfcbank',
                        'status' => Withdrawal::STATUS_APPROVED,
                        'processed_by' => $adminId,
                        'processed_at' => $settled,
                        'updated_at' => $settled,
                    ],
                );
            }
        }
    }

    /**
     * When a given day's play happened. Always in the past: today's slot is
     * placed inside the hours that have actually elapsed, and returns null when
     * the day is too young to have any play in it yet.
     */
    private function playedAt(int $daysAgo, int $index): ?Carbon
    {
        if ($daysAgo > 0) {
            return Carbon::today()
                ->subDays($daysAgo)
                ->setTime(11 + ($index % 8), (($daysAgo * 7) % 60));
        }

        $elapsed = (int) Carbon::today()->diffInMinutes(Carbon::now());

        // Under an hour into the day there is no room to place a deposit and a
        // settled withdrawal behind us; leave today's board empty until there is.
        if ($elapsed < 60) {
            return null;
        }

        return Carbon::today()->addMinutes((int) round($elapsed * 0.3));
    }

    /**
     * A withdrawal settles after its deposit, but never in the future and never
     * past midnight — crossing the boundary would file it under the next cycle.
     */
    private function settledAt(Carbon $at): Carbon
    {
        $settled = $at->copy()->addMinutes(90);
        $endOfDay = $at->copy()->endOfDay();
        $cutoff = Carbon::now()->subMinutes(5);

        return $settled->greaterThan($endOfDay) ? $endOfDay
            : ($settled->greaterThan($cutoff) ? $cutoff : $settled);
    }
}
