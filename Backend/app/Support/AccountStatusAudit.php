<?php

namespace App\Support;

use App\Models\Account;
use App\Models\AccountStatusEvent;
use App\Models\Admin;
use Illuminate\Support\Facades\Log;

/**
 * Records why an account's status changed and who changed it: stamped on the
 * account row (the latest change, shown as the list badge) and appended to
 * account_status_events (the history).
 */
class AccountStatusAudit
{
    /** Auto-paused: lifetime approved deposits reached the deposit limit. */
    public const REASON_LIMIT_REACHED = 'limit_reached';

    /** Auto-resumed: the limit was raised above the total, or removed (0/empty). */
    public const REASON_LIMIT_LIFTED = 'limit_lifted';

    /** An admin, staff member or super admin set the status by hand. */
    public const REASON_MANUAL = 'manual';

    public const ROLE_SYSTEM = 'system';

    /**
     * Call AFTER the new status is saved. Best-effort by design: this runs inside
     * deposit approval, so a failed audit write is logged and never allowed to
     * undo or block the status change itself.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function record(
        Account $account,
        ?string $fromStatus,
        string $reason,
        ?Admin $actor = null,
        array $meta = [],
    ): void {
        $role = $actor?->role ?? self::ROLE_SYSTEM;

        try {
            $account->forceFill([
                'status_reason' => $reason,
                'status_changed_by_id' => $actor?->id,
                'status_changed_by_role' => $role,
                'status_changed_at' => now(),
                'status_meta' => $meta ?: null,
            ])->save();

            AccountStatusEvent::create([
                'account_id' => $account->id,
                'from_status' => $fromStatus,
                'to_status' => (string) $account->status,
                'reason' => $reason,
                'actor_id' => $actor?->id,
                'actor_role' => $role,
                'meta' => $meta ?: null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Account status audit failed.', [
                'account_id' => $account->id,
                'to_status' => $account->status,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * {id, name, role} for the panels, or null when there is no such admin.
     *
     * @return array{id: int, name: string, role: string}|null
     */
    public static function summarize(?Admin $admin, ?string $role = null): ?array
    {
        if (!$admin) {
            return null;
        }

        return [
            'id' => (int) $admin->id,
            'name' => (string) $admin->name,
            'role' => (string) ($role ?? $admin->role),
        ];
    }
}
