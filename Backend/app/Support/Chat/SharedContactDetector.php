<?php

namespace App\Support\Chat;

class SharedContactDetector
{
    /**
     * Detect whether the given text contains a shared contact number —
     * any run of more than 5 digits, allowing single spaces/dashes between
     * digits (e.g. "9876543210" or "98765 43210").
     */
    public static function containsNumber(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }

        return (bool) preg_match('/(?:\d[\s-]?){6,}/', $text);
    }
}
