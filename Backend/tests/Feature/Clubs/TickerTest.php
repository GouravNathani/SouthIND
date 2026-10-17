<?php

namespace Tests\Feature\Clubs;

use App\Models\BranchTicker;
use App\Support\Club\ClubAssigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The running strip on the user home page, per branch, and the club badge. */
class TickerTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->makeBranch('TKR');
    }

    public function test_admin_sets_the_strip_and_the_user_sees_it_until_it_is_switched_off(): void
    {
        Sanctum::actingAs($this->ctx['user']);
        $this->getJson('/api/home/extras')->assertOk()->assertExactJson(['ticker' => null, 'club' => null]);

        Sanctum::actingAs($this->ctx['admin']);
        $this->getJson('/api/admin/ticker')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.text', '');
        $this->putJson('/api/admin/ticker', ['enabled' => true, 'text' => "  Live Casino \n Not Allowed  "])
            ->assertOk()
            ->assertJsonPath('data.text', 'Live Casino Not Allowed');

        Sanctum::actingAs($this->ctx['user']);
        $this->getJson('/api/home/extras')->assertOk()->assertJsonPath('ticker.text', 'Live Casino Not Allowed');

        Sanctum::actingAs($this->ctx['admin']);
        $this->putJson('/api/admin/ticker', ['enabled' => false, 'text' => 'Live Casino Not Allowed'])->assertOk();

        Sanctum::actingAs($this->ctx['user']);
        $this->getJson('/api/home/extras')->assertOk()->assertJsonPath('ticker', null);
    }

    public function test_switching_on_with_no_text_is_refused(): void
    {
        Sanctum::actingAs($this->ctx['admin']);
        $this->putJson('/api/admin/ticker', ['enabled' => true, 'text' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('text');
        $this->putJson('/api/admin/ticker', ['enabled' => true, 'text' => str_repeat('x', 301)])
            ->assertStatus(422);
    }

    public function test_super_sets_one_branch_or_all_branches(): void
    {
        $other = $this->makeBranch('TK2');
        Sanctum::actingAs($this->ctx['super']);

        $this->putJson('/api/super/tickers/' . $other['branch']->id, ['enabled' => true, 'text' => 'Only here'])
            ->assertOk()
            ->assertJsonPath('data.branch_name', $other['branch']->name);
        $this->assertFalse(BranchTicker::query()->where('branch_id', $this->ctx['branch']->id)->exists());

        $this->putJson('/api/super/tickers', ['enabled' => true, 'text' => 'Everywhere'])->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/super/tickers')->assertOk()->assertJsonPath('data.0.text', 'Everywhere')->assertJsonPath('data.1.text', 'Everywhere');

        Sanctum::actingAs($other['user']);
        $this->getJson('/api/home/extras')->assertOk()->assertJsonPath('ticker.text', 'Everywhere');
    }

    public function test_home_extras_shows_the_users_club(): void
    {
        app(ClubAssigner::class)->seedDefaults($this->ctx['branch']->id);
        $club = \App\Models\Club::query()->where('name', 'Gold Club')->firstOrFail();
        \App\Models\ClubMember::create([
            'user_id' => $this->ctx['user']->id, 'club_id' => $club->id, 'branch_id' => $this->ctx['branch']->id, 'active_days' => 16,
        ]);

        Sanctum::actingAs($this->ctx['user']);
        $this->getJson('/api/home/extras')
            ->assertOk()
            ->assertJsonPath('club.name', 'Gold Club')
            ->assertJsonPath('club.color', '#f59e0b')
            ->assertJsonPath('club.active_days', 16);
    }

    public function test_admin_tokens_cannot_read_user_home_extras(): void
    {
        Sanctum::actingAs($this->ctx['admin']);
        $this->getJson('/api/home/extras')->assertForbidden();
    }
}
