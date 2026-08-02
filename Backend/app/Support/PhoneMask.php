<?php

namespace App\Support;

use App\Models\Admin;
use App\Support\Cache\GlobalSettingCache;
use Illuminate\Support\Facades\Auth;

/**
 * Masks user phone numbers for display in the admin / super-admin panels when the
 * global "mask_user_phone" setting is enabled. Only the last 4 digits stay visible.
 *
 * This is applied at the serialization layer (API resources) so masking is enforced
 * on every page consistently. The `phone` field is not user-editable in the panels,
 * so a masked value can never be written back to the database.
 *
 * Masking only applies to branch admin/staff viewers. Super admins (owners) and a
 * regular end user viewing their own data (profile, their own deposits/withdrawals)
 * always see the real number.
 */
class PhoneMask
{
    public static function enabled(): bool
    {
        $setting = GlobalSettingCache::current();

        return (bool) ($setting?->mask_user_phone ?? false);
    }

    /**
     * Return the phone as-is when masking is off or the viewer is not an admin,
     * otherwise mask all but the last 4 digits.
     */
    public static function apply(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return $phone;
        }

        if (!self::enabled()) {
            return $phone;
        }

        $viewer = Auth::user();

        // End users always see their own real number.
        if (!($viewer instanceof Admin)) {
            return $phone;
        }

        // Super admins (owners) always see the real number — masking exists only to
        // keep branch admins/staff from harvesting customer contact numbers.
        if ($viewer->isSuperAdmin()) {
            return $phone;
        }

        return self::mask($phone);
    }

    public static function mask(string $phone): string
    {
        $length = mb_strlen($phone);
        if ($length <= 4) {
            return $phone;
        }

        return str_repeat('•', $length - 4) . mb_substr($phone, -4);
    }
}
