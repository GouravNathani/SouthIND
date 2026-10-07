<?php

namespace Tests\Feature;

use App\Models\Deposit;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Reject is sent as status "rejected". The Admin and SuperAdmin decision pages
 * sent "reject", which failed validation, so no deposit or withdrawal could be
 * rejected from either panel.
 */
class RejectStatusTest extends TestCase
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
            'utr_number' => '401100000101',
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

    /** @return array<string, array{string, string}> */
    public static function panels(): array
    {
        return [
            'admin' => ['admin', '/api/admin'],
            'super' => ['super', '/api/super'],
        ];
    }

    #[DataProvider('panels')]
    public function test_a_deposit_can_be_rejected(string $actor, string $prefix): void
    {
        $ctx = $this->makeBranch('RJD');
        $deposit = $this->pendingDeposit($ctx);

        $this->withToken($ctx[$actor]->createToken('test')->plainTextToken)
            ->patchJson("{$prefix}/deposits/{$deposit->id}/status", [
                'status' => 'rejected',
                'notes' => 'UTR not received',
            ])
            ->assertOk();

        $deposit->refresh();
        $this->assertSame(Deposit::STATUS_REJECTED, $deposit->status);
        $this->assertSame('UTR not received', $deposit->notes);
        $this->assertSame($ctx[$actor]->id, $deposit->approved_by);
    }

    #[DataProvider('panels')]
    public function test_a_withdrawal_can_be_rejected(string $actor, string $prefix): void
    {
        $ctx = $this->makeBranch('RJW');
        $withdrawal = $this->pendingWithdrawal($ctx);

        $this->withToken($ctx[$actor]->createToken('test')->plainTextToken)
            ->patchJson("{$prefix}/withdrawals/{$withdrawal->id}/status", [
                'status' => 'rejected',
                'notes' => 'Bank details wrong',
            ])
            ->assertOk();

        $withdrawal->refresh();
        $this->assertSame(Withdrawal::STATUS_REJECTED, $withdrawal->status);
        $this->assertSame('Bank details wrong', $withdrawal->notes);
        $this->assertSame($ctx[$actor]->id, $withdrawal->processed_by);
    }

    public function test_the_old_reject_value_is_refused_and_changes_nothing(): void
    {
        $ctx = $this->makeBranch('RJX');
        $deposit = $this->pendingDeposit($ctx);
        $withdrawal = $this->pendingWithdrawal($ctx);
        $token = $ctx['admin']->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->patchJson("/api/admin/deposits/{$deposit->id}/status", ['status' => 'reject'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
        $this->withToken($token)
            ->patchJson("/api/admin/withdrawals/{$withdrawal->id}/status", ['status' => 'reject'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame(Deposit::STATUS_PENDING, $deposit->fresh()->status);
        $this->assertSame(Withdrawal::STATUS_PENDING, $withdrawal->fresh()->status);
    }
}
