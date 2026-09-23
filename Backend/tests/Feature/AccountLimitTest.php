<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountStatusEvent;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\Deposit;
use App\Models\User;
use App\Support\AccountStatusAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Deposit limit: 0/empty means unlimited, reaching a real limit auto-pauses the
 * account, and every status change records why and who — the panels show that
 * instead of a bare "paused".
 */
class AccountLimitTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['name' => 'Main', 'code' => 'MAIN']);
    }

    private function admin(string $role = 'admin'): Admin
    {
        static $n = 0;
        $n++;

        return Admin::create([
            'name' => ucfirst($role) . " {$n}",
            'email' => "{$role}{$n}@test.local",
            'phone' => '98000' . str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'password' => 'secret',
            'role' => $role,
            'is_active' => true,
            'branch_id' => $role === 'super_admin' ? null : $this->branch->id,
        ]);
    }

    private function account(?float $limit, ?Admin $owner = null): Account
    {
        return Account::create([
            'name' => 'UPI ' . random_int(1000, 9999),
            'type' => 'upi',
            'upi_id' => 'test@upi',
            'status' => 'active',
            'deposit_limit' => $limit,
            'branch_id' => $this->branch->id,
            'created_by' => $owner?->id,
            'owner_admin_id' => $owner?->id,
        ]);
    }

    private function pendingDeposit(Account $account, float $amount): Deposit
    {
        $user = User::create([
            'name' => 'Player',
            'phone' => '90000' . random_int(10000, 99999),
            'unique_number' => (string) random_int(100000, 999999),
            'password' => bcrypt('secret'),
            'branch_id' => $this->branch->id,
            'status' => User::STATUS_ACTIVE,
        ]);

        return Deposit::create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'branch_id' => $this->branch->id,
            'play_id' => (string) $user->unique_number,
            'amount' => $amount,
            'status' => Deposit::STATUS_PENDING,
        ]);
    }

    private function approveAsAdmin(Admin $admin, Deposit $deposit): void
    {
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/deposits/{$deposit->id}/status", ['status' => Deposit::STATUS_APPROVED])
            ->assertOk();
    }

    public function test_a_zero_limit_never_pauses_the_account(): void
    {
        $admin = $this->admin();
        $account = $this->account(0, $admin);

        foreach ([50000, 75000, 125000] as $amount) {
            $this->approveAsAdmin($admin, $this->pendingDeposit($account, $amount));
        }

        $this->assertSame('active', $account->fresh()->status);
        $this->assertSame(0, AccountStatusEvent::count());
    }

    public function test_an_empty_limit_never_pauses_the_account(): void
    {
        $admin = $this->admin();
        $account = $this->account(null, $admin);

        $this->approveAsAdmin($admin, $this->pendingDeposit($account, 999999));

        $this->assertSame('active', $account->fresh()->status);
    }

    public function test_reaching_the_limit_pauses_with_the_reason_and_totals(): void
    {
        $admin = $this->admin();
        $account = $this->account(1000, $admin);

        $this->approveAsAdmin($admin, $this->pendingDeposit($account, 600));
        $this->assertSame('active', $account->fresh()->status);

        $crossing = $this->pendingDeposit($account, 500);
        $this->approveAsAdmin($admin, $crossing);

        $account->refresh();
        $this->assertSame('paused', $account->status);
        $this->assertSame(AccountStatusAudit::REASON_LIMIT_REACHED, $account->status_reason);
        $this->assertSame(AccountStatusAudit::ROLE_SYSTEM, $account->status_changed_by_role);
        $this->assertNull($account->status_changed_by_id);
        $this->assertNotNull($account->status_changed_at);
        $this->assertEquals(1100, $account->status_meta['approved_total']);
        $this->assertEquals(1000, $account->status_meta['limit']);
        $this->assertSame($crossing->id, $account->status_meta['deposit_id']);
        $this->assertSame($admin->id, $account->status_meta['approved_by']);

        $event = AccountStatusEvent::sole();
        $this->assertSame('active', $event->from_status);
        $this->assertSame('paused', $event->to_status);
        $this->assertSame(AccountStatusAudit::REASON_LIMIT_REACHED, $event->reason);
    }

    public function test_a_super_admin_approval_also_enforces_the_limit(): void
    {
        $account = $this->account(1000);

        Sanctum::actingAs($this->admin('super_admin'));
        $this->patchJson("/api/super/deposits/{$this->pendingDeposit($account, 1500)->id}/status", [
            'status' => Deposit::STATUS_APPROVED,
        ])->assertOk();

        $account->refresh();
        $this->assertSame('paused', $account->status);
        $this->assertSame(AccountStatusAudit::REASON_LIMIT_REACHED, $account->status_reason);
    }

    public function test_setting_the_limit_to_zero_releases_a_limit_paused_account(): void
    {
        $admin = $this->admin();
        $account = $this->account(1000, $admin);
        $this->approveAsAdmin($admin, $this->pendingDeposit($account, 1000));
        $this->assertSame('paused', $account->fresh()->status);

        $this->putJson("/api/admin/accounts/{$account->id}", ['deposit_limit' => 0])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.status_reason', AccountStatusAudit::REASON_LIMIT_LIFTED)
            ->assertJsonPath('data.status_changed_by.id', $admin->id)
            ->assertJsonPath('data.status_changed_by.role', 'admin');
    }

    public function test_raising_the_limit_only_releases_once_it_is_above_the_total(): void
    {
        $admin = $this->admin();
        $account = $this->account(1000, $admin);
        $this->approveAsAdmin($admin, $this->pendingDeposit($account, 1200));

        // Still at/under the approved total: stays paused.
        $this->putJson("/api/admin/accounts/{$account->id}", ['deposit_limit' => 1200])
            ->assertOk()
            ->assertJsonPath('data.status', 'paused');

        $this->putJson("/api/admin/accounts/{$account->id}", ['deposit_limit' => 5000])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_a_manual_deactivation_records_who_did_it_and_is_not_auto_released(): void
    {
        $admin = $this->admin();
        $account = $this->account(1000, $admin);
        Sanctum::actingAs($admin);

        $this->putJson("/api/admin/accounts/{$account->id}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status_reason', AccountStatusAudit::REASON_MANUAL)
            ->assertJsonPath('data.status_changed_by.name', $admin->name);

        // Clearing the limit must not flip a hand-disabled account back on.
        $this->putJson("/api/admin/accounts/{$account->id}", ['deposit_limit' => 0])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_the_list_shows_who_added_and_who_changed_each_account(): void
    {
        $admin = $this->admin();
        $account = $this->account(1000, $admin);
        Sanctum::actingAs($admin);
        $this->putJson("/api/admin/accounts/{$account->id}", ['status' => 'inactive'])->assertOk();

        $this->getJson('/api/admin/accounts')
            ->assertOk()
            ->assertJsonPath('data.0.created_by.id', $admin->id)
            ->assertJsonPath('data.0.status_changed_by.id', $admin->id)
            ->assertJsonPath('data.0.status_reason', AccountStatusAudit::REASON_MANUAL);
    }

    public function test_zero_limit_accounts_are_offered_to_users(): void
    {
        $this->account(0);
        $user = User::create([
            'name' => 'Player',
            'phone' => '9111111111',
            'unique_number' => '123456',
            'password' => bcrypt('secret'),
            'branch_id' => $this->branch->id,
            'status' => User::STATUS_ACTIVE,
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/accounts')->assertOk()->assertJsonCount(1, 'data');
    }
}
