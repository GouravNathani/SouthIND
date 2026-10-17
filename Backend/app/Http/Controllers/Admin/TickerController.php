<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ResolvesBranch;
use App\Support\Ticker\TickerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The running strip on this branch's user home page. */
class TickerController extends Controller
{
    use ResolvesBranch;

    public function show(Request $request): JsonResponse
    {
        $branchId = $this->resolveBranchId($request->user());

        return response()->json(['data' => TickerService::serialize(TickerService::forBranch($branchId))]);
    }

    public function update(Request $request): JsonResponse
    {
        $branchId = $this->resolveBranchId($request->user());
        $ticker = TickerService::save($branchId, TickerService::validated($request), 'Admin: '.($request->user()->name ?? ''));

        return response()->json(['data' => TickerService::serialize($ticker)]);
    }
}
