<?php

namespace Tests\Feature;

use App\Models\Deposit;
use App\Support\Query\DateBucket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression guard for the reporting endpoints.
 *
 * They were inherited using raw `DATE_FORMAT(...)` / `DATE(...)`, which are
 * MySQL spellings. That works in production and throws "no such function" on
 * SQLite — so every one of these endpoints was broken in local development and
 * in CI, and nobody knew, because there were no tests. DateBucket makes the
 * expressions driver-portable; these tests are what stop them drifting back.
 */
class ReportingPortabilityTest extends TestCase
{
    use RefreshDatabase;

    private function superToken(array $ctx): string
    {
        return $ctx['super']->createToken('test')->plainTextToken;
    }

    private function approvedDeposit(array $ctx, string $utr, Carbon $at, float $amount = 5000): void
    {
        Deposit::create([
            'user_id' => $ctx['user']->id,
            'account_id' => $ctx['account']->id,
            'branch_id' => $ctx['branch']->id,
            'play_id' => $ctx['user']->play_id,
            'amount' => $amount,
            'utr_number' => $utr,
            'status' => Deposit::STATUS_APPROVED,
            'approved_by' => $ctx['admin']->id,
            'approved_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    public function test_date_bucket_groups_by_month_on_this_driver(): void
    {
        $ctx = $this->makeBranch();
        $this->approvedDeposit($ctx, '5011000001', Carbon::parse('2026-03-14 10:00:00'));

        $row = DB::table('deposits')
            ->selectRaw(DateBucket::month('approved_at').' as ym')
            ->first();

        $this->assertSame('2026-03', $row->ym);
    }

    public function test_date_bucket_groups_by_day_on_this_driver(): void
    {
        $ctx = $this->makeBranch();
        $this->approvedDeposit($ctx, '5011000002', Carbon::parse('2026-03-14 10:00:00'));

        $row = DB::table('deposits')
            ->selectRaw(DateBucket::day('approved_at').' as d')
            ->first();

        $this->assertSame('2026-03-14', $row->d);
    }

    public function test_monthly_payout_report_returns_grouped_totals(): void
    {
        // Explicit: the report hides anything before payout.start_month, so a
        // test that relied on the config default would break the day someone
        // set one.
        config(['payout.start_month' => '']);

        $ctx = $this->makeBranch();
        $year = Carbon::now()->year;

        $this->approvedDeposit($ctx, '5011000003', Carbon::create($year, 3, 5, 12), 5000);
        $this->approvedDeposit($ctx, '5011000004', Carbon::create($year, 3, 9, 12), 3000);
        $this->approvedDeposit($ctx, '5011000005', Carbon::create($year, 4, 2, 12), 2000);

        $response = $this->withToken($this->superToken($ctx))
            ->getJson('/api/super/payout/monthly')
            ->assertOk();

        $months = collect($response->json('data'))->keyBy('month');

        $this->assertSame(8000, (int) $months[sprintf('%d-03', $year)]['approved_total']);
        $this->assertSame(2, (int) $months[sprintf('%d-03', $year)]['deposit_count']);
        $this->assertSame(2000, (int) $months[sprintf('%d-04', $year)]['approved_total']);
    }

    public function test_months_before_the_payout_start_month_are_hidden(): void
    {
        $year = Carbon::now()->year;
        config(['payout.start_month' => sprintf('%d-04', $year)]);

        $ctx = $this->makeBranch();

        $this->approvedDeposit($ctx, '5011000006', Carbon::create($year, 3, 5, 12), 5000);
        $this->approvedDeposit($ctx, '5011000007', Carbon::create($year, 4, 2, 12), 2000);

        $months = collect(
            $this->withToken($this->superToken($ctx))
                ->getJson('/api/super/payout/monthly')
                ->assertOk()
                ->json('data')
        )->pluck('month');

        $this->assertNotContains(sprintf('%d-03', $year), $months);
        $this->assertContains(sprintf('%d-04', $year), $months);
    }

    public function test_the_shipped_default_shows_every_month(): void
    {
        // The panel this was ported from hardcoded its own billing start date.
        // Carried over, it would silently hide SouthIND's own first months.
        $this->assertSame('', (string) config('payout.start_month'));
    }

    public function test_every_reporting_endpoint_responds(): void
    {
        $ctx = $this->makeBranch();
        $token = $this->superToken($ctx);

        foreach ([
            '/api/super/payout/monthly',
            '/api/super/payout/daily',
            '/api/super/wallet',
            '/api/super/wallet/daily',
            '/api/super/dashboard/user-activity',
            '/api/super/dashboard/user-averages',
        ] as $endpoint) {
            $this->withToken($token)->getJson($endpoint)->assertOk();
        }
    }
}
