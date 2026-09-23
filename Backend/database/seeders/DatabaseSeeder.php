<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Admin;
use App\Models\AppSetting;
use App\Models\Branch;
use App\Models\Deposit;
use App\Models\GlobalSetting;
use App\Models\Tag;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * LOCAL AND QA ONLY. Never run this against production — use ProductionSeeder.
 *
 * Builds two branches with enough real-shaped data to exercise every screen:
 * admins, staff, users, deposit and payout accounts, tags, and a spread of
 * transactions across every status so the admin queues, the history page, the
 * dashboard aggregates and the winner-streak calculations all have something to
 * show.
 *
 * Everything is deterministic — no faker, no random — so a screenshot taken
 * today matches one taken next week and a failing test is reproducible.
 *
 *   php artisan migrate:fresh --seed
 *
 * Every account uses an obviously fake password. That is deliberate: if this
 * ever runs somewhere it should not, the accounts it creates are useless rather
 * than a working backdoor.
 */
class DatabaseSeeder extends Seeder
{
    private const PASSWORD = 'secret123';
    // Six digits — UserMpinLoginRequest enforces digits:6.
    private const MPIN = '123456';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DatabaseSeeder is demo data. Use --class=ProductionSeeder instead.');

            return;
        }

        $super = Admin::create([
            'name' => 'Super Admin',
            'phone' => '+919999999999',
            'email' => 'super@southind.local',
            'password' => self::PASSWORD,
            'role' => 'super_admin',
            'is_active' => true,
            'domain' => 'main.southind',
        ]);

        GlobalSetting::create(['created_by' => $super->id]);

        $chennai = $this->branch('Chennai', 'CHN', $super);
        $madurai = $this->branch('Madurai', 'MDU', $super);

        $this->seedBranch($chennai, 'chennai', [
            ['Ravi Kumar', '+919000000001'],
            ['Meenakshi S', '+919000000002'],
            ['Karthik R', '+919000000003'],
        ]);

        $this->seedBranch($madurai, 'madurai', [
            ['Arjun P', '+919100000001'],
            ['Divya N', '+919100000002'],
        ]);

        // Settled play + closed Winner Streak cycles. Split out because it has
        // to run after every branch has its players and payout account.
        $this->call(WinnerStreakSeeder::class);

        $this->command?->info('Seeded 2 branches. Admin/staff password: '.self::PASSWORD.' · user MPIN: '.self::MPIN);
    }

    private function branch(string $name, string $code, Admin $super): Branch
    {
        return Branch::create([
            'name' => $name.' Branch',
            'code' => $code,
            'domain' => strtolower($code).'.southind',
            'is_active' => true,
            'min_deposit_amount' => 500,
            'min_withdrawal_amount' => 1000,
            'created_by' => $super->id,
        ]);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $people
     */
    private function seedBranch(Branch $branch, string $slug, array $people): void
    {
        $admin = Admin::create([
            'name' => ucfirst($slug).' Admin',
            'phone' => '+9188'.substr(md5($slug), 0, 8),
            'email' => $slug.'-admin@southind.local',
            'password' => self::PASSWORD,
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
            'domain' => $branch->domain,
        ]);

        // Staff inherit their branch from the parent admin — see Admin::booted().
        Admin::create([
            'name' => ucfirst($slug).' Staff',
            'phone' => '+9177'.substr(md5($slug), 0, 8),
            'email' => $slug.'-staff@southind.local',
            'password' => self::PASSWORD,
            'role' => 'staff',
            'is_active' => true,
            'parent_id' => $admin->id,
        ]);

        AppSetting::create([
            'branch_id' => $branch->id,
            'owner_admin_id' => $admin->id,
            'created_by' => $admin->id,
            'deposit_offer_text' => 'Upto 10% Extra',
            'withdrawal_offer_text' => 'Upto 5% Extra',
            'whatsapp_number' => '+919000000000',
        ]);

        $vip = Tag::create(['branch_id' => $branch->id, 'name' => 'VIP', 'color' => '#d4af37']);
        Tag::create(['branch_id' => $branch->id, 'name' => 'New', 'color' => '#3b82f6']);

        // One deposit account WITH limits and one without, plus a payout
        // account — enough to exercise AccountLimitEnforcer and the picker.
        $upi = Account::create([
            'name' => ucfirst($slug).' UPI',
            'type' => 'upi',
            'used_for' => 'deposit',
            'upi_id' => $slug.'@okaxis',
            'holder_name' => ucfirst($slug).' Payments',
            'status' => 'active',
            'branch_id' => $branch->id,
            'created_by' => $admin->id,
            'owner_admin_id' => $admin->id,
            'min_deposit' => 500,
            'max_deposit' => 50000,
            'deposit_limit' => 500000,
        ]);

        Account::create([
            'name' => ucfirst($slug).' Bank',
            'type' => 'bank',
            'used_for' => 'both',
            'account_number' => '00112233'.substr(md5($slug), 0, 4),
            'ifsc_code' => 'HDFC0001234',
            'holder_name' => ucfirst($slug).' Payments',
            'status' => 'active',
            'branch_id' => $branch->id,
            'created_by' => $admin->id,
            'owner_admin_id' => $admin->id,
        ]);

        foreach ($people as $index => [$name, $phone]) {
            $user = User::create([
                'name' => $name,
                'phone' => $phone,
                'password' => self::PASSWORD,
                'mpin' => self::MPIN,
                'play_id' => strtoupper($slug[0]).'PLAY'.(1001 + $index),
                'status' => User::STATUS_ACTIVE,
                'branch_id' => $branch->id,
            ]);

            if ($index === 0) {
                $user->tags()->attach($vip->id);
            }

            // A spread across every status so the admin queues, the history page
            // and the dashboard aggregates all have something to render.
            foreach ([
                Deposit::STATUS_APPROVED,
                Deposit::STATUS_PENDING,
                Deposit::STATUS_REJECTED,
            ] as $offset => $status) {
                $at = Carbon::now()->subDays($offset + $index)->subHours($offset * 3);

                Deposit::create([
                    'user_id' => $user->id,
                    'account_id' => $upi->id,
                    'branch_id' => $branch->id,
                    'play_id' => $user->play_id,
                    'amount' => 5000 * ($offset + 1),
                    'utr_number' => '4011'.str_pad((string) ($index * 10 + $offset), 8, '0', STR_PAD_LEFT),
                    'status' => $status,
                    'approved_by' => $status === Deposit::STATUS_APPROVED ? $admin->id : null,
                    'approved_at' => $status === Deposit::STATUS_APPROVED ? $at : null,
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }

            $at = Carbon::now()->subDays($index + 1);

            Withdrawal::create([
                'user_id' => $user->id,
                'branch_id' => $branch->id,
                'play_id' => $user->play_id,
                'amount' => 2500 * ($index + 1),
                'destination_type' => 'upi',
                'upi_id' => strtolower(str_replace(' ', '', $name)).'@okhdfcbank',
                'status' => $index === 0 ? Withdrawal::STATUS_APPROVED : Withdrawal::STATUS_PENDING,
                'processed_by' => $index === 0 ? $admin->id : null,
                'processed_at' => $index === 0 ? $at : null,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }
    }
}
