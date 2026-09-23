<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Deposit;
use App\Models\User;
use App\Support\StatusTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two approvals racing: both requests loaded the deposit while it was still
 * pending, so both pass the controller's status check. Only the first may win.
 */
class StatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    private function pendingDeposit(): Deposit
    {
        $branch = Branch::create(['name' => 'Main', 'code' => 'MAIN']);
        $user = User::create([
            'name' => 'Player',
            'phone' => '9000054321',
            'unique_number' => '654321',
            'password' => bcrypt('secret'),
            'branch_id' => $branch->id,
            'status' => User::STATUS_ACTIVE,
        ]);
        $account = Account::create([
            'name' => 'UPI',
            'type' => 'upi',
            'upi_id' => 'test@upi',
            'branch_id' => $branch->id,
        ]);

        return Deposit::create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'branch_id' => $branch->id,
            'play_id' => '654321',
            'amount' => 500,
            'status' => Deposit::STATUS_PENDING,
        ]);
    }

    public function test_only_the_first_of_two_racing_approvals_wins(): void
    {
        $deposit = $this->pendingDeposit();
        $pending = [Deposit::STATUS_PENDING];

        // Two requests, each holding its own copy loaded while still pending.
        $first = Deposit::find($deposit->id);
        $second = Deposit::find($deposit->id);

        $this->assertTrue(StatusTransition::claim($first, $pending, ['status' => Deposit::STATUS_APPROVED, 'notes' => 'first']));
        $this->assertFalse(StatusTransition::claim($second, $pending, ['status' => Deposit::STATUS_REJECTED, 'notes' => 'second']));

        $deposit->refresh();
        $this->assertSame(Deposit::STATUS_APPROVED, $deposit->status);
        $this->assertSame('first', $deposit->notes);
    }

    public function test_the_winning_instance_carries_the_new_state_for_the_side_effects(): void
    {
        $deposit = $this->pendingDeposit();

        StatusTransition::claim($deposit, [Deposit::STATUS_PENDING], ['status' => Deposit::STATUS_APPROVED]);

        $this->assertSame(Deposit::STATUS_APPROVED, $deposit->status);
        $this->assertFalse($deposit->isDirty());
    }
}
