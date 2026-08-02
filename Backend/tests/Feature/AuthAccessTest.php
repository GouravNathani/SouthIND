<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who can log in, and what each role is allowed to reach.
 *
 * The staff boundary is the one that matters commercially: support staff must
 * be able to work the inbox and tag users, but must never reach user CRUD or
 * anything money-adjacent. That split is enforced by route grouping alone, so
 * it is one careless `prevent-staff` edit away from opening up.
 */
class AuthAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_logs_in_with_phone_branch_and_mpin(): void
    {
        $ctx = $this->makeBranch();

        $this->postJson('/api/auth/mpin-login', [
            'phone' => $ctx['user']->phone,
            'branch_code' => $ctx['branch']->code,
            'mpin' => '123456',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'play_id']]);
    }

    public function test_a_wrong_mpin_is_refused(): void
    {
        $ctx = $this->makeBranch();

        $this->postJson('/api/auth/mpin-login', [
            'phone' => $ctx['user']->phone,
            'branch_code' => $ctx['branch']->code,
            'mpin' => '999999',
        ])->assertUnauthorized();
    }

    public function test_admin_and_super_admin_log_in(): void
    {
        $ctx = $this->makeBranch();

        $this->postJson('/api/admin/login', [
            'phone' => $ctx['admin']->phone,
            'password' => 'secret123',
        ])->assertOk()->assertJsonStructure(['token']);

        $this->postJson('/api/super/login', [
            'phone' => $ctx['super']->phone,
            'password' => 'secret123',
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_an_admin_cannot_log_into_the_super_panel(): void
    {
        $ctx = $this->makeBranch();

        $this->postJson('/api/super/login', [
            'phone' => $ctx['admin']->phone,
            'password' => 'secret123',
        ])->assertUnauthorized();
    }

    public function test_staff_can_work_support_but_not_user_crud(): void
    {
        $ctx = $this->makeBranch();

        $staff = Admin::create([
            'name' => 'Support Staff',
            'phone' => '+919777000111',
            'password' => 'secret123',
            'role' => 'staff',
            'is_active' => true,
            'parent_id' => $ctx['admin']->id,
        ]);

        $token = $staff->createToken('test')->plainTextToken;

        // Allowed — support staff need these to do their job.
        $this->withToken($token)->getJson('/api/admin/support/conversations')->assertOk();
        $this->withToken($token)->getJson('/api/admin/tags')->assertOk();

        // Blocked — user CRUD and money features stay admin-only.
        $this->withToken($token)->getJson('/api/admin/users')->assertForbidden();
        $this->withToken($token)->getJson('/api/admin/bonus-codes')->assertForbidden();
    }

    public function test_unauthenticated_requests_are_json_401s(): void
    {
        $this->getJson('/api/admin/deposits')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_an_unknown_api_path_is_a_json_404_not_a_csrf_error(): void
    {
        // The web catch-all excludes api/ precisely so this cannot come back as
        // 419 "CSRF token mismatch" and send someone debugging the wrong thing.
        $this->getJson('/api/definitely-not-a-route')
            ->assertNotFound()
            ->assertJson(['message' => 'Not found.']);
    }
}
