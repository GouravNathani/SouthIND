<?php

namespace App\Support\Query;

use Illuminate\Support\Facades\DB;

final class DurationAggregate
{
    public static function avgSecondsExpression(string $startColumn, string $endColumn): string
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'pgsql' => "AVG(ABS(EXTRACT(EPOCH FROM ({$endColumn} - {$startColumn}))))",
            'sqlite' => "AVG(ABS((julianday({$endColumn}) - julianday({$startColumn})) * 86400.0))",
            'sqlsrv' => "AVG(ABS(DATEDIFF(SECOND, {$startColumn}, {$endColumn})))",
            default => "AVG(ABS(TIMESTAMPDIFF(SECOND, {$startColumn}, {$endColumn})))",
        };
    }
}
