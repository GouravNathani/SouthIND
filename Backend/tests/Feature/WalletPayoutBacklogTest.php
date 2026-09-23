<?php

namespace Tests\Feature;

use App\Models\WalletCharge;
use App\Models\WalletDailyPayout;
use App\Support\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The daily payout used to clamp its start date to "today - maxDays", so any
 * charge older than that window was never billed at all. $maxDays now caps how
 * many days ONE run takes on, and a backlog drains over several runs.
 */
class WalletPayoutBacklogTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $deductedReferences = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'wallet.wallet_id' => '9900990099',
            'wallet.api_key' => 'test-key',
            'wallet.api_secret' => 'test-secret',
            'wallet.base_url' => 'https://control.test',
        ]);

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/deduct')) {
                $this->deductedReferences[] = $request->data()['reference'] ?? '';

                return Http::response(['success' => true, 'data' => [
                    'transaction_id' => 'tx-' . count($this->deductedReferences),
                    'balance' => 1000,
                ]]);
            }

            return Http::response(['success' => true, 'data' => []]);
        });
    }

    private function chargeOn(int $daysAgo, float $coins = 0.5): void
    {
        $this->travelTo(now()->subDays($daysAgo)->setTime(12, 0));

        WalletCharge::create([
            'channel' => WalletCharge::CHANNEL_SUPPORT,
            'direction' => WalletCharge::DIRECTION_IN,
            'cost_field' => 'support_in_cost',
            'cost_value' => $coins,
            'coins' => $coins,
            'reference' => (string) Str::uuid(),
            'status' => WalletCharge::STATUS_UNSETTLED,
        ]);

        $this->travelBack();
    }

    public function test_charges_older_than_the_window_are_still_billed(): void
    {
        $this->chargeOn(75);
        $this->chargeOn(2);

        $result = app(WalletService::class)->runDailyPayout(null, 60);

        $this->assertSame(2, $result['settled']);
        $this->assertSame(0, WalletCharge::query()->where('status', WalletCharge::STATUS_UNSETTLED)->count());
        $this->assertCount(2, $this->deductedReferences);
        $this->assertStringContainsString(now()->subDays(75)->toDateString(), $this->deductedReferences[0]);
    }

    public function test_a_long_backlog_drains_over_several_runs_oldest_first(): void
    {
        foreach ([10, 9, 8, 7, 6] as $daysAgo) {
            $this->chargeOn($daysAgo);
        }

        $wallet = app(WalletService::class);

        $first = $wallet->runDailyPayout(null, 3);
        // The 3-day budget is spent walking days 10, 9 and 8.
        $this->assertSame(3, $first['settled']);
        $this->assertSame(2, WalletCharge::query()->where('status', WalletCharge::STATUS_UNSETTLED)->count());

        $second = $wallet->runDailyPayout(null, 3);
        $this->assertSame(2, $second['settled']);
        $this->assertSame(0, WalletCharge::query()->where('status', WalletCharge::STATUS_UNSETTLED)->count());

        $this->assertSame(5, WalletDailyPayout::query()->where('status', WalletDailyPayout::STATUS_SETTLED)->count());
        $this->assertStringContainsString(now()->subDays(10)->toDateString(), $this->deductedReferences[0]);
    }
}
