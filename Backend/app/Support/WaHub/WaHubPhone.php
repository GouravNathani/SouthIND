<?php

namespace App\Support\WaHub;

/**
 * Number helpers for the WA Hub. `users.phone` is stored as typed (with or
 * without +91, spaces, dashes), so it is normalised the same way the hub does
 * before two users can be recognised as the same number.
 */
class WaHubPhone
{
    /**
     * Digits only; an Indian 10-digit number (or 0 + 10 digits) gets the 91
     * prefix. Empty string when there is no number at all.
     */
    public static function normalize(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            $digits = '91' . $digits;
        }

        return $digits;
    }

    /** `919876543210` → `91******3210`: country code and last 4 stay visible. */
    public static function mask(?string $number): string
    {
        $number = (string) $number;
        $length = strlen($number);

        if ($length > 6) {
            return substr($number, 0, 2) . str_repeat('*', $length - 6) . substr($number, -4);
        }

        if ($length > 4) {
            return str_repeat('*', $length - 4) . substr($number, -4);
        }

        return $number;
    }

    /** For logs: the last 4 digits only, never the full number. */
    public static function last4(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        return $digits === '' ? '' : '…' . substr($digits, -4);
    }
}
