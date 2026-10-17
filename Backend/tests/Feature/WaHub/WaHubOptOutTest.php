<?php

namespace Tests\Feature\WaHub;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** "Exclude from WhatsApp" on the user page (Admin own branch, Super any). */
class WaHubOptOutTest extends TestCase
{
    use RefreshDatabase;
    use WaHubTestHelpers;

    public function test_admin_reads_the_hub_status_for_the_users_number(): void
    {
        $ctx = $this->makeBranch('WOA');
        $ctx['user']->update(['phone' => '+91 98765-43210']);
        $this->configureHub();

        Http::fake(fn () => Http::response([
            'number' => '919876543210', 'opted_out' => true, 'opted_out_at' => '2026-10-17T05:00:00Z', 'reason' => 'stop_reply',
        ]));

        Sanctum::actingAs($ctx['admin']);
        $this->getJson("/api/admin/wa/users/{$ctx['user']->id}/opt-out")
            ->assertOk()
            ->assertExactJson(['has_phone' => true, 'excluded' => true, 'excluded_at' => '2026-10-17T05:00:00Z', 'reason' => 'stop_reply']);

        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && self::hubPath($r) === "/v1/connections/branch-{$ctx['branch']->id}/contacts/919876543210");
    }

    public function test_admin_excludes_and_includes_with_actor(): void
    {
        $ctx = $this->makeBranch('WOB');
        $ctx['user']->update(['phone' => '9876543210']);
        $this->configureHub();

        Http::fake(fn (Request $r) => Http::response([
            'number' => '919876543210', 'opted_out' => self::hubJson($r)['opted_out'], 'opted_out_at' => null, 'reason' => 'manual',
        ]));

        Sanctum::actingAs($ctx['admin']);
        $this->putJson("/api/admin/wa/users/{$ctx['user']->id}/opt-out", ['excluded' => true])
            ->assertOk()->assertJson(['excluded' => true, 'reason' => 'manual']);
        $this->putJson("/api/admin/wa/users/{$ctx['user']->id}/opt-out", ['excluded' => false])
            ->assertOk()->assertJson(['excluded' => false, 'reason' => null]);
        $this->putJson("/api/admin/wa/users/{$ctx['user']->id}/opt-out", [])->assertStatus(422);

        $sent = Http::recorded();
        $this->assertCount(2, $sent);
        $body = self::hubJson($sent[0][0]);
        $this->assertSame('PUT', $sent[0][0]->method());
        $this->assertTrue($body['opted_out']);
        $this->assertSame('admin', $body['actor']['type']);
        $this->assertFalse(self::hubJson($sent[1][0])['opted_out']);
    }

    public function test_admin_cannot_touch_another_branchs_user(): void
    {
        $mine = $this->makeBranch('WOC');
        $other = $this->makeBranch('WOD');
        $this->configureHub();
        Http::fake();

        Sanctum::actingAs($mine['admin']);
        $this->getJson("/api/admin/wa/users/{$other['user']->id}/opt-out")->assertNotFound();
        $this->putJson("/api/admin/wa/users/{$other['user']->id}/opt-out", ['excluded' => true])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_super_uses_the_users_own_branch_number(): void
    {
        $ctx = $this->makeBranch('WOE');
        $ctx['user']->update(['phone' => '9876500000']);
        $this->configureHub();
        Http::fake(fn () => Http::response(['number' => '919876500000', 'opted_out' => true, 'opted_out_at' => null, 'reason' => 'manual']));

        Sanctum::actingAs($ctx['super']);
        $this->putJson("/api/super/wa/users/{$ctx['user']->id}/opt-out", ['excluded' => true])->assertOk()->assertJson(['excluded' => true]);

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && self::hubPath($r) === "/v1/connections/branch-{$ctx['branch']->id}/contacts/919876500000"
            && self::hubJson($r)['actor']['type'] === 'super');
    }

    public function test_no_phone_never_calls_the_hub(): void
    {
        $ctx = $this->makeBranch('WOF');
        $ctx['user']->forceFill(['phone' => ''])->saveQuietly();
        $this->configureHub();
        Http::fake();

        Sanctum::actingAs($ctx['admin']);
        $this->getJson("/api/admin/wa/users/{$ctx['user']->id}/opt-out")->assertOk()->assertJson(['has_phone' => false, 'excluded' => false]);
        $this->putJson("/api/admin/wa/users/{$ctx['user']->id}/opt-out", ['excluded' => true])->assertStatus(422)->assertJson(['code' => 'no_phone']);
        Http::assertNothingSent();
    }

    public function test_switch_off_and_hub_down(): void
    {
        $ctx = $this->makeBranch('WOG');
        $this->configureHub(false);
        Sanctum::actingAs($ctx['admin']);
        $this->getJson("/api/admin/wa/users/{$ctx['user']->id}/opt-out")->assertForbidden();

        $this->configureHub();
        Http::fake(fn () => Http::response(['error' => 'hub_starting'], 503));
        $this->getJson("/api/admin/wa/users/{$ctx['user']->id}/opt-out")->assertStatus(502)->assertJson(['code' => 'wa_hub_unavailable']);
    }

    public function test_message_order_is_passed_to_the_hub(): void
    {
        $ctx = $this->makeBranch('WOH');
        $this->configureHub();
        Http::fake(fn () => Http::response(['data' => [], 'meta' => ['total' => 0, 'page' => 1, 'per_page' => 25]]));

        Sanctum::actingAs($ctx['admin']);
        $this->getJson('/api/admin/wa/messages?order=oldest&status=sent,delivered,read')->assertOk();

        Http::assertSent(fn (Request $r) => self::hubQuery($r)['order'] === 'oldest' && self::hubQuery($r)['status'] === 'sent,delivered,read');
    }
}
