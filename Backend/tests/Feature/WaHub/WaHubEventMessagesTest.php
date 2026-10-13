<?php

namespace Tests\Feature\WaHub;

use App\Jobs\SendWaHubMessage;
use App\Models\BonusCode;
use App\Models\BonusCodeRedemption;
use App\Models\Deposit;
use App\Models\User;
use App\Models\Withdrawal;
use App\Support\WaHub\WaHubClient;
use App\Support\WaHub\WaHubNotifier;
use App\Support\WaHub\WaHubUnavailableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Event messages: each hook queues SendWaHubMessage with the right idempotency
 * key (ids only — never the MPIN or phone), and the job posts the right
 * variables to the hub at send time.
 */
class WaHubEventMessagesTest extends TestCase
{
    use RefreshDatabase;
    use WaHubTestHelpers;

    /** @var array{branch: \App\Models\Branch, super: \App\Models\Admin, admin: \App\Models\Admin, user: User, account: \App\Models\Account} */
    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ctx = $this->makeBranch('WAE');
        $this->ctx['branch']->update(['name' => 'South Branch', 'domain' => 'play.southind.test']);
        $this->ctx['user']->update(['name' => 'Ravi', 'phone' => '98765 43210', 'play_id' => 'RAVI77', 'mpin' => '482913']);
        $this->configureHub();
    }

    /** @return array<string, array{string, string}> */
    public static function panels(): array
    {
        return [
            'admin' => ['admin', '/api/admin'],
            'super' => ['super', '/api/super'],
        ];
    }

    private function deposit(array $overrides = []): Deposit
    {
        return Deposit::create(array_merge([
            'user_id' => $this->ctx['user']->id,
            'account_id' => $this->ctx['account']->id,
            'branch_id' => $this->ctx['branch']->id,
            'play_id' => 'RAVI77',
            'amount' => 125000,
            'utr_number' => '401100000101',
            'status' => Deposit::STATUS_PENDING,
        ], $overrides));
    }

    private function withdrawal(array $overrides = []): Withdrawal
    {
        return Withdrawal::create(array_merge([
            'user_id' => $this->ctx['user']->id,
            'branch_id' => $this->ctx['branch']->id,
            'play_id' => 'RAVI77',
            'amount' => 1234.5,
            'destination_type' => 'upi',
            'upi_id' => 'ravikumar@ybl',
            'status' => Withdrawal::STATUS_PENDING,
        ], $overrides));
    }

    private function redemption(): BonusCodeRedemption
    {
        static $n = 0;
        $n++;

        $code = BonusCode::create([
            'code' => "DIWALI{$n}",
            'branch_id' => $this->ctx['branch']->id,
            'reward_amount' => 500,
            'reward_label' => 'Cash bonus',
        ]);

        return BonusCodeRedemption::create([
            'bonus_code_id' => $code->id,
            'user_id' => $this->ctx['user']->id,
            'branch_id' => $this->ctx['branch']->id,
            'code' => 'DIWALI500',
            'amount' => 500,
            'reward_label' => 'Cash bonus',
            'status' => BonusCodeRedemption::STATUS_PENDING,
            'period_key' => 'once',
        ]);
    }

    private function actAs(string $actor): void
    {
        Sanctum::actingAs($this->ctx[$actor]);
    }

    /** The queued job never holds a secret or a number. */
    private function assertJobHasNoSecrets(SendWaHubMessage $job, User $user): void
    {
        $serialized = serialize($job);

        $this->assertStringNotContainsString((string) $user->mpin, $serialized);
        $this->assertStringNotContainsString('43210', $serialized);
        $this->assertStringNotContainsString((string) $user->phone, $serialized);
    }

    // ---- Hooks ------------------------------------------------------------

    #[DataProvider('panels')]
    public function test_deposit_decisions_queue_a_message(string $actor, string $prefix): void
    {
        Queue::fake();
        $approved = $this->deposit();
        $rejected = $this->deposit(['utr_number' => '401100000102']);

        $this->actAs($actor);
        $this->patchJson("{$prefix}/deposits/{$approved->id}/status", ['status' => 'approved'])->assertOk();
        $this->patchJson("{$prefix}/deposits/{$rejected->id}/status", ['status' => 'rejected', 'notes' => 'UTR not found'])->assertOk();

        $type = $actor === 'super' ? 'super' : 'admin';

        Queue::assertPushed(SendWaHubMessage::class, fn (SendWaHubMessage $job) => $job->idempotencyKey === "deposit-{$approved->id}-approved"
            && $job->event === 'deposit.approved'
            && $job->refType === 'deposit'
            && $job->refId === $approved->id
            && $job->actor === ['type' => $type, 'id' => (string) $this->ctx[$actor]->id, 'name' => $this->ctx[$actor]->name]
            && $job->connection === 'database');
        Queue::assertPushed(SendWaHubMessage::class, fn (SendWaHubMessage $job) => $job->idempotencyKey === "deposit-{$rejected->id}-rejected"
            && $job->event === 'deposit.rejected');
        Queue::assertPushed(SendWaHubMessage::class, 2);
    }

    #[DataProvider('panels')]
    public function test_withdrawal_decisions_queue_a_message(string $actor, string $prefix): void
    {
        Queue::fake();
        $approved = $this->withdrawal();
        $rejected = $this->withdrawal();
        $processing = $this->withdrawal();

        $this->actAs($actor);
        $this->patchJson("{$prefix}/withdrawals/{$approved->id}/status", ['status' => 'approved'])->assertOk();
        $this->patchJson("{$prefix}/withdrawals/{$rejected->id}/status", ['status' => 'rejected', 'notes' => 'Wrong UPI'])->assertOk();
        $this->patchJson("{$prefix}/withdrawals/{$processing->id}/status", ['status' => 'on_process'])->assertOk();

        Queue::assertPushed(SendWaHubMessage::class, fn (SendWaHubMessage $job) => $job->idempotencyKey === "withdrawal-{$approved->id}-approved"
            && $job->event === 'withdrawal.approved');
        Queue::assertPushed(SendWaHubMessage::class, fn (SendWaHubMessage $job) => $job->idempotencyKey === "withdrawal-{$rejected->id}-rejected"
            && $job->event === 'withdrawal.rejected');
        Queue::assertPushed(SendWaHubMessage::class, 2);
    }

    public function test_new_users_get_a_welcome_without_the_mpin_in_the_queue(): void
    {
        Queue::fake();

        $this->actAs('admin');
        $adminUser = $this->postJson('/api/admin/users', ['phone' => '9000000001', 'play_id' => 'NEW1'])->assertCreated();

        $this->actAs('super');
        $superUser = $this->postJson('/api/super/users', ['phone' => '9000000002', 'branch_id' => $this->ctx['branch']->id])->assertCreated();

        foreach ([$adminUser, $superUser] as $response) {
            $user = User::query()->where('unique_number', $response->json('credentials.user_id'))->firstOrFail();
            $this->assertSame($response->json('credentials.mpin'), $user->mpin);

            Queue::assertPushed(SendWaHubMessage::class, function (SendWaHubMessage $job) use ($user) {
                if ($job->idempotencyKey !== "user-{$user->id}-welcome") {
                    return false;
                }

                $this->assertSame('user.welcome', $job->event);
                $this->assertJobHasNoSecrets($job, $user);

                return true;
            });
        }
    }

    #[DataProvider('panels')]
    public function test_mpin_reset_queues_a_message_keyed_by_time(string $actor, string $prefix): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-10-07 21:15:00');

        $this->actAs($actor);
        $mpin = $this->postJson("{$prefix}/users/{$this->ctx['user']->id}/mpin")->assertOk()->json('mpin');
        $user = $this->ctx['user']->refresh();
        $this->assertSame($mpin, $user->mpin);

        Queue::assertPushed(SendWaHubMessage::class, function (SendWaHubMessage $job) use ($user) {
            $this->assertSame("user-{$user->id}-mpin-" . Carbon::now()->getTimestamp(), $job->idempotencyKey);
            $this->assertSame('mpin.reset', $job->event);
            $this->assertJobHasNoSecrets($job, $user);

            return true;
        });

        Carbon::setTestNow();
    }

    #[DataProvider('panels')]
    public function test_bonus_approval_queues_a_message(string $actor, string $prefix): void
    {
        Queue::fake();
        $redemption = $this->redemption();
        $declined = $this->redemption();

        $this->actAs($actor);
        $this->patchJson("{$prefix}/bonus-codes/redemptions/{$redemption->id}", ['status' => 'fulfilled'])->assertOk();
        $this->patchJson("{$prefix}/bonus-codes/redemptions/{$declined->id}", ['status' => 'rejected'])->assertOk();

        Queue::assertPushed(SendWaHubMessage::class, fn (SendWaHubMessage $job) => $job->idempotencyKey === "bonus-{$redemption->id}-approved"
            && $job->event === 'bonus.approved');
        Queue::assertPushed(SendWaHubMessage::class, 1);
    }

    public function test_nothing_is_queued_while_switched_off_or_not_configured(): void
    {
        Queue::fake();
        $this->actAs('admin');

        $this->setGlobal(['wa_hub_enabled' => false]);
        $this->patchJson('/api/admin/deposits/' . $this->deposit()->id . '/status', ['status' => 'approved'])->assertOk();

        $this->setGlobal(['wa_hub_enabled' => true]);
        config(['services.wahub.key' => '']);
        $this->patchJson('/api/admin/deposits/' . $this->deposit()->id . '/status', ['status' => 'approved'])->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_a_broken_queue_never_breaks_the_request(): void
    {
        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('jobs table missing'));
        Http::fake();

        $this->actAs('admin');
        $this->patchJson('/api/admin/deposits/' . $this->deposit()->id . '/status', ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    // ---- The job ----------------------------------------------------------

    private function runJob(string $event, string $refType, int $refId, string $key, ?array $actor = null): void
    {
        (new SendWaHubMessage($event, $refType, $refId, $key, $actor))->handle(app(WaHubClient::class));
    }

    public function test_job_posts_deposit_vars_to_the_hub(): void
    {
        Http::fake(fn () => Http::response(['message' => ['id' => 1, 'status' => 'queued']], 202));
        $deposit = $this->deposit(['status' => Deposit::STATUS_APPROVED, 'approved_at' => Carbon::parse('2026-10-07 21:15:00', 'Asia/Kolkata')]);
        $actor = ['type' => 'admin', 'id' => '4', 'name' => 'Ravi Admin'];

        $this->runJob('deposit.approved', 'deposit', $deposit->id, "deposit-{$deposit->id}-approved", $actor);

        Http::assertSent(function (Request $request) use ($deposit, $actor) {
            $this->assertSame('POST', $request->method());
            $this->assertSame('/v1/messages', self::hubPath($request));
            $this->assertSame([
                'connection' => 'branch-' . $this->ctx['branch']->id,
                'event' => 'deposit.approved',
                'to' => '919876543210',
                'name' => 'Ravi',
                'vars' => [
                    'name' => 'Ravi',
                    'amount' => '1,25,000',
                    'play_id' => 'RAVI77',
                    'utr' => '401100000101',
                    'date' => '07 Oct 2026, 09:15 PM',
                    'branch_name' => 'South Branch',
                ],
                'ref' => ['type' => 'deposit', 'id' => (string) $deposit->id, 'user_id' => (string) $this->ctx['user']->id],
                'idempotency_key' => "deposit-{$deposit->id}-approved",
                'actor' => $actor,
            ], self::hubJson($request));

            return true;
        });
    }

    public function test_job_posts_rejection_reason_and_masked_destination(): void
    {
        Http::fake(fn () => Http::response(['message' => ['id' => 1, 'status' => 'skipped', 'skip_reason' => 'event_off']], 202));

        $deposit = $this->deposit(['status' => Deposit::STATUS_REJECTED, 'notes' => 'UTR not found']);
        $this->runJob('deposit.rejected', 'deposit', $deposit->id, 'k1');

        $paid = $this->withdrawal(['status' => Withdrawal::STATUS_APPROVED, 'processed_at' => now()]);
        $this->runJob('withdrawal.approved', 'withdrawal', $paid->id, 'k2');

        $bank = $this->withdrawal(['status' => Withdrawal::STATUS_APPROVED, 'destination_type' => 'bank', 'upi_id' => null, 'account_number' => '0012 3456 7890 1234']);
        $this->runJob('withdrawal.approved', 'withdrawal', $bank->id, 'k3');

        $refused = $this->withdrawal(['status' => Withdrawal::STATUS_REJECTED, 'notes' => 'Wrong UPI']);
        $this->runJob('withdrawal.rejected', 'withdrawal', $refused->id, 'k4');

        $sent = collect(Http::recorded())->map(fn ($pair) => self::hubJson($pair[0]))->keyBy('idempotency_key');

        $this->assertSame('UTR not found', $sent['k1']['vars']['reason']);
        $this->assertSame('1,25,000', $sent['k1']['vars']['amount']);
        $this->assertSame('UPI ••••mar@ybl', $sent['k2']['vars']['destination']);
        $this->assertSame('1,234.50', $sent['k2']['vars']['amount']);
        $this->assertSame('A/C ••••1234', $sent['k3']['vars']['destination']);
        $this->assertSame('Wrong UPI', $sent['k4']['vars']['reason']);
        $this->assertArrayNotHasKey('destination', $sent['k4']['vars']);
        $this->assertArrayNotHasKey('actor', $sent['k4']);
    }

    public function test_job_reads_the_current_mpin_at_send_time(): void
    {
        Http::fake(fn () => Http::response(['message' => ['id' => 1]], 202));
        $user = $this->ctx['user'];

        WaHubNotifier::mpinReset($user); // queued on the database queue
        $user->update(['mpin' => '777111']);
        $this->runJob('mpin.reset', 'user', $user->id, 'mpin-key');
        $this->runJob('user.welcome', 'user', $user->id, "user-{$user->id}-welcome");

        $sent = collect(Http::recorded())->map(fn ($pair) => self::hubJson($pair[0]))->keyBy('idempotency_key');

        $this->assertSame([
            'name' => 'Ravi',
            'phone' => '98765 43210',
            'mpin' => '777111',
            'login_link' => 'https://play.southind.test',
            'branch_name' => 'South Branch',
        ], $sent['mpin-key']['vars']);
        $this->assertSame('mpin.reset', $sent['mpin-key']['event']);
        $this->assertSame('RAVI77', $sent["user-{$user->id}-welcome"]['vars']['play_id']);
        $this->assertSame('777111', $sent["user-{$user->id}-welcome"]['vars']['mpin']);

        // No domain → the configured default link.
        $this->ctx['branch']->update(['domain' => null]);
        $this->runJob('mpin.reset', 'user', $user->id, 'mpin-key-2');
        $this->assertSame('https://southindiaexch.com', self::hubJson(Http::recorded()[2][0])['vars']['login_link']);
    }

    public function test_job_posts_bonus_vars(): void
    {
        Http::fake(fn () => Http::response(['message' => ['id' => 1]], 202));
        $redemption = $this->redemption();
        $redemption->update(['status' => BonusCodeRedemption::STATUS_FULFILLED]);

        $this->runJob('bonus.approved', 'bonus_redemption', $redemption->id, "bonus-{$redemption->id}-approved");

        Http::assertSent(fn (Request $r) => self::hubJson($r)['vars'] === [
            'name' => 'Ravi',
            'bonus_code' => 'DIWALI500',
            'amount' => '500',
            'reward_label' => 'Cash bonus',
            'play_id' => 'RAVI77',
            'branch_name' => 'South Branch',
        ] && self::hubJson($r)['ref']['type'] === 'bonus_redemption');
    }

    public function test_job_skips_users_without_a_phone_or_when_switched_off(): void
    {
        Http::fake();

        $this->ctx['user']->update(['phone' => '']);
        $this->runJob('deposit.approved', 'deposit', $this->deposit(['status' => 'approved'])->id, 'k1');

        $this->ctx['user']->update(['phone' => '9876543210']);
        $this->setGlobal(['wa_hub_enabled' => false]);
        $this->runJob('deposit.approved', 'deposit', $this->deposit(['status' => 'approved'])->id, 'k2');

        // Record deleted since it was queued.
        $this->setGlobal(['wa_hub_enabled' => true]);
        $this->runJob('deposit.approved', 'deposit', 999999, 'k3');

        Http::assertNothingSent();
    }

    public function test_job_retries_when_the_hub_is_unreachable(): void
    {
        Http::fake(['*' => Http::response(['error' => 'hub_starting'], 503)]);
        $job = new SendWaHubMessage('deposit.approved', 'deposit', $this->deposit(['status' => 'approved'])->id, 'k1');

        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300, 900], $job->backoff());

        $this->expectException(WaHubUnavailableException::class);
        $job->handle(app(WaHubClient::class));
    }

    // ---- Formatting -------------------------------------------------------

    public function test_amounts_dates_and_destinations_are_formatted_for_india(): void
    {
        $this->assertSame('1,25,000', WaHubNotifier::amount(125000));
        $this->assertSame('1,00,00,000', WaHubNotifier::amount('10000000.00'));
        $this->assertSame('999', WaHubNotifier::amount(999));
        $this->assertSame('1,000', WaHubNotifier::amount(1000));
        $this->assertSame('1,234.50', WaHubNotifier::amount(1234.5));
        $this->assertSame('0.75', WaHubNotifier::amount(0.75));

        $this->assertSame('07 Oct 2026, 09:15 PM', WaHubNotifier::date(Carbon::parse('2026-10-07T15:45:00Z')));
        $this->assertSame('08 Oct 2026, 12:05 AM', WaHubNotifier::date(Carbon::parse('2026-10-08 00:05:00', 'Asia/Kolkata')));

        $upi = fn (string $id) => WaHubNotifier::destination(new Withdrawal(['destination_type' => 'upi', 'upi_id' => $id]));
        $this->assertSame('UPI ••••abc@ybl', $upi('ravi.abc@ybl'));
        $this->assertSame('UPI ••••bc@okaxis', $upi('abc@okaxis'));
        $this->assertSame('A/C ••••4321', WaHubNotifier::destination(new Withdrawal(['destination_type' => 'bank', 'account_number' => '987654321'])));
    }
}
