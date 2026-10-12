<?php

namespace Tests\Feature;

use App\Models\BonusCode;
use App\Models\BonusCodeRedemption;
use App\Models\Deposit;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * "In progress" (on_process) marks a request as being worked on. It is not a
 * decision: it must not stamp a decided time, and it must not release a bonus
 * code applied on the deposit form the way a reject does. The request can then
 * still be approved or rejected.
 */
class InProgressStatusTest extends TestCase
{
    use RefreshDatabase;

    private function pendingDeposit(array $ctx): Deposit
    {
        return Deposit::create([
            'user_id' => $ctx['user']->id,
            'account_id' => $ctx['account']->id,
            'branch_id' => $ctx['branch']->id,
            'play_id' => $ctx['user']->play_id,
            'amount' => 1500,
            'utr_number' => '401100000201',
            'status' => Deposit::STATUS_PENDING,
        ]);
    }

    private function pendingWithdrawal(array $ctx): Withdrawal
    {
        return Withdrawal::create([
            'user_id' => $ctx['user']->id,
            'branch_id' => $ctx['branch']->id,
            'play_id' => $ctx['user']->play_id,
            'amount' => 800,
            'destination_type' => 'upi',
            'upi_id' => 'player@okaxis',
            'status' => Withdrawal::STATUS_PENDING,
        ]);
    }

    private function parkedBonus(array $ctx, Deposit $deposit): BonusCodeRedemption
    {
        $code = BonusCode::create([
            'code' => 'WELCOME' . $ctx['branch']->code,
            'branch_id' => $ctx['branch']->id,
            'reward_amount' => 100,
            'frequency' => BonusCode::FREQ_ONCE,
            'status' => BonusCode::STATUS_ACTIVE,
            'requires_deposit' => true,
        ]);

        return BonusCodeRedemption::create([
            'bonus_code_id' => $code->id,
            'user_id' => $ctx['user']->id,
            'branch_id' => $ctx['branch']->id,
            'code' => $code->code,
            'amount' => 100,
            'status' => BonusCodeRedemption::STATUS_AWAITING_DEPOSIT,
            'period_key' => 'once',
            'deposit_id' => $deposit->id,
            'redeemed_at' => now(),
        ]);
    }

    /** @return array<string, array{string, string}> */
    public static function panels(): array
    {
        return [
            'admin' => ['admin', '/api/admin'],
            'super' => ['super', '/api/super'],
        ];
    }

    #[DataProvider('panels')]
    public function test_a_deposit_goes_in_progress_without_being_decided(string $actor, string $prefix): void
    {
        $ctx = $this->makeBranch('IPD');
        $deposit = $this->pendingDeposit($ctx);
        $bonus = $this->parkedBonus($ctx, $deposit);
        $token = $ctx[$actor]->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->patchJson("{$prefix}/deposits/{$deposit->id}/status", [
                'status' => 'on_process',
                'notes' => 'Checking with bank',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Deposit::STATUS_ON_PROCESS);

        $deposit->refresh();
        $this->assertSame(Deposit::STATUS_ON_PROCESS, $deposit->status);
        $this->assertSame('Checking with bank', $deposit->notes);
        $this->assertNull($deposit->approved_by);
        $this->assertNull($deposit->approved_at);
        $this->assertSame(BonusCodeRedemption::STATUS_AWAITING_DEPOSIT, $bonus->fresh()->status);

        // The queue's "In progress" filter finds it; "Pending" no longer does.
        $this->withToken($token)
            ->getJson("{$prefix}/deposits?status=on_process&per_page=25")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $deposit->id);
        $this->withToken($token)
            ->getJson("{$prefix}/deposits?status=pending&per_page=25")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Still decidable afterwards.
        $this->withToken($token)
            ->patchJson("{$prefix}/deposits/{$deposit->id}/status", ['status' => 'approved'])
            ->assertOk();

        $deposit->refresh();
        $this->assertSame(Deposit::STATUS_APPROVED, $deposit->status);
        $this->assertSame($ctx[$actor]->id, $deposit->approved_by);
        $this->assertNotNull($deposit->approved_at);
    }

    #[DataProvider('panels')]
    public function test_a_withdrawal_goes_in_progress_and_can_then_be_rejected(string $actor, string $prefix): void
    {
        $ctx = $this->makeBranch('IPW');
        $withdrawal = $this->pendingWithdrawal($ctx);
        $token = $ctx[$actor]->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->patchJson("{$prefix}/withdrawals/{$withdrawal->id}/status", ['status' => 'on_process'])
            ->assertOk()
            ->assertJsonPath('data.status', Withdrawal::STATUS_ON_PROCESS);

        $withdrawal->refresh();
        $this->assertSame(Withdrawal::STATUS_ON_PROCESS, $withdrawal->status);
        $this->assertNull($withdrawal->processed_by);
        $this->assertNull($withdrawal->processed_at);

        $this->withToken($token)
            ->getJson("{$prefix}/withdrawals?status=on_process&per_page=25")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withToken($token)
            ->patchJson("{$prefix}/withdrawals/{$withdrawal->id}/status", [
                'status' => 'rejected',
                'notes' => 'Bank details wrong',
            ])
            ->assertOk();

        $withdrawal->refresh();
        $this->assertSame(Withdrawal::STATUS_REJECTED, $withdrawal->status);
        $this->assertSame($ctx[$actor]->id, $withdrawal->processed_by);
        $this->assertNotNull($withdrawal->processed_at);
    }
}
