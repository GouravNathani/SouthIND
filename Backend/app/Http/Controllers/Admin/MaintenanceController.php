<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;

class MaintenanceController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $commands = [
            'optimize:clear',
            'cache:clear',
            'config:clear',
            'route:clear',
            'view:clear',
            'optimize',
        ];

        $results = [];

        foreach ($commands as $command) {
            $exitCode = Artisan::call($command);

            $results[] = [
                'command' => $command,
                'exit_code' => $exitCode,
                'output' => trim(Artisan::output()),
            ];
        }

        return response()->json([
            'message' => 'Cache cleared and application optimized successfully.',
            'steps' => $results,
        ]);
    }
}
