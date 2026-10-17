<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Support\Ticker\TickerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Every branch's home-page strip; set one branch or all of them at once. */
class TickerController extends Controller
{
    public function index(): JsonResponse
    {
        $branches = Branch::query()->orderBy('name')->get(['id', 'name']);

        return response()->json([
            'data' => $branches->map(fn (Branch $branch) => [
                'branch_name' => $branch->name,
            ] + TickerService::serialize(TickerService::forBranch($branch->id)))->values(),
        ]);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        $ticker = TickerService::save($branch->id, TickerService::validated($request), 'Super Admin');

        return response()->json(['data' => ['branch_name' => $branch->name] + TickerService::serialize($ticker)]);
    }

    /** The same text and switch for every branch. */
    public function updateAll(Request $request): JsonResponse
    {
        $data = TickerService::validated($request);
        $rows = Branch::query()->orderBy('name')->get(['id', 'name'])->map(
            fn (Branch $branch) => ['branch_name' => $branch->name]
                + TickerService::serialize(TickerService::save($branch->id, $data, 'Super Admin')),
        );

        return response()->json(['data' => $rows->values()]);
    }
}
