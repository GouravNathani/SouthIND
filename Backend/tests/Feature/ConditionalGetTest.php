<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ETagResponse — the conditional-GET layer.
 *
 * Three SPAs poll settings, banners and status endpoints on timers. Answering
 * 304 with an empty body is the cheapest saving available, but it is also easy
 * to get subtly wrong: a stale ETag served after a change would leave every
 * client showing old settings indefinitely.
 */
class ConditionalGetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_read_endpoint_returns_an_etag(): void
    {
        $this->makeBranch();

        $this->getJson('/api/global-settings')
            ->assertOk()
            // Symfony reorders the directives; assert on meaning, not spelling.
            ->assertHeader('Cache-Control', 'no-cache, private');

        $this->assertNotEmpty(
            $this->getJson('/api/global-settings')->headers->get('ETag')
        );
    }

    public function test_matching_etag_gets_304_with_no_body(): void
    {
        $this->makeBranch();

        $etag = $this->getJson('/api/global-settings')->headers->get('ETag');

        $response = $this->withHeaders(['If-None-Match' => $etag])
            ->getJson('/api/global-settings')
            ->assertStatus(304);

        $this->assertSame('', $response->getContent());
    }

    public function test_a_weak_etag_from_a_proxy_still_matches(): void
    {
        $this->makeBranch();

        $etag = $this->getJson('/api/global-settings')->headers->get('ETag');

        // Some proxies downgrade to a weak validator; that must still match or
        // the saving silently disappears behind a CDN.
        $this->withHeaders(['If-None-Match' => 'W/'.$etag])
            ->getJson('/api/global-settings')
            ->assertStatus(304);
    }

    public function test_a_stale_etag_gets_the_full_body(): void
    {
        $this->makeBranch();

        $this->withHeaders(['If-None-Match' => '"not-the-current-hash"'])
            ->getJson('/api/global-settings')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_writes_are_never_etagged(): void
    {
        $ctx = $this->makeBranch();

        $response = $this->withToken($ctx['user']->createToken('t')->plainTextToken)
            ->postJson('/api/deposits', [
                'account_id' => $ctx['account']->id,
                'amount' => 1500,
                'utr_number' => '601100000001',
            ])
            ->assertCreated();

        $this->assertNull($response->headers->get('ETag'));
    }
}
