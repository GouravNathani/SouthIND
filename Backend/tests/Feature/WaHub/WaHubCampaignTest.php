<?php

namespace Tests\Feature\WaHub;

use App\Models\Tag;
use App\Models\User;
use App\Support\WaHub\CampaignAudience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Campaign audiences are resolved inside the admin's own branch: tags or
 * explicit users, active users with a phone only, one message per number.
 */
class WaHubCampaignTest extends TestCase
{
    use RefreshDatabase;
    use WaHubTestHelpers;

    /** @var array{branch: \App\Models\Branch, super: \App\Models\Admin, admin: \App\Models\Admin, user: User, account: \App\Models\Account} */
    private array $ctx;

    /** @var array{branch: \App\Models\Branch, super: \App\Models\Admin, admin: \App\Models\Admin, user: User, account: \App\Models\Account} */
    private array $other;

    private Tag $vip;

    private Tag $fresh;

    private Tag $otherTag;

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ctx = $this->makeBranch('WAC');
        $this->other = $this->makeBranch('WAX');
        $this->configureHub();

        $branchId = $this->ctx['branch']->id;
        $this->vip = Tag::create(['branch_id' => $branchId, 'name' => 'VIP']);
        $this->fresh = Tag::create(['branch_id' => $branchId, 'name' => 'New']);
        $this->otherTag = Tag::create(['branch_id' => $this->other['branch']->id, 'name' => 'Other VIP']);

        // Same number twice (typed differently), one banned, one without a phone.
        $this->users = [
            'asha' => $this->user('Asha', '9876500001', 'ASHA1', [$this->vip]),
            'asha2' => $this->user('Asha 2', '+91 98765-00001', 'ASHA2', [$this->vip, $this->fresh]),
            'bala' => $this->user('Bala', '09876500002', 'BALA1', [$this->fresh]),
            'banned' => $this->user('Banned', '9876500003', 'BAN1', [$this->vip], User::STATUS_BANNED),
            'nophone' => $this->user('No Phone', '', 'NOPH1', [$this->vip]),
            'untagged' => $this->user('Untagged', '9876500004', 'UNT1', []),
        ];

        // Another branch's user carrying another branch's tag.
        $outsider = $this->other['user'];
        $outsider->update(['phone' => '9876500099']);
        $outsider->tags()->attach($this->otherTag->id);
    }

    /** @param  list<Tag>  $tags */
    private function user(string $name, string $phone, string $playId, array $tags, string $status = User::STATUS_ACTIVE): User
    {
        $user = User::create([
            'name' => $name,
            'phone' => $phone,
            'password' => 'secret123',
            'mpin' => '123456',
            'play_id' => $playId,
            'status' => $status,
            'branch_id' => $this->ctx['branch']->id,
        ]);
        $user->tags()->attach(array_map(fn (Tag $tag) => $tag->id, $tags));

        return $user;
    }

    private function key(): string
    {
        return 'branch-' . $this->ctx['branch']->id;
    }

    // ---- Resolution -------------------------------------------------------

    public function test_tag_audience_takes_users_with_any_tag_and_skips_inactive_no_phone_and_duplicates(): void
    {
        $resolved = CampaignAudience::resolve($this->ctx['branch']->id, [
            'type' => 'tags',
            'tag_ids' => [$this->vip->id, $this->fresh->id, $this->otherTag->id],
        ]);

        $this->assertSame(2, $resolved['total']);
        $this->assertSame(['no_phone' => 1, 'duplicate' => 1, 'inactive' => 1], $resolved['skipped']);
        // Another branch's tag never shows up, not even in the label.
        $this->assertSame('Tags: New, VIP', $resolved['label']);
        $this->assertSame([
            ['to' => '919876500001', 'name' => 'Asha', 'vars' => ['play_id' => 'ASHA1'], 'ref_user_id' => (string) $this->users['asha']->id],
            ['to' => '919876500002', 'name' => 'Bala', 'vars' => ['play_id' => 'BALA1'], 'ref_user_id' => (string) $this->users['bala']->id],
        ], $resolved['recipients']);
    }

    public function test_another_branchs_tag_alone_resolves_to_nobody(): void
    {
        $resolved = CampaignAudience::resolve($this->ctx['branch']->id, ['type' => 'tags', 'tag_ids' => [$this->otherTag->id]]);

        $this->assertSame(0, $resolved['total']);
        $this->assertSame([], $resolved['recipients']);
    }

    public function test_user_audience_ignores_other_branch_users(): void
    {
        $resolved = CampaignAudience::resolve($this->ctx['branch']->id, [
            'type' => 'users',
            'user_ids' => [
                $this->users['bala']->id,
                $this->users['untagged']->id,
                $this->users['banned']->id,
                $this->other['user']->id,
                $this->users['bala']->id,
            ],
        ]);

        $this->assertSame(2, $resolved['total']);
        $this->assertSame(['no_phone' => 0, 'duplicate' => 0, 'inactive' => 1], $resolved['skipped']);
        $this->assertSame('3 users', $resolved['label']);
        $this->assertSame(['919876500002', '919876500004'], array_column($resolved['recipients'], 'to'));
        $this->assertNotContains((string) $this->other['user']->id, array_column($resolved['recipients'], 'ref_user_id'));
    }

    // ---- Endpoints --------------------------------------------------------

    public function test_preview_counts_coins_and_masks_the_sample(): void
    {
        Http::fake();
        Sanctum::actingAs($this->ctx['admin']);

        $this->postJson('/api/admin/wa/campaigns/preview', [
            'audience' => ['type' => 'tags', 'tag_ids' => [$this->vip->id, $this->fresh->id]],
        ])
            ->assertOk()
            ->assertExactJson([
                'total' => 2,
                'skipped' => ['no_phone' => 1, 'duplicate' => 1, 'inactive' => 1],
                'coins' => 2,
                'sample' => [
                    ['id' => $this->users['asha']->id, 'name' => 'Asha', 'phone_masked' => '91******0001'],
                    ['id' => $this->users['bala']->id, 'name' => 'Bala', 'phone_masked' => '91******0002'],
                ],
                'max_recipients' => CampaignAudience::MAX_RECIPIENTS,
            ]);

        $this->postJson('/api/admin/wa/campaigns/preview', ['audience' => ['type' => 'everyone']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('audience.type');

        // Computed here: the hub is not asked.
        Http::assertNothingSent();
    }

    public function test_create_posts_this_branchs_recipients_to_the_hub(): void
    {
        Http::fake(fn () => Http::response(['id' => 5, 'connection' => $this->key(), 'status' => 'scheduled'], 201));
        Sanctum::actingAs($this->ctx['admin']);

        $this->postJson('/api/admin/wa/campaigns', [
            'title' => 'Diwali offer',
            'body' => 'Hi {{name}}, your ID {{play_id}}',
            'audience' => ['type' => 'users', 'user_ids' => [$this->users['asha']->id, $this->users['asha2']->id, $this->other['user']->id]],
            'send_at' => '2026-10-20 10:30:00',
        ])
            ->assertCreated()
            ->assertJsonPath('id', 5);

        Http::assertSent(function (Request $request) {
            $this->assertSame('POST', $request->method());
            $this->assertSame('/v1/campaigns', self::hubPath($request));
            $this->assertSame([
                'connection' => $this->key(),
                'title' => 'Diwali offer',
                'body' => 'Hi {{name}}, your ID {{play_id}}',
                'audience_label' => '2 users',
                // IST input, UTC to the hub.
                'send_at' => '2026-10-20T05:00:00Z',
                'recipients' => [
                    ['to' => '919876500001', 'name' => 'Asha', 'vars' => ['play_id' => 'ASHA1'], 'ref_user_id' => (string) $this->users['asha']->id],
                ],
                'actor' => ['type' => 'admin', 'id' => (string) $this->ctx['admin']->id, 'name' => $this->ctx['admin']->name],
            ], self::hubJson($request));

            return true;
        });
    }

    public function test_create_with_a_saved_template_and_tags(): void
    {
        Http::fake(fn () => Http::response(['id' => 6, 'connection' => $this->key()], 201));
        Sanctum::actingAs($this->ctx['admin']);

        $this->postJson('/api/admin/wa/campaigns', [
            'title' => 'Weekend',
            'template_id' => 9,
            'audience' => ['type' => 'tags', 'tag_ids' => [$this->fresh->id]],
        ])->assertCreated();

        Http::assertSent(fn (Request $r) => self::hubJson($r)['template_id'] === 9
            && !array_key_exists('body', self::hubJson($r))
            && !array_key_exists('send_at', self::hubJson($r))
            && self::hubJson($r)['audience_label'] === 'Tags: New'
            && array_column(self::hubJson($r)['recipients'], 'to') === ['919876500001', '919876500002']);
    }

    public function test_create_with_nobody_to_send_to_never_calls_the_hub(): void
    {
        Http::fake();
        Sanctum::actingAs($this->ctx['admin']);

        $this->postJson('/api/admin/wa/campaigns', [
            'title' => 'Nobody',
            'body' => 'Hi',
            'audience' => ['type' => 'users', 'user_ids' => [$this->users['banned']->id, $this->other['user']->id]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'no_recipients');

        $this->postJson('/api/admin/wa/campaigns', [
            'title' => 'No text',
            'audience' => ['type' => 'tags', 'tag_ids' => [$this->vip->id]],
        ])->assertStatus(422)->assertJsonValidationErrors('body');

        Http::assertNothingSent();
    }
}
