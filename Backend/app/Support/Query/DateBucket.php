<?php

namespace App\Support\Query;

use Illuminate\Support\Facades\DB;

/**
 * Driver-portable date bucketing for GROUP BY.
 *
 * `DATE_FORMAT(...)` and `DATE(...)` are MySQL spellings. Used raw, every
 * monthly/daily report works in production and throws "no such function" the
 * moment anyone runs it on SQLite — which is what local development and the
 * test suite use. That is how a reporting endpoint ships broken-in-dev and
 * nobody notices until a test is finally written for it.
 *
 * Same approach as the neighbouring DurationAggregate, which already solved
 * this for TIMESTAMPDIFF.
 *
 * Callers pass a raw column or expression (e.g. "created_at" or
 * "COALESCE(approved_at, created_at)") — never user input.
 */
final class DateBucket
{
    /** 'YYYY-MM' */
    public static function month(string $expression): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', {$expression})",
            'pgsql' => "to_char({$expression}, 'YYYY-MM')",
            'sqlsrv' => "FORMAT({$expression}, 'yyyy-MM')",
            default => "DATE_FORMAT({$expression}, '%Y-%m')",
        };
    }

    /** 'YYYY-MM-DD' */
    public static function day(string $expression): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m-%d', {$expression})",
            'pgsql' => "to_char({$expression}, 'YYYY-MM-DD')",
            'sqlsrv' => "FORMAT({$expression}, 'yyyy-MM-dd')",
            default => "DATE({$expression})",
        };
    }
}
