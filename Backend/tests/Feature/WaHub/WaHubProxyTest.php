<?php

namespace Tests\Feature\WaHub;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The panel WA Hub routes: signed calls to the hub, branch scoping, the super
 * admin's all-branch views, the feature switch and hub outages.
 */
class WaHubProxyTest extends TestCase
{
    use RefreshDatabase;
    use WaHubTestHelpers;

    /** @var array{branch: \App\Models\Branch, super: \App\Models\Admin, admin: \App\Models\Admin, user: \App\Models\User, account: \App\Models\Account} */
    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ctx = $this->makeBranch('WAP');
        $this->configureHub();
    }

    private function asAdmin(): static
    {
        Sanctum::actingAs($this->ctx['admin']);

        return $this;
    }

    private function asSuper(): static
    {
        Sanctum::actingAs($this->ctx['super']);

        return $this;
    }

    private function key(): string
    {
        return 'branch-' . $this->ctx['branch']->id;
    }

    // ---- Signing ----------------------------------------------------------

    public function test_every_hub_request_is_signed_over_path_timestamp_nonce_and_raw_body(): void
    {
        Http::fake(fn () => Http::response(['ok' => true]));

        $this->asAdmin()->putJson('/api/admin/wa/events/deposit.approved', [
            'enabled' => true,
            'body' => "✅ {{name}} जी, ₹{{amount}} mil gaye / https://x.y",
        ])->assertOk();

        $this->getJson('/api/admin/wa/messages?status=sent&q=3210')->assertOk();

        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);

        $nonces = [];
        foreach ($recorded as [$request]) {
            $timestamp = $request->header('X-Timestamp')[0] ?? '';
            $nonce = $request->header('X-Nonce')[0] ?? '';

            // Recomputed here from what went over the wire, not from the client.
            $canonical = implode("\n", [$request->method(), self::hubPath($request), $timestamp, $nonce, hash('sha256', $request->body())]);

            $this->assertSame($this->hubKey, $request->header('X-Api-Key')[0] ?? null);
            $this->assertSame(hash_hmac('sha256', $canonical, $this->hubSecret), $request->header('X-Signature')[0] ?? null);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $nonce);
            $this->assertLessThanOrEqual(5, abs(time() - (int) $timestamp));
            $this->assertStringStartsWith('application/json', $request->header('Content-Type')[0] ?? '');
            $this->assertSame('application/json', $request->header('Accept')[0] ?? null);
            $this->assertStringNotContainsString($this->hubSecret, json_encode($request->headers()) . $request->body());
            $nonces[] = $nonce;
        }
        $this->assertCount(2, array_unique($nonces));

        // The PUT body is the exact JSON that was hashed: unicode and slashes unescaped.
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && self::hubPath($request) === "/v1/connections/{$this->key()}/events/deposit.approved"
            && str_contains($request->body(), 'जी')
            && str_contains($request->body(), 'https://x.y')
            && self::hubJson($request) === ['enabled' => true, 'body' => "✅ {{name}} जी, ₹{{amount}} mil gaye / https://x.y"]);

        // GET: empty body, query string not part of the signed path.
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->body() === ''
            && self::hubPath($request) === '/v1/messages'
            && self::hubQuery($request) === ['status' => 'sent', 'q' => '3210', 'connection' => $this->key()]);
    }

    public function test_hub_without_key_or_secret_is_unavailable_and_never_called(): void
    {
        config(['services.wahub.secret' => null]);
        Http::fake();

        $this->asAdmin()->getJson('/api/admin/wa/events')
            ->assertStatus(502)
            ->assertJsonPath('code', 'wa_hub_unavailable');

        Http::assertNothingSent();
    }

    // ---- Switch & outages -------------------------------------------------

    public function test_switch_off_blocks_admin_and_super_routes(): void
    {
        $this->setGlobal(['wa_hub_enabled' => false]);
        Http::fake();

        $this->asAdmin()->getJson('/api/admin/wa/connection')
            ->assertForbidden()
            ->assertJsonPath('code', 'wa_hub_disabled');
        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('features.wa_hub', false);

        $this->asSuper()->getJson('/api/super/wa/connections')
            ->assertForbidden()
            ->assertJsonPath('code', 'wa_hub_disabled');

        Http::assertNothingSent();
    }

    public function test_super_admin_switches_the_feature_and_me_reports_it(): void
    {
        $this->setGlobal(['wa_hub_enabled' => false]);

        $this->asSuper()->patchJson('/api/super/global-settings', ['wa_hub_enabled' => true])
            ->assertOk()
            ->assertJsonPath('wa_hub_enabled', true);
        $this->getJson('/api/super/me')->assertOk()->assertJsonPath('features.wa_hub', true);
        $this->getJson('/api/super/global-settings')->assertOk()->assertJsonPath('data.wa_hub_enabled', true);

        $this->asAdmin()->getJson('/api/admin/me')->assertOk()->assertJsonPath('features.wa_hub', true);
        $this->patchJson('/api/super/global-settings', ['wa_hub_enabled' => false])->assertForbidden();
    }

    public function test_staff_cannot_use_the_admin_routes(): void
    {
        $staff = \App\Models\Admin::create([
            'name' => 'Staff',
            'phone' => '9811100001',
            'password' => 'secret123',
            'role' => 'staff',
            'is_active' => true,
            'branch_id' => $this->ctx['branch']->id,
        ]);
        Http::fake();

        Sanctum::actingAs($staff);
        $this->getJson('/api/admin/wa/connection')->assertForbidden();

        Http::assertNothingSent();
    }

    /**
     * One fake for the whole test whose reply can be swapped between calls
     * (a second Http::fake would not replace the first stub).
     */
    private function fakeHubReplies(): \stdClass
    {
        $hub = new \stdClass();
        $hub->reply = fn () => Http::response(['ok' => true]);
        Http::fake(fn (Request $request) => ($hub->reply)($request));

        return $hub;
    }

    public function test_hub_starting_or_down_answers_502(): void
    {
        $hub = $this->fakeHubReplies();

        $hub->reply = fn () => Http::response(['error' => 'hub_starting'], 503);
        $this->asAdmin()->getJson('/api/admin/wa/events')
            ->assertStatus(502)
            ->assertJsonPath('code', 'wa_hub_unavailable');

        $hub->reply = fn () => throw new ConnectionException('Connection timed out');
        $this->getJson('/api/admin/wa/usage')
            ->assertStatus(502)
            ->assertJsonPath('code', 'wa_hub_unavailable');

        $hub->reply = fn () => Http::response('<html>Bad gateway</html>', 200);
        $this->getJson('/api/admin/wa/templates')
            ->assertStatus(502)
            ->assertJsonPath('code', 'wa_hub_unavailable');
    }

    public function test_hub_4xx_errors_pass_through_unchanged(): void
    {
        $hub = $this->fakeHubReplies();

        $hub->reply = fn () => Http::response(['error' => 'unknown_variable', 'message' => 'Unknown variable {{foo}}'], 422);
        $this->asAdmin()->putJson('/api/admin/wa/events/deposit.approved', ['body' => 'Hi {{foo}}'])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'unknown_variable', 'message' => 'Unknown variable {{foo}}']);

        // Control switched this panel's hub account off.
        $hub->reply = fn () => Http::response(['error' => 'tenant_disabled', 'message' => 'WhatsApp Hub is disabled for this account'], 403);
        $this->getJson('/api/admin/wa/events')
            ->assertForbidden()
            ->assertExactJson(['error' => 'tenant_disabled', 'message' => 'WhatsApp Hub is disabled for this account']);
        $this->asSuper()->getJson('/api/super/wa/connections')
            ->assertForbidden()
            ->assertJsonPath('error', 'tenant_disabled');
    }

    public function test_a_refused_signature_counts_as_unavailable(): void
    {
        $hub = $this->fakeHubReplies();

        // Wrong key / secret: the hub answers a bare 404 not_found to everything.
        $hub->reply = fn () => Http::response(['error' => 'not_found'], 404);
        $this->asAdmin()->getJson('/api/admin/wa/events')
            ->assertStatus(502)
            ->assertJsonPath('code', 'wa_hub_unavailable');

        // A missing record keeps its own code and status.
        $hub->reply = fn () => Http::response(['error' => 'template_not_found', 'message' => 'Template not found'], 404);
        $this->deleteJson('/api/admin/wa/templates/77')
            ->assertNotFound()
            ->assertJsonPath('error', 'template_not_found');
    }

    public function test_event_switch_goes_to_the_hub_as_a_real_boolean(): void
    {
        Http::fake(fn () => Http::response(['key' => 'mpin.reset', 'enabled' => false]));

        $this->asAdmin()->putJson('/api/admin/wa/events/mpin.reset', ['enabled' => '0'])->assertOk();
        $this->putJson('/api/admin/wa/events/mpin.reset', ['body' => null])->assertOk();

        Http::assertSent(fn (Request $r) => self::hubJson($r) === ['enabled' => false]);
        // `body: null` (reset to default) is forwarded as null, not dropped.
        Http::assertSent(fn (Request $r) => $r->body() === '{"body":null}');
    }

    // ---- Admin: own branch only -------------------------------------------

    public function test_admin_connection_is_created_with_the_branch_label_on_first_visit(): void
    {
        $label = $this->ctx['branch']->name;

        Http::fake(function (Request $request) use ($label) {
            if ($request->method() === 'GET') {
                return Http::response(['error' => 'connection_not_found'], 404);
            }

            return Http::response(self::connectionObject($this->key(), $label, ['status' => 'not_linked']));
        });

        $this->asAdmin()->getJson('/api/admin/wa/connection')
            ->assertOk()
            ->assertJsonPath('key', $this->key())
            ->assertJsonPath('status', 'not_linked');

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && self::hubPath($request) === "/v1/connections/{$this->key()}"
            && self::hubJson($request) === ['label' => $label]);
    }

    public function test_admin_routes_always_use_their_own_branch_key(): void
    {
        $other = $this->makeBranch('WAO');
        Http::fake(fn () => Http::response(['data' => [], 'meta' => ['total' => 0, 'page' => 1, 'per_page' => 25]]));

        $this->asAdmin()->getJson('/api/admin/wa/messages?connection=branch-' . $other['branch']->id . '&status=failed')->assertOk();
        $this->getJson('/api/admin/wa/campaigns?connection=branch-' . $other['branch']->id)->assertOk();
        $this->getJson('/api/admin/wa/usage')->assertOk();
        $this->postJson('/api/admin/wa/connection/link')->assertOk();
        $this->postJson('/api/admin/wa/templates', ['name' => 'Diwali', 'body' => 'Hi {{name}}'])->assertOk();

        Http::assertSent(fn (Request $r) => self::hubPath($r) === '/v1/messages' && self::hubQuery($r) === ['status' => 'failed', 'connection' => $this->key()]);
        Http::assertSent(fn (Request $r) => self::hubPath($r) === '/v1/campaigns' && self::hubQuery($r) === ['connection' => $this->key()]);
        Http::assertSent(fn (Request $r) => self::hubPath($r) === '/v1/usage' && self::hubQuery($r) === ['connection' => $this->key()]);
        Http::assertSent(fn (Request $r) => self::hubPath($r) === "/v1/connections/{$this->key()}/link"
            && self::hubJson($r) === ['label' => $this->ctx['branch']->name]);
        Http::assertSent(fn (Request $r) => self::hubPath($r) === "/v1/connections/{$this->key()}/templates" && $r->method() === 'POST');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'branch-' . $other['branch']->id));
    }

    public function test_admin_cannot_see_or_cancel_another_branchs_campaign(): void
    {
        Http::fake(function (Request $request) {
            if (self::hubPath($request) === '/v1/campaigns/5') {
                return Http::response(['id' => 5, 'connection' => 'branch-999', 'status' => 'running']);
            }

            return Http::response(['id' => 6, 'connection' => $this->key(), 'status' => 'cancelled']);
        });

        $this->asAdmin()->getJson('/api/admin/wa/campaigns/5')
            ->assertNotFound()
            ->assertJsonPath('error', 'campaign_not_found');
        $this->postJson('/api/admin/wa/campaigns/5/cancel')->assertNotFound();

        Http::assertNotSent(fn (Request $r) => self::hubPath($r) === '/v1/campaigns/5/cancel');

        $this->getJson('/api/admin/wa/campaigns/6')->assertOk()->assertJsonPath('id', 6);
        $this->postJson('/api/admin/wa/campaigns/6/cancel')->assertOk();
        Http::assertSent(fn (Request $r) => self::hubPath($r) === '/v1/campaigns/6/cancel' && $r->body() === '{}');
    }

    public function test_admin_connection_update_never_forwards_enabled(): void
    {
        Http::fake(fn () => Http::response(self::connectionObject($this->key(), 'x')));

        $this->asAdmin()->putJson('/api/admin/wa/connection', [
            'header' => 'South Branch',
            'footer' => null,
            'enabled' => false,
        ])->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && self::hubJson($r) === ['label' => $this->ctx['branch']->name, 'header' => 'South Branch', 'footer' => '']);
    }

    public function test_test_message_defaults_to_the_admins_own_phone(): void
    {
        $this->ctx['admin']->update(['phone' => '98765 00001']);
        Http::fake(fn () => Http::response(['message' => ['id' => 1, 'kind' => 'test', 'status' => 'queued']], 202));

        $this->asAdmin()->postJson('/api/admin/wa/test', ['text' => 'Hello'])
            ->assertStatus(202)
            ->assertJsonPath('message.kind', 'test');
        $this->postJson('/api/admin/wa/test', ['to' => '9123456789', 'text' => 'Hi'])->assertStatus(202);

        Http::assertSent(fn (Request $r) => self::hubPath($r) === "/v1/connections/{$this->key()}/test"
            && self::hubJson($r) === ['to' => '919876500001', 'text' => 'Hello']);
        Http::assertSent(fn (Request $r) => self::hubJson($r) === ['to' => '919123456789', 'text' => 'Hi']);

        $this->ctx['admin']->update(['phone' => '']);
        $this->postJson('/api/admin/wa/test', ['text' => 'Hello'])->assertStatus(422)->assertJsonValidationErrors('to');
    }

    public function test_admin_message_numbers_are_masked_when_mask_user_phone_is_on(): void
    {
        $messages = ['data' => [[
            'id' => 1,
            'connection' => $this->key(),
            'to' => '919876543210',
            'to_name' => 'Ravi',
            'body' => "Welcome Ravi, login with 9876543210 (919876543210).\nPhone: +91 98765-43210 / 098765 43210. MPIN: ••••",
            'status' => 'sent',
        ]], 'meta' => ['total' => 1, 'page' => 1, 'per_page' => 25]];
        Http::fake(fn () => Http::response($messages));

        $this->asAdmin()->getJson('/api/admin/wa/messages')
            ->assertOk()
            ->assertJsonPath('data.0.to', '919876543210');

        $this->setGlobal(['mask_user_phone' => true]);

        $response = $this->getJson('/api/admin/wa/messages')->assertOk()
            ->assertJsonPath('data.0.to', '91******3210')
            ->assertJsonPath('meta.total', 1);
        $this->assertStringNotContainsString('9876543210', $response->getContent());
        $this->assertStringNotContainsString('98765', $response->getContent());
        $this->assertStringNotContainsString('43210', $response->getContent());
        $this->assertSame(
            "Welcome Ravi, login with 91******3210 (91******3210).\nPhone: 91******3210 / 91******3210. MPIN: ••••",
            $response->json('data.0.body'),
        );

        // The super admin always sees the real number, plus the branch.
        $this->asSuper()->getJson('/api/super/wa/messages')
            ->assertOk()
            ->assertJsonPath('data.0.to', '919876543210')
            ->assertJsonPath('data.0.branch_id', $this->ctx['branch']->id)
            ->assertJsonPath('data.0.branch_name', $this->ctx['branch']->name);
    }

    // ---- Super ------------------------------------------------------------

    public function test_super_connections_list_one_row_per_branch(): void
    {
        $other = $this->makeBranch('WAS');
        $label = $this->ctx['branch']->name;

        Http::fake(fn () => Http::response(['connections' => [
            self::connectionObject($this->key(), $label),
            self::connectionObject('branch-9999', 'Deleted branch'),
        ]]));

        $this->asSuper()->getJson('/api/super/wa/connections')
            ->assertOk()
            ->assertJsonCount(2, 'connections')
            ->assertJsonPath('connections.0.key', $this->key())
            ->assertJsonPath('connections.0.status', 'connected')
            ->assertJsonPath('connections.0.branch_id', $this->ctx['branch']->id)
            ->assertJsonPath('connections.0.branch_name', $label)
            ->assertJsonPath('connections.1.key', 'branch-' . $other['branch']->id)
            ->assertJsonPath('connections.1.status', 'not_linked')
            ->assertJsonPath('connections.1.branch_name', $other['branch']->name);
    }

    public function test_super_enables_and_disables_a_branch_number(): void
    {
        Http::fake(fn (Request $r) => Http::response(self::connectionObject($this->key(), 'x', ['enabled' => self::hubJson($r)['enabled']])));

        $this->asSuper()->postJson("/api/super/wa/connections/{$this->ctx['branch']->id}/disable")
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('branch_id', $this->ctx['branch']->id);
        $this->postJson("/api/super/wa/connections/{$this->ctx['branch']->id}/enable")->assertOk()->assertJsonPath('enabled', true);
        $this->postJson('/api/super/wa/connections/987654/enable')->assertNotFound();

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && self::hubPath($r) === "/v1/connections/{$this->key()}"
            && self::hubJson($r) === ['label' => $this->ctx['branch']->name, 'enabled' => false]);
        Http::assertSentCount(2);
    }

    public function test_super_filters_by_branch_or_sees_all(): void
    {
        Http::fake(fn () => Http::response([
            'data' => [['id' => 5, 'connection' => $this->key(), 'title' => 'Diwali']],
            'meta' => ['total' => 1, 'page' => 1, 'per_page' => 25],
        ]));

        $this->asSuper()->getJson('/api/super/wa/campaigns?branch_id=' . $this->ctx['branch']->id)
            ->assertOk()
            ->assertJsonPath('data.0.branch_name', $this->ctx['branch']->name);
        $this->getJson('/api/super/wa/messages?status=failed')->assertOk();

        Http::assertSent(fn (Request $r) => self::hubPath($r) === '/v1/campaigns' && self::hubQuery($r) === ['connection' => $this->key()]);
        Http::assertSent(fn (Request $r) => self::hubPath($r) === '/v1/messages' && self::hubQuery($r) === ['status' => 'failed']);
    }

    public function test_super_usage_rows_carry_the_branch(): void
    {
        Http::fake(fn () => Http::response([
            'today' => ['sent' => 4, 'coins' => 4],
            'month' => ['sent' => 40, 'coins' => 40],
            'by_connection' => [['connection' => $this->key(), 'today_sent' => 4, 'month_sent' => 40, 'month_coins' => 40]],
        ]));

        $this->asSuper()->getJson('/api/super/wa/usage')
            ->assertOk()
            ->assertJsonPath('today.sent', 4)
            ->assertJsonPath('by_connection.0.branch_id', $this->ctx['branch']->id)
            ->assertJsonPath('by_connection.0.branch_name', $this->ctx['branch']->name)
            ->assertJsonPath('coins_per_message', 0.25);

        Http::assertSent(fn (Request $r) => self::hubPath($r) === '/v1/usage' && self::hubQuery($r) === []);
    }
}
