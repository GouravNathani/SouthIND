<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Support chat can be switched off by the super admin only. Off blocks the
 * user and branch-admin chat endpoints (so the panels stop polling them),
 * keeps every old message, and leaves the super admin's own view open.
 */
class SupportChatToggleTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['name' => 'Main', 'code' => 'MAIN']);
    }

    private function admin(string $role = 'admin'): Admin
    {
        static $n = 0;
        $n++;

        return Admin::create([
            'name' => ucfirst($role) . " {$n}",
            'email' => "chat-{$role}{$n}@test.local",
            'phone' => '98200' . str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'password' => 'secret',
            'role' => $role,
            'is_active' => true,
            'branch_id' => $role === 'super_admin' ? null : $this->branch->id,
        ]);
    }

    private function user(): User
    {
        return User::create([
            'name' => 'Chat User',
            'phone' => '90000' . random_int(10000, 99999),
            'unique_number' => (string) random_int(100000, 999999),
            'password' => bcrypt('secret'),
            'branch_id' => $this->branch->id,
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    /**
     * A real token, so the same test runs on panels whose routes are `device-bound`
     * (they also need the token's device id in X-Device-Id).
     */
    private function actAs(Admin|User $who): static
    {
        $token = $who->createToken('test');
        if (Schema::hasColumn('personal_access_tokens', 'device_id')) {
            $token->accessToken->forceFill(['device_id' => 'test-device'])->save();
        }

        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $token->plainTextToken,
            'X-Device-Id' => 'test-device',
        ]);
    }

    private function switchSupportChat(bool $on): void
    {
        $this->actAs($this->admin('super_admin'))->patchJson('/api/super/global-settings', ['support_chat_enabled' => $on])
            ->assertOk()
            ->assertJsonPath('support_chat_enabled', $on);
    }

    public function test_support_chat_is_on_by_default(): void
    {
        $this->actAs($this->user())->getJson('/api/support/chat')->assertOk();

        $this->actAs($this->admin())->getJson('/api/admin/support/conversations')->assertOk();
        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('features.support_chat', true);
    }

    public function test_switching_off_blocks_user_and_branch_admin_but_not_super_admin(): void
    {
        $user = $this->user();
        $admin = $this->admin();

        $this->switchSupportChat(false);

        $this->actAs($user)->getJson('/api/support/chat')
            ->assertForbidden()
            ->assertJsonPath('code', 'support_chat_disabled');
        $this->postJson('/api/support/chat', ['body' => 'hello'])
            ->assertForbidden()
            ->assertJsonPath('code', 'support_chat_disabled');

        $this->actAs($admin)->getJson('/api/admin/support/conversations')
            ->assertForbidden()
            ->assertJsonPath('code', 'support_chat_disabled');
        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('features.support_chat', false);

        $this->actAs($this->admin('super_admin'))->getJson('/api/super/support/conversations')->assertOk();
        $this->getJson('/api/super/me')->assertOk()->assertJsonPath('features.support_chat', false);

        $this->getJson('/api/global-settings')->assertOk()->assertJsonPath('data.support_chat_enabled', false);
    }

    public function test_switching_back_on_keeps_old_messages(): void
    {
        $user = $this->user();
        $this->actAs($user)->postJson('/api/support/chat', ['body' => 'first message'])->assertSuccessful();
        $messages = DB::table('support_messages')->count();

        $this->switchSupportChat(false);
        $this->switchSupportChat(true);

        $this->actAs($user)->getJson('/api/support/chat')->assertOk();
        $this->assertSame($messages, DB::table('support_messages')->count());
        $this->assertGreaterThan(0, $messages);
    }

    public function test_branch_admin_cannot_switch_support_chat(): void
    {
        $this->actAs($this->admin());

        $this->patchJson('/api/super/global-settings', ['support_chat_enabled' => false])->assertForbidden();
        $this->getJson('/api/admin/support/conversations')->assertOk();
    }
}
