<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_eleventh_guess_for_one_account_in_a_minute_is_refused_with_a_429(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/super/login', ['phone' => '9000000001', 'password' => "guess-$i"])
                ->assertStatus(401);
        }

        // Same account: refused before the password is even checked.
        $this->postJson('/api/super/login', ['phone' => '9000000001', 'password' => 'guess-10'])
            ->assertStatus(429)
            ->assertJsonPath('message', fn ($message) => is_string($message));

        // Another account from the same IP is unaffected.
        $this->postJson('/api/super/login', ['phone' => '9000000002', 'password' => 'guess'])
            ->assertStatus(401);
    }
}
