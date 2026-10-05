<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Deposit;
use App\Models\User;
use App\Models\WalletMonthlyPayout;
use App\Support\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Monthly Payout (the developer's percent of approved deposits) is deducted
 * from the wallet once per finished month, from config payout.start_month on.
 * A failed month stays owed and is retried with the amount it was first billed.
 */
class MonthlyPayoutTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{reference: string, amount: float}> */
    private array $deductions = [];

    private float $percent = 0.5;

    private bool $controlUp = true;

    private bool $insufficientFunds = false;

    private Branch $branch;

    private Account $account;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'wallet.wallet_id' => '9900990099',
            'wallet.api_key' => 'test-key',
            'wallet.api_secret' => 'test-secret',
            'wallet.base_url' => 'https://control.test',
            'payout.start_month' => '2026-07',
        ]);

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (!$this->controlUp) {
                return Http::response(['success' => false], 500);
            }

            if (str_ends_with($request->url(), '/deduct')) {
                if ($this->insufficientFunds) {
                    return Http::response(['success' => false, 'error' => ['code' => 'insufficient_funds']], 422);
                }

                $this->deductions[] = [
                    'reference' => $request->data()['reference'] ?? '',
                    'amount' => (float) ($request->data()['amount'] ?? 0),
                ];

                return Http::response(['success' => true, 'data' => [
                    'transaction_id' => 'tx-' . count($this->deductions),
                    'balance' => -100,
                ]]);
            }

            return Http::response(['success' => true, 'data' => [
                'wallet_id' => '9900990099',
                'balance' => 100,
                'monthly_payout_percent' => $this->percent,
                'costs' => [],
            ]]);
        });

        $this->travelTo(Carbon::create(2026, 10, 5, 12, 0, 0, config('app.timezone')));

        $this->branch = Branch::create(['name' => 'Main', 'code' => 'MAIN']);
        $this->account = Account::create([
            'name' => 'UPI 1',
            'type' => 'upi',
            'upi_id' => 'test@upi',
            'status' => 'active',
            'branch_id' => $this->branch->id,
        ]);
        $this->user = User::create([
            'name' => 'Player',
            'phone' => '9000012345',
            'unique_number' => '123456',
            'password' => bcrypt('secret'),
            'branch_id' => $this->branch->id,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->deposit('2026-06-20', 50000);                            // before start_month
        $this->deposit('2026-07-10', 60000);
        $this->deposit('2026-07-25', 40000);                            // July: 1,00,000
        $this->deposit('2026-07-26', 99999, Deposit::STATUS_REJECTED);  // never counted
        $this->deposit('2026-08-15', 200000);                           // August: 2,00,000
        $this->deposit('2026-10-02', 300000);                           // current month
    }

    private function deposit(string $date, float $amount, string $status = Deposit::STATUS_APPROVED): void
    {
        Deposit::create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'branch_id' => $this->branch->id,
            'play_id' => '123456',
            'amount' => $amount,
            'status' => $status,
            'approved_at' => $status === Deposit::STATUS_APPROVED
                ? Carbon::parse("{$date} 12:00:00", config('app.timezone'))
                : null,
        ]);
    }

    private function bill(?string $month = null): array
    {
        return app(WalletService::class)->runMonthlyPayout($month);
    }

    public function test_each_finished_month_is_deducted_once_from_the_start_month(): void
    {
        $result = $this->bill();

        // July and August deducted, September had nothing to bill, October is not over.
        $this->assertSame(3, $result['months']);
        $this->assertSame(0, $result['owed']);
        $this->assertCount(2, $this->deductions);
        $this->assertStringEndsWith('-month-2026-07', $this->deductions[0]['reference']);
        $this->assertSame(500.0, $this->deductions[0]['amount']);   // 1,00,000 x 0.5%
        $this->assertStringEndsWith('-month-2026-08', $this->deductions[1]['reference']);
        $this->assertSame(1000.0, $this->deductions[1]['amount']);  // 2,00,000 x 0.5%

        $this->assertSame(WalletMonthlyPayout::STATUS_SKIPPED, WalletMonthlyPayout::where('month', '2026-09')->value('status'));
        $this->assertNull(WalletMonthlyPayout::where('month', '2026-06')->first());
        $this->assertNull(WalletMonthlyPayout::where('month', '2026-10')->first());

        // A second run the same night bills nothing again.
        $this->assertSame(0, $this->bill()['months']);
        $this->assertCount(2, $this->deductions);
    }

    public function test_an_owed_month_is_retried_with_the_amount_it_was_first_billed(): void
    {
        $this->insufficientFunds = true;

        $first = $this->bill();
        $wallet = app(WalletService::class);

        $this->assertSame(2, $first['owed']);
        $this->assertSame(1500.0, $wallet->owedCoins());
        $this->assertSame(['2026-08' => 1000.0, '2026-07' => 500.0], $wallet->owedByMonth());

        // The rate changes before the retry; the owed months keep their amounts.
        $this->insufficientFunds = false;
        $this->percent = 1.0;

        $second = $this->bill();

        $this->assertSame(2, $second['settled']);
        $this->assertSame([500.0, 1000.0], array_column($this->deductions, 'amount'));
        $this->assertSame(0.0, app(WalletService::class)->owedCoins());
    }

    public function test_nothing_is_billed_without_a_rate_from_control(): void
    {
        $this->controlUp = false;

        $this->assertSame(0, $this->bill()['months']);
        $this->assertSame(0, WalletMonthlyPayout::count());
    }

    public function test_the_super_admin_monthly_report_shows_what_was_deducted(): void
    {
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'sqlite' && !$this->payoutReportRunsOnSqlite()) {
            $this->markTestSkipped('This panel\'s payout report uses MySQL-only DATE_FORMAT.');
        }

        $this->bill();

        $super = \App\Models\Admin::create([
            'name' => 'Super',
            'email' => 'super-payout@test.local',
            'phone' => '9830000001',
            'password' => 'secret',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $token = $super->createToken('test');
        if (\Illuminate\Support\Facades\Schema::hasColumn('personal_access_tokens', 'device_id')) {
            $token->accessToken->forceFill(['device_id' => 'test-device'])->save();
        }

        $rows = collect($this->withHeaders([
            'Authorization' => 'Bearer ' . $token->plainTextToken,
            'X-Device-Id' => 'test-device',
        ])->getJson('/api/super/payout/monthly?year=2026')->assertOk()->json('data'))->keyBy('month');

        $this->assertSame('settled', $rows['2026-07']['deduction']['status']);
        $this->assertEquals(500, $rows['2026-07']['deduction']['coins']);
        $this->assertSame('skipped', $rows['2026-09']['deduction']['status']);
        $this->assertNull($rows['2026-10']['deduction']);
    }

    private function payoutReportRunsOnSqlite(): bool
    {
        $source = (string) file_get_contents(app_path('Http/Controllers/Super/PayoutController.php'));

        return !str_contains($source, 'DATE_FORMAT(COALESCE');
    }

    public function test_one_finished_month_can_be_billed_on_demand(): void
    {
        $this->assertSame(1, $this->bill('2026-08')['months']);
        $this->assertCount(1, $this->deductions);
        $this->assertStringEndsWith('-month-2026-08', $this->deductions[0]['reference']);

        // The running month is never billed early.
        $this->assertSame(0, $this->bill('2026-10')['months']);
    }
}
