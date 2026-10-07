<?php

namespace Tests\Feature;

use App\Models\Deposit;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sort and the reverse-order button on the user, deposit and withdrawal lists.
 * Lists are paginated, so the order has to come from the API: page 1 of a
 * reversed list must hold the oldest rows, not the newest page turned around.
 */
class ListOrderTest extends TestCase
{
    use RefreshDatabase;

    /** Three users with distinct join times, names and last-seen times. */
    private function seedUsers(array $ctx): void
    {
        $rows = [
            // play_id, name, created, last_seen
            ['P_OLD', 'Zara', now()->subDays(3), now()->subMinutes(1)],
            ['P_MID', '', now()->subDays(2), null],
            ['P_NEW', 'Arun', now()->subDay(), now()->subHours(5)],
        ];
        foreach ($rows as $i => [$playId, $name, $created, $seen]) {
            $user = User::create([
                'name' => $name,
                'phone' => '+91800000000'.$i,
                'password' => 'secret123',
                'mpin' => '123456',
                'play_id' => $playId,
                'status' => User::STATUS_ACTIVE,
                'branch_id' => $ctx['branch']->id,
            ]);
            $user->forceFill(['created_at' => $created, 'last_seen_at' => $seen])->saveQuietly();
        }
    }

    private function playIds(string $url, string $token): array
    {
        $ids = array_column($this->withToken($token)->getJson($url)->assertOk()->json('data'), 'play_id');

        // makeBranch() adds its own user; keep only the seeded ones.
        return array_values(array_filter($ids, fn ($id) => str_starts_with((string) $id, 'P_')));
    }

    public function test_admin_user_list_sorts_and_reverses(): void
    {
        $ctx = $this->makeBranch('SRT');
        $this->seedUsers($ctx);
        $token = $ctx['admin']->createToken('test')->plainTextToken;

        $this->assertSame(['P_NEW', 'P_MID', 'P_OLD'], $this->playIds('/api/admin/users?per_page=25', $token));
        $this->assertSame(['P_OLD', 'P_MID', 'P_NEW'], $this->playIds('/api/admin/users?per_page=25&reverse=1', $token));
        $this->assertSame(['P_OLD', 'P_NEW', 'P_MID'], $this->playIds('/api/admin/users?per_page=25&sort_by=last_used', $token));
        $this->assertSame(['P_MID', 'P_NEW', 'P_OLD'], $this->playIds('/api/admin/users?per_page=25&sort_by=last_used&reverse=1', $token));
        // A to Z with the unnamed user last; reversed is the exact mirror.
        $this->assertSame(['P_NEW', 'P_OLD', 'P_MID'], $this->playIds('/api/admin/users?per_page=25&sort_by=name', $token));
        $this->assertSame(['P_MID', 'P_OLD', 'P_NEW'], $this->playIds('/api/admin/users?per_page=25&sort_by=name&reverse=1', $token));
    }

    public function test_profit_sort_needs_profit_view_on_the_admin_panel(): void
    {
        $ctx = $this->makeBranch('PRF');
        $this->seedUsers($ctx);
        Deposit::create([
            'user_id' => User::where('play_id', 'P_MID')->value('id'),
            'account_id' => $ctx['account']->id,
            'branch_id' => $ctx['branch']->id,
            'play_id' => 'P_MID',
            'amount' => 900,
            'utr_number' => '401100000201',
            'status' => Deposit::STATUS_APPROVED,
        ]);
        $token = $ctx['admin']->createToken('test')->plainTextToken;

        // Without the permission the totals are not selected: falls back to newest.
        $this->assertSame(['P_NEW', 'P_MID', 'P_OLD'], $this->playIds('/api/admin/users?per_page=25&sort_by=profit', $token));

        $ctx['admin']->forceFill(['allow_profit_view' => true])->save();
        // The guard keeps the admin it resolved on the first request.
        $this->app['auth']->forgetGuards();
        $this->assertSame('P_MID', $this->playIds('/api/admin/users?per_page=25&sort_by=profit', $token)[0]);
        $this->assertSame('P_MID', $this->playIds('/api/admin/users?per_page=25&sort_by=profit&reverse=1', $token)[2]);
    }

    public function test_super_user_list_sorts_reverses_and_keeps_the_old_sort_names(): void
    {
        $ctx = $this->makeBranch('SUP');
        $this->seedUsers($ctx);
        $token = $ctx['super']->createToken('test')->plainTextToken;
        $base = '/api/super/users?per_page=25&branch_id='.$ctx['branch']->id;

        $this->assertSame(['P_NEW', 'P_MID', 'P_OLD'], $this->playIds($base, $token));
        $this->assertSame(['P_OLD', 'P_MID', 'P_NEW'], $this->playIds($base.'&reverse=1', $token));
        $this->assertSame(['P_OLD', 'P_MID', 'P_NEW'], $this->playIds($base.'&sort_by=oldest', $token));
        $this->assertSame(['P_NEW', 'P_OLD', 'P_MID'], $this->playIds($base.'&sort_by=name&status=active', $token));
    }

    public function test_deposit_and_withdrawal_queues_reverse_across_pages(): void
    {
        $ctx = $this->makeBranch('QRV');
        foreach ([3, 2, 1] as $i => $daysAgo) {
            $deposit = Deposit::create([
                'user_id' => $ctx['user']->id,
                'account_id' => $ctx['account']->id,
                'branch_id' => $ctx['branch']->id,
                'play_id' => $ctx['user']->play_id,
                'amount' => 100 * ($i + 1),
                'utr_number' => '40110000030'.$i,
                'status' => Deposit::STATUS_PENDING,
            ]);
            $deposit->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();
            $withdrawal = Withdrawal::create([
                'user_id' => $ctx['user']->id,
                'branch_id' => $ctx['branch']->id,
                'play_id' => $ctx['user']->play_id,
                'amount' => 100 * ($i + 1),
                'destination_type' => 'upi',
                'upi_id' => 'qrv@okaxis',
                'status' => Withdrawal::STATUS_PENDING,
            ]);
            $withdrawal->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();
        }

        $amounts = fn (string $url, string $token) => array_map(
            'floatval',
            array_column($this->withToken($token)->getJson($url)->assertOk()->json('data'), 'amount')
        );
        $admin = $ctx['admin']->createToken('test')->plainTextToken;
        $super = $ctx['super']->createToken('test')->plainTextToken;

        foreach (['/api/admin/deposits', '/api/admin/withdrawals'] as $url) {
            // One row per page: the reversed first page is the oldest request.
            $this->assertSame([300.0], $amounts($url.'?per_page=1', $admin));
            $this->assertSame([100.0], $amounts($url.'?per_page=1&reverse=1', $admin));
        }
        $this->app['auth']->forgetGuards();
        foreach (['/api/super/deposits', '/api/super/withdrawals'] as $url) {
            $this->assertSame([300.0, 200.0, 100.0], $amounts($url.'?per_page=25', $super));
            $this->assertSame([100.0, 200.0, 300.0], $amounts($url.'?per_page=25&reverse=1', $super));
        }
    }
}
