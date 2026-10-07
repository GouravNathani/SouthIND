<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The SuperAdmin deposit/withdrawal queues share a request to the WhatsApp
 * number of the row's branch, which it reads from the branch list.
 */
class SuperBranchWhatsappTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_list_carries_each_branchs_whatsapp_numbers(): void
    {
        $chennai = $this->makeBranch('CHN');
        $madurai = $this->makeBranch('MDU');

        AppSetting::create([
            'branch_id' => $chennai['branch']->id,
            'owner_admin_id' => $chennai['admin']->id,
            'deposit_wa' => '919000000011',
            'withdrawal_wa' => '919000000022',
        ]);

        $branches = collect(
            $this->withToken($chennai['super']->createToken('test')->plainTextToken)
                ->getJson('/api/super/branches')
                ->assertOk()
                ->json('data')
        )->keyBy('id');

        $this->assertSame('919000000011', $branches[$chennai['branch']->id]['deposit_wa']);
        $this->assertSame('919000000022', $branches[$chennai['branch']->id]['withdrawal_wa']);
        // A branch without settings yet still lists, just without numbers.
        $this->assertNull($branches[$madurai['branch']->id]['deposit_wa']);
        $this->assertNull($branches[$madurai['branch']->id]['withdrawal_wa']);
    }
}
