<?php

namespace Tests\Feature;

use App\Models\Deposit;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Super admin business dashboard: monthly growth, churn, new and lost players.
 * Active = an approved deposit or withdrawal in the month.
 */
class BusinessDashboardTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-17 12:00:00');
        Cache::flush();
        $this->ctx = $this->makeBranch('BIZ');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $signedUp, ?int $branchId = null): User
    {
        $this->seq++;
        $user = User::create([
            'name' => 'Player ' . $this->seq,
            'phone' => '+91710000' . str_pad((string) $this->seq, 4, '0', STR_PAD_LEFT),
            'password' => 'secret123',
            'mpin' => '123456',
            'play_id' => 'BIZ' . $this->seq,
            'status' => User::STATUS_ACTIVE,
            'branch_id' => $branchId ?? $this->ctx['branch']->id,
        ]);
        $user->forceFill(['created_at' => $signedUp])->saveQuietly();

        return $user;
    }

    private function deposit(User $user, string $at, float $amount = 1000, string $status = Deposit::STATUS_APPROVED): void
    {
        $this->seq++;
        $deposit = Deposit::create([
            'user_id' => $user->id,
            'account_id' => $this->ctx['account']->id,
            'branch_id' => $user->branch_id,
            'play_id' => $user->play_id,
            'amount' => $amount,
            'utr_number' => '8' . str_pad((string) $this->seq, 11, '0', STR_PAD_LEFT),
            'status' => $status,
        ]);
        $deposit->forceFill(['created_at' => $at])->saveQuietly();
    }

    private function withdrawal(User $user, string $at, float $amount): void
    {
        $withdrawal = Withdrawal::create([
            'user_id' => $user->id,
            'branch_id' => $user->branch_id,
            'play_id' => $user->play_id,
            'amount' => $amount,
            'destination_type' => 'upi',
            'upi_id' => 'x@ybl',
            'status' => Withdrawal::STATUS_APPROVED,
        ]);
        $withdrawal->forceFill(['created_at' => $at])->saveQuietly();
    }

    private function month(array $data, string $label): array
    {
        return collect($data['months'])->firstWhere('month', $label);
    }

    public function test_monthly_growth_churn_and_lost_players(): void
    {
        // Steady: active Aug, Sep, Oct.
        $steady = $this->user('2026-08-01 10:00:00');
        $this->deposit($steady, '2026-08-05 10:00:00', 2000);
        $this->deposit($steady, '2026-09-05 10:00:00', 2000);
        $this->deposit($steady, '2026-10-05 10:00:00', 2000);

        // Left: active Aug and Sep, nothing since 2026-09-02 → lost (45 days).
        $left = $this->user('2026-08-01 10:00:00');
        $this->deposit($left, '2026-08-03 10:00:00', 5000);
        $this->deposit($left, '2026-09-02 10:00:00', 5000);
        $this->withdrawal($left, '2026-09-01 10:00:00', 1000);

        // Comes back in October after skipping September.
        $back = $this->user('2026-07-01 10:00:00');
        $this->deposit($back, '2026-08-10 10:00:00');
        $this->deposit($back, '2026-10-10 10:00:00');

        // New in October.
        $new = $this->user('2026-10-02 10:00:00');
        $this->deposit($new, '2026-10-03 10:00:00', 3000);

        // Signed up, only a rejected deposit — not a depositor, not active.
        $tryer = $this->user('2026-10-04 10:00:00');
        $this->deposit($tryer, '2026-10-04 11:00:00', 500, Deposit::STATUS_REJECTED);

        Sanctum::actingAs($this->ctx['super']);
        $data = $this->getJson('/api/super/dashboard/business')->assertOk()->json('data');

        $this->assertCount(12, $data['months']);

        $aug = $this->month($data, '2026-08');
        $this->assertSame(3, $aug['active']);
        $this->assertSame(3, $aug['new_active']);
        $this->assertSame(2, $aug['signups']);

        $sep = $this->month($data, '2026-09');
        $this->assertSame(2, $sep['active']);
        $this->assertSame(1, $sep['churned']);          // $back skipped September
        $this->assertEqualsWithDelta(33.3, $sep['churn_rate'], 0.05);
        $this->assertEqualsWithDelta(-33.3, $sep['active_growth'], 0.05);

        $oct = $this->month($data, '2026-10');
        $this->assertTrue($oct['partial']);
        $this->assertSame(3, $oct['active']);           // steady, back, new
        $this->assertSame(1, $oct['retained']);
        $this->assertSame(1, $oct['new_active']);
        $this->assertSame(1, $oct['reactivated']);
        $this->assertSame(1, $oct['churned']);          // $left
        $this->assertSame(3, $oct['signups']);       // new, tryer, the helper's user
        $this->assertSame(1, $oct['first_deposits']);
        $this->assertEquals(6000, $oct['deposits_total']);
        $this->assertEqualsWithDelta(50.0, $oct['active_growth'], 0.05);

        $snap = $data['snapshot'];
        $this->assertSame(6, $snap['total_users']);    // 5 here + the helper's user
        $this->assertSame(4, $snap['depositors']);
        $this->assertSame(3, $snap['active_30d']);
        $this->assertSame(1, $snap['lost']);
        $this->assertEquals(9000, $snap['lost_net_value']);

        $this->assertSame($left->id, $data['lost_players'][0]['id']);
        $this->assertSame(45, $data['lost_players'][0]['days_inactive']);
        $this->assertEquals(10000, $data['lost_players'][0]['lifetime_deposits']);

        $this->assertSame($new->id, $data['top_players'][0]['id']);
        $this->assertEquals(3000, $data['top_players'][0]['month_deposits']);

        $cohort = collect($data['cohorts'])->firstWhere('month', '2026-08');
        $this->assertSame(2, $cohort['size']);
        $this->assertEquals([100.0, 100.0, 50.0], $cohort['retention']);
    }

    public function test_branch_filter_and_breakdown(): void
    {
        $other = $this->makeBranch('OTH');
        $here = $this->user('2026-10-01 10:00:00');
        $this->deposit($here, '2026-10-02 10:00:00', 1000);
        $there = $this->user('2026-10-01 10:00:00', $other['branch']->id);
        $this->deposit($there, '2026-10-02 10:00:00', 7000);

        Sanctum::actingAs($this->ctx['super']);

        $all = $this->getJson('/api/super/dashboard/business')->assertOk()->json('data');
        $this->assertEquals(8000, $this->month($all, '2026-10')['deposits_total']);
        $this->assertCount(2, $all['branches']);
        $this->assertEquals(7000, collect($all['branches'])->firstWhere('id', $other['branch']->id)['deposits_month']);

        $one = $this->getJson('/api/super/dashboard/business?branch_id=' . $other['branch']->id)->assertOk()->json('data');
        $this->assertEquals(7000, $this->month($one, '2026-10')['deposits_total']);
        $this->assertSame([], $one['branches']);
        $this->assertSame(1, $one['month_to_date']['current']['active']);
    }

    public function test_branch_admin_cannot_open_it(): void
    {
        Sanctum::actingAs($this->ctx['admin']);
        $this->getJson('/api/super/dashboard/business')->assertForbidden();
    }
}
