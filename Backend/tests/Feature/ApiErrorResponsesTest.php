<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Two 500s seen in the production error logs that must be clean client errors.
 */
class ApiErrorResponsesTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unauthenticated_request_without_a_json_accept_header_gets_a_401(): void
    {
        // Plain get(): no `Accept: application/json`, like a bot, a stale tab or
        // a beacon. This used to die with "Route [login] not defined".
        $this->get('/api/accounts')
            ->assertStatus(401)
            ->assertJsonPath('message', fn ($message) => is_string($message));
    }

    public function test_an_absurd_withdrawal_amount_is_rejected_not_a_database_error(): void
    {
        $branch = Branch::create(['name' => 'Main', 'code' => 'MAIN']);
        $user = User::create([
            'name' => 'Player',
            'phone' => '9000012345',
            'unique_number' => '123456',
            'password' => bcrypt('secret'),
            'branch_id' => $branch->id,
            'status' => User::STATUS_ACTIVE,
        ]);

        // A real token; panels with `device-bound` routes also need its device id.
        $token = $user->createToken('test', ['user']);
        if (Schema::hasColumn('personal_access_tokens', 'device_id')) {
            $token->accessToken->forceFill(['device_id' => 'test-device'])->save();
        }

        // withdrawals.amount is DECIMAL(12,2): anything above 9,999,999,999.99 used
        // to reach MySQL and fail as "Numeric value out of range" (a 500).
        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token->plainTextToken,
            'X-Device-Id' => 'test-device',
        ])->postJson('/api/withdrawals', [
            'amount' => '99999999999999',
            'destination_type' => 'upi',
            'upi_id' => 'player@upi',
        ])->assertStatus(422)->assertJsonValidationErrors('amount');
    }
}
