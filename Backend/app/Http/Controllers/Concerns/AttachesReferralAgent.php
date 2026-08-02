<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Support\Referral\ReferralService;
use RuntimeException;

/**
 * The "Referral Agent" field on the admin create-user forms.
 *
 * Attaching is deliberately best-effort: the user has already been created and
 * their credentials handed to the admin by the time we get here, so a bad agent
 * id or a guard rejection must not fail the request. It comes back as a warning
 * and the admin can attach the user manually from the referral screen.
 */
trait AttachesReferralAgent
{
    /**
     * @param  string|null  $warning  Any warning the caller already collected.
     * @return string|null The combined warning text, or null when all is well.
     */
    protected function attachToAgent(
        User $user,
        int $agentId,
        int $branchId,
        ?int $adminId,
        ?string $warning = null,
    ): ?string {
        $agent = User::query()->where('branch_id', $branchId)->find($agentId);

        $problem = null;

        if (! $agent) {
            $problem = 'Referral agent not found in this branch, so the user was not attached.';
        } else {
            try {
                // retroactive: null — follow the branch's referral setting.
                app(ReferralService::class)->attach(
                    user: $user,
                    referrer: $agent,
                    adminId: $adminId,
                    reason: 'Attached while creating the user.',
                );
            } catch (RuntimeException $e) {
                $problem = 'Referral agent could not be attached: ' . $e->getMessage();
            }
        }

        if (! $problem) {
            return $warning;
        }

        return $warning ? trim($warning . ' ' . $problem) : $problem;
    }
}
