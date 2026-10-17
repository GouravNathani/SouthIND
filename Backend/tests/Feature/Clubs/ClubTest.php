<?php

namespace Tests\Feature\Clubs;

use App\Models\Club;
use App\Models\ClubMember;
use App\Models\Deposit;
use App\Models\User;
use App\Models\Withdrawal;
use App\Support\Club\ClubAssigner;
use App\Support\WaHub\CampaignAudience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Clubs: the system puts each user in the highest club whose day count they
 * reach (distinct IST days with an approved deposit or withdrawal in the club
 * window), or in none below the lowest club.
 */
class ClubTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-17 12:00:00');
        $this->ctx = $this->makeBranch('CLB');
        app(ClubAssigner::class)->seedDefaults($this->ctx['branch']->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $name, ?int $branchId = null): User
    {
        $this->seq++;

        return User::create([
            'name' => $name,
            'phone' => '+91700000' . str_pad((string) $this->seq, 4, '0', STR_PAD_LEFT),
            'password' => 'secret123',
            'mpin' => '123456',
            'play_id' => 'CLB' . $this->seq,
            'status' => User::STATUS_ACTIVE,
            'branch_id' => $branchId ?? $this->ctx['branch']->id,
        ]);
    }

    /** Approved deposits on `$days` distinct days, ending today. */
    private function activeOn(User $user, int $days, int $startDaysAgo = 0, string $status = Deposit::STATUS_APPROVED): void
    {
        for ($i = 0; $i < $days; $i++) {
            $this->seq++;
            Deposit::create([
                'user_id' => $user->id,
                'account_id' => $this->ctx['account']->id,
                'branch_id' => $user->branch_id,
                'play_id' => $user->play_id,
                'amount' => 500,
                'utr_number' => '9' . str_pad((string) $this->seq, 11, '0', STR_PAD_LEFT),
                'status' => $status,
                'approved_at' => Carbon::now()->subDays($startDaysAgo + $i)->setTime(10, 0),
            ]);
        }
    }

    private function withdrawalOn(User $user, int $daysAgo): void
    {
        Withdrawal::create([
            'user_id' => $user->id,
            'branch_id' => $user->branch_id,
            'play_id' => $user->play_id,
            'amount' => 300,
            'destination_type' => 'upi',
            'upi_id' => 'x@ybl',
            'status' => Withdrawal::STATUS_APPROVED,
            'processed_at' => Carbon::now()->subDays($daysAgo)->setTime(18, 0),
        ]);
    }

    private function clubOf(User $user): ?string
    {
        return ClubMember::query()->where('user_id', $user->id)->first()?->club?->name;
    }

    // ── Assignment rules ─────────────────────────────────────────────────────

    public function test_users_land_in_the_highest_club_they_reach(): void
    {
        $diamond = $this->user('Diamond');
        $this->activeOn($diamond, 22);
        $gold = $this->user('Gold');
        $this->activeOn($gold, 14);
        $silver = $this->user('Silver');
        $this->activeOn($silver, 9);
        $comeback = $this->user('Comeback');
        $this->activeOn($comeback, 3);
        $quiet = $this->user('Quiet');
        $this->activeOn($quiet, 2);
        $never = $this->user('Never');

        $result = app(ClubAssigner::class)->refreshBranch($this->ctx['branch']->id);

        $this->assertSame('Diamond Club', $this->clubOf($diamond));
        $this->assertSame('Gold Club', $this->clubOf($gold));
        $this->assertSame('Silver Club', $this->clubOf($silver));
        $this->assertSame('Comeback Club', $this->clubOf($comeback));
        $this->assertNull($this->clubOf($quiet), 'Below the lowest club = no club.');
        $this->assertNull($this->clubOf($never));
        $this->assertSame(4, $result['members']);
        $this->assertSame(22, ClubMember::query()->where('user_id', $diamond->id)->value('active_days'));
    }

    public function test_only_distinct_approved_days_inside_the_window_count(): void
    {
        $user = $this->user('Mixed');
        // 2 deposits today and 1 withdrawal today = 1 day.
        $this->activeOn($user, 1);
        $this->activeOn($user, 1);
        $this->withdrawalOn($user, 0);
        // Withdrawals alone on 2 more days.
        $this->withdrawalOn($user, 5);
        $this->withdrawalOn($user, 6);
        // Rejected deposits and days before the 30-day window do not count.
        $this->activeOn($user, 5, 10, Deposit::STATUS_REJECTED);
        $this->activeOn($user, 5, 30);

        $days = app(ClubAssigner::class)->activeDays($this->ctx['branch']->id);

        $this->assertSame(3, $days[$user->id]);
        app(ClubAssigner::class)->refreshUser($user->id);
        $this->assertSame('Comeback Club', $this->clubOf($user));
    }

    public function test_a_user_drops_out_when_activity_ages_out(): void
    {
        $user = $this->user('Fading');
        $this->activeOn($user, 7, 23); // days 23..29 ago: inside a 30-day window

        app(ClubAssigner::class)->refreshBranch($this->ctx['branch']->id);
        $this->assertSame('Silver Club', $this->clubOf($user));

        Carbon::setTestNow('2026-10-21 12:00:00'); // 4 days later only 3 of those days remain
        app(ClubAssigner::class)->refreshBranch($this->ctx['branch']->id);
        $this->assertSame('Comeback Club', $this->clubOf($user));

        Carbon::setTestNow('2026-10-25 12:00:00');
        app(ClubAssigner::class)->refreshBranch($this->ctx['branch']->id);
        $this->assertNull($this->clubOf($user));
    }

    public function test_approving_a_deposit_refreshes_that_users_club_at_once(): void
    {
        $user = $this->user('Climber');
        $this->activeOn($user, 2, 1);
        $pending = Deposit::create([
            'user_id' => $user->id,
            'account_id' => $this->ctx['account']->id,
            'branch_id' => $user->branch_id,
            'play_id' => $user->play_id,
            'amount' => 900,
            'utr_number' => '777700000001',
            'status' => Deposit::STATUS_PENDING,
        ]);
        $this->assertNull($this->clubOf($user));

        Sanctum::actingAs($this->ctx['admin']);
        $this->patchJson("/api/admin/deposits/{$pending->id}/status", ['status' => 'approved'])->assertOk();

        $this->assertSame('Comeback Club', $this->clubOf($user));
    }

    public function test_seed_defaults_adds_only_the_missing_clubs(): void
    {
        $branchId = $this->ctx['branch']->id;
        $this->assertSame(4, Club::query()->where('branch_id', $branchId)->count());
        $this->assertSame(0, app(ClubAssigner::class)->seedDefaults($branchId));

        Club::query()->where('branch_id', $branchId)->where('name', 'Gold Club')->delete();
        $this->assertSame(1, app(ClubAssigner::class)->seedDefaults($branchId));
    }

    public function test_a_wa_campaign_audience_can_be_clubs(): void
    {
        $gold = $this->user('Goldie');
        $this->activeOn($gold, 15);
        $silver = $this->user('Silvia');
        $this->activeOn($silver, 8);
        $this->user('Nobody');
        app(ClubAssigner::class)->refreshBranch($this->ctx['branch']->id);
        $goldClub = Club::query()->where('branch_id', $this->ctx['branch']->id)->where('name', 'Gold Club')->firstOrFail();

        $result = CampaignAudience::resolve($this->ctx['branch']->id, ['type' => 'clubs', 'club_ids' => [$goldClub->id]]);
        $this->assertSame(1, $result['total']);
        $this->assertSame('Clubs: Gold Club', $result['label']);
        $this->assertSame((string) $gold->id, $result['recipients'][0]['ref_user_id']);

        // Another branch's club is ignored.
        $other = $this->makeBranch('OT2');
        app(ClubAssigner::class)->seedDefaults($other['branch']->id);
        $foreign = Club::query()->where('branch_id', $other['branch']->id)->firstOrFail();
        $this->assertSame(0, CampaignAudience::resolve($this->ctx['branch']->id, ['type' => 'clubs', 'club_ids' => [$foreign->id]])['total']);
    }

    // ── Admin API ────────────────────────────────────────────────────────────

    public function test_admin_lists_creates_updates_and_deletes_clubs(): void
    {
        $user = $this->user('Regular');
        $this->activeOn($user, 10);
        Sanctum::actingAs($this->ctx['admin']);

        $this->getJson('/api/admin/clubs')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.name', 'Diamond Club')
            ->assertJsonPath('meta.window_days', 30)
            ->assertJsonPath('meta.users_total', 2);

        // A new club re-sorts the branch at once.
        $vip = $this->postJson('/api/admin/clubs', ['name' => 'VIP 10', 'color' => '#ff0000', 'min_active_days' => 10])
            ->assertCreated()
            ->assertJsonPath('data.members_count', 1)
            ->json('data');
        $this->assertSame('VIP 10', $this->clubOf($user));

        // Same name or the same day count twice in a branch is refused.
        $this->postJson('/api/admin/clubs', ['name' => 'VIP 10', 'color' => '#ff0000', 'min_active_days' => 11])
            ->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/admin/clubs', ['name' => 'Other', 'color' => '#ff0000', 'min_active_days' => 14])
            ->assertStatus(422)->assertJsonValidationErrors('min_active_days');
        $this->postJson('/api/admin/clubs', ['name' => 'Too long', 'color' => '#ff0000', 'min_active_days' => 31])
            ->assertStatus(422)->assertJsonValidationErrors('min_active_days');

        $this->putJson("/api/admin/clubs/{$vip['id']}", ['name' => 'VIP 12', 'color' => '#00ff00', 'min_active_days' => 12])
            ->assertOk();
        $this->assertSame('Silver Club', $this->clubOf($user));

        $silver = Club::query()->where('name', 'Silver Club')->firstOrFail();
        $this->deleteJson("/api/admin/clubs/{$silver->id}")->assertOk();
        $this->assertSame('Comeback Club', $this->clubOf($user));
    }

    public function test_admin_cannot_touch_another_branchs_club(): void
    {
        $other = $this->makeBranch('OTH');
        app(ClubAssigner::class)->seedDefaults($other['branch']->id);
        $foreign = Club::query()->where('branch_id', $other['branch']->id)->firstOrFail();

        Sanctum::actingAs($this->ctx['admin']);
        $this->putJson("/api/admin/clubs/{$foreign->id}", ['name' => 'X', 'color' => '#000000', 'min_active_days' => 2])
            ->assertNotFound();
        $this->deleteJson("/api/admin/clubs/{$foreign->id}")->assertNotFound();
        $this->assertSame(4, Club::query()->where('branch_id', $other['branch']->id)->count());
    }

    public function test_users_list_filters_by_club_and_shows_the_badge(): void
    {
        $gold = $this->user('Goldie');
        $this->activeOn($gold, 15);
        $this->user('Nobody');
        app(ClubAssigner::class)->refreshBranch($this->ctx['branch']->id);
        $goldClub = Club::query()->where('name', 'Gold Club')->firstOrFail();

        Sanctum::actingAs($this->ctx['admin']);
        $this->getJson("/api/admin/users?club_id={$goldClub->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Goldie')
            ->assertJsonPath('data.0.club.name', 'Gold Club')
            ->assertJsonPath('data.0.club.active_days', 15);

        $names = collect($this->getJson('/api/admin/users?club_id=none')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertContains('Nobody', $names);
        $this->assertNotContains('Goldie', $names);
    }

    // ── Super API ────────────────────────────────────────────────────────────

    public function test_super_creates_a_club_in_every_branch_and_changes_the_window(): void
    {
        $other = $this->makeBranch('TWO');
        $user = $this->user('Steady');
        $this->activeOn($user, 5, 20); // 20..24 days ago
        Sanctum::actingAs($this->ctx['super']);

        $this->postJson('/api/super/clubs', [
            'all_branches' => true, 'name' => 'Starter', 'color' => '#123456', 'min_active_days' => 1,
        ])->assertCreated()->assertJsonCount(2, 'data');
        $this->assertSame(1, Club::query()->where('branch_id', $other['branch']->id)->where('name', 'Starter')->count());
        $this->assertSame('Comeback Club', $this->clubOf($user)); // 5 days: Comeback (3) beats Starter (1)

        // A 14-day window no longer sees any of those days.
        $this->putJson('/api/super/clubs/settings', ['window_days' => 14])->assertOk()->assertJsonPath('data.window_days', 14);
        $this->assertNull($this->clubOf($user));
        $this->getJson('/api/super/clubs')->assertOk()->assertJsonPath('meta.window_days', 14)->assertJsonCount(2, 'data');
        $this->getJson('/api/super/clubs?branch_id=' . $other['branch']->id)->assertOk()->assertJsonPath('data.0.name', 'Starter');

        $this->postJson('/api/super/clubs/defaults', ['branch_id' => $other['branch']->id])
            ->assertOk()->assertJsonPath('data.created', 4);
    }
}
