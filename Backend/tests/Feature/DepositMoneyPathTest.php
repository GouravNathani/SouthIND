<?php

namespace Tests\Feature;

use App\Models\Deposit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The deposit path — the one that moves real money.
 *
 * The logic under test was copied verbatim from the panel this replaces, which
 * shipped with no tests at all. These exist so the copy is provably faithful,
 * and so the per-account limits (the newest and least-exercised part) cannot
 * silently regress.
 */
class DepositMoneyPathTest extends TestCase
{
    use RefreshDatabase;

    private function userToken(array $ctx): string
    {
        return $ctx['user']->createToken('test')->plainTextToken;
    }

    private function adminToken(array $ctx): string
    {
        return $ctx['admin']->createToken('test')->plainTextToken;
    }

    public function test_user_can_submit_a_deposit(): void
    {
        $ctx = $this->makeBranch();

        $this->withToken($this->userToken($ctx))
            ->postJson('/api/deposits', [
                'account_id' => $ctx['account']->id,
                'amount' => 5000,
                'utr_number' => '401100000001',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('deposits', [
            'user_id' => $ctx['user']->id,
            'account_id' => $ctx['account']->id,
            'amount' => 5000,
            'status' => Deposit::STATUS_PENDING,
            'branch_id' => $ctx['branch']->id,
        ]);
    }

    public function test_deposit_below_the_account_minimum_is_rejected(): void
    {
        $ctx = $this->makeBranch('MIN', ['min_deposit' => 500, 'max_deposit' => 50000]);

        $this->withToken($this->userToken($ctx))
            ->postJson('/api/deposits', [
                'account_id' => $ctx['account']->id,
                'amount' => 499,
                'utr_number' => '401100000002',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->assertDatabaseCount('deposits', 0);
    }

    public function test_deposit_above_the_account_maximum_is_rejected(): void
    {
        $ctx = $this->makeBranch('MAX', ['min_deposit' => 500, 'max_deposit' => 50000]);

        $this->withToken($this->userToken($ctx))
            ->postJson('/api/deposits', [
                'account_id' => $ctx['account']->id,
                'amount' => 50001,
                'utr_number' => '401100000003',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->assertDatabaseCount('deposits', 0);
    }

    public function test_a_utr_number_cannot_be_reused(): void
    {
        $ctx = $this->makeBranch('UTR');
        $token = $this->userToken($ctx);

        $payload = [
            'account_id' => $ctx['account']->id,
            'amount' => 1000,
            'utr_number' => '401100000004',
        ];

        $this->withToken($token)->postJson('/api/deposits', $payload)->assertCreated();
        $this->withToken($token)->postJson('/api/deposits', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('utr_number');

        $this->assertDatabaseCount('deposits', 1);
    }

    public function test_admin_approval_stamps_who_and_when(): void
    {
        $ctx = $this->makeBranch('APR');

        $deposit = Deposit::create([
            'user_id' => $ctx['user']->id,
            'account_id' => $ctx['account']->id,
            'branch_id' => $ctx['branch']->id,
            'play_id' => $ctx['user']->play_id,
            'amount' => 2500,
            'utr_number' => '401100000005',
            'status' => Deposit::STATUS_PENDING,
        ]);

        $this->withToken($this->adminToken($ctx))
            ->patchJson("/api/admin/deposits/{$deposit->id}/status", ['status' => 'approved'])
            ->assertOk();

        $deposit->refresh();

        $this->assertSame(Deposit::STATUS_APPROVED, $deposit->status);
        $this->assertSame($ctx['admin']->id, $deposit->approved_by);
        $this->assertNotNull($deposit->approved_at);
    }

    public function test_an_admin_cannot_approve_another_branchs_deposit(): void
    {
        $chennai = $this->makeBranch('CHN');
        $madurai = $this->makeBranch('MDU');

        $deposit = Deposit::create([
            'user_id' => $madurai['user']->id,
            'account_id' => $madurai['account']->id,
            'branch_id' => $madurai['branch']->id,
            'play_id' => $madurai['user']->play_id,
            'amount' => 1000,
            'utr_number' => '401100000006',
            'status' => Deposit::STATUS_PENDING,
        ]);

        $this->withToken($this->adminToken($chennai))
            ->patchJson("/api/admin/deposits/{$deposit->id}/status", ['status' => 'approved'])
            ->assertNotFound();

        $this->assertSame(Deposit::STATUS_PENDING, $deposit->fresh()->status);
    }

    public function test_reaching_the_cumulative_deposit_limit_pauses_the_account(): void
    {
        // Limit is 10,000 and the approval below takes it to exactly that.
        $ctx = $this->makeBranch('LIM', ['deposit_limit' => 10000]);

        $deposit = Deposit::create([
            'user_id' => $ctx['user']->id,
            'account_id' => $ctx['account']->id,
            'branch_id' => $ctx['branch']->id,
            'play_id' => $ctx['user']->play_id,
            'amount' => 10000,
            'utr_number' => '401100000007',
            'status' => Deposit::STATUS_PENDING,
        ]);

        $this->withToken($this->adminToken($ctx))
            ->patchJson("/api/admin/deposits/{$deposit->id}/status", ['status' => 'approved'])
            ->assertOk();

        $this->assertNotSame(
            'active',
            $ctx['account']->fresh()->status,
            'An account that has reached its cumulative deposit limit must stop accepting deposits.'
        );
    }

    public function test_a_paused_account_is_hidden_from_the_user(): void
    {
        $ctx = $this->makeBranch('PAU', ['status' => 'paused']);

        $response = $this->withToken($this->userToken($ctx))
            ->getJson('/api/accounts')
            ->assertOk();

        $ids = array_column($response->json('data') ?? [], 'id');

        $this->assertNotContains(
            $ctx['account']->id,
            $ids,
            'A paused account must never be offered to a user as a deposit target.'
        );
    }
}
