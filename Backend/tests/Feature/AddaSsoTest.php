<?php

namespace Tests\Feature;

use App\Support\Sso\AddaTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A user logged in to Adda lands on SouthIND already logged in, through a
 * signed one-time ticket — and through nothing else.
 */
class AddaSsoTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-shared-secret-0123456789abcdef';

    private array $branch;

    protected function setUp(): void
    {
        parent::setUp();
        config(['adda.sso_secret' => self::SECRET, 'adda.sso_max_age' => 120]);
        $this->branch = $this->makeBranch();
    }

    private function ticket(array $overrides = [], string $secret = self::SECRET): string
    {
        $user = $this->branch['user'];
        $now = time();

        return AddaTicket::sign(array_merge([
            'v' => 1,
            'aud' => 'southind',
            'sub' => $user->id,
            'ph' => $user->phone,
            'iat' => $now,
            'exp' => $now + 60,
            'jti' => Str::random(32),
        ], $overrides), $secret);
    }

    public function test_a_valid_ticket_logs_the_user_in(): void
    {
        $response = $this->postJson('/api/auth/sso/adda', ['ticket' => $this->ticket()])->assertOk();

        $token = $response->json('token');
        $this->assertNotEmpty($token);
        $this->assertSame($this->branch['user']->id, $response->json('user.id'));

        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
    }

    public function test_a_ticket_works_only_once(): void
    {
        $ticket = $this->ticket();

        $this->postJson('/api/auth/sso/adda', ['ticket' => $ticket])->assertOk();
        $this->postJson('/api/auth/sso/adda', ['ticket' => $ticket])->assertUnauthorized();
    }

    public function test_expired_forged_or_mismatched_tickets_are_refused(): void
    {
        $now = time();

        $this->postJson('/api/auth/sso/adda', ['ticket' => $this->ticket(['iat' => $now - 300, 'exp' => $now - 10])])->assertUnauthorized();
        $this->postJson('/api/auth/sso/adda', ['ticket' => $this->ticket(['exp' => $now + 3600])])->assertUnauthorized();
        $this->postJson('/api/auth/sso/adda', ['ticket' => $this->ticket([], 'some-other-secret')])->assertUnauthorized();
        $this->postJson('/api/auth/sso/adda', ['ticket' => $this->ticket(['ph' => '0000000000'])])->assertUnauthorized();
        $this->postJson('/api/auth/sso/adda', ['ticket' => $this->ticket(['aud' => 'adda'])])->assertUnauthorized();
        $this->postJson('/api/auth/sso/adda', ['ticket' => 'not-a-ticket'])->assertUnauthorized();
        $this->postJson('/api/auth/sso/adda', [])->assertUnprocessable();
    }

    public function test_tampering_with_the_payload_breaks_the_signature(): void
    {
        [$body, $signature] = explode('.', $this->ticket());
        $payload = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
        $payload['sub'] = $payload['sub'] + 1;
        $forged = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=') . '.' . $signature;

        $this->postJson('/api/auth/sso/adda', ['ticket' => $forged])->assertUnauthorized();
    }

    public function test_the_hand_off_is_off_without_a_secret(): void
    {
        config(['adda.sso_secret' => '']);

        $this->postJson('/api/auth/sso/adda', ['ticket' => $this->ticket()])->assertNotFound();
    }

    public function test_a_locked_account_is_not_let_in(): void
    {
        $this->branch['user']->forceFill(['mpin_locked_at' => now()])->save();

        $this->postJson('/api/auth/sso/adda', ['ticket' => $this->ticket()])->assertStatus(423);
    }
}
