<?php

namespace App\Support\Bonus;

use App\Models\BonusCode;
use Illuminate\Support\Str;

/**
 * Random, unambiguous codes for the admin "Generate" button. 0/O and 1/I/L are
 * left out — these get read aloud and typed on phones.
 */
class BonusCodeGenerator
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public static function make(int $length = 8, string $prefix = ''): string
    {
        $prefix = BonusCode::normalise($prefix);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = $prefix . self::randomBody($length);

            if (!BonusCode::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        // Astronomically unlikely; fall back to a longer body rather than fail.
        return $prefix . self::randomBody($length + 4);
    }

    /**
     * Bulk-unique codes for a campaign (one personal code per user).
     *
     * @return list<string>
     */
    public static function batch(int $count, int $length = 8, string $prefix = ''): array
    {
        $codes = [];

        while (count($codes) < $count) {
            $code = self::make($length, $prefix);

            if (!in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    private static function randomBody(int $length): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $body = '';

        for ($i = 0; $i < $length; $i++) {
            $body .= $alphabet[random_int(0, $max)];
        }

        return $body;
    }
}
