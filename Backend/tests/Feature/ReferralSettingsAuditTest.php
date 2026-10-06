<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ReferralAudit;
use App\Support\Referral\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Changing referral settings writes a branch-level audit row (user_id = 0) that must not hit a users FK. */
class ReferralSettingsAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_referral_settings_records_an_audit_row(): void
    {
        $branch = Branch::create(['name' => 'Main', 'code' => 'MAIN']);

        app(ReferralService::class)->updateSettings($branch->id, ['enabled' => true], null);

        $this->assertSame(1, ReferralAudit::where('branch_id', $branch->id)
            ->where('action', ReferralAudit::ACTION_SETTINGS)->count());
    }
}
