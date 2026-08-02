<?php

namespace App\Http\Controllers\PublicApi;

use App\Http\Controllers\Controller;
use App\Http\Resources\User\BannerResource;
use App\Models\Banner;
use App\Support\Cache\BannerCache;
use App\Support\BranchResolver;
use Illuminate\Http\Request;

class BannerController extends Controller
{
    public function index(Request $request)
    {
        $branch = BranchResolver::resolve($request);
        $branchId = $branch?->id;

        if (!$branchId) {
            return BannerResource::collection(collect());
        }

        $banners = BannerCache::rememberPublic($branchId, function () use ($branchId) {
            return Banner::query()
                ->where('branch_id', $branchId)
                ->active()
                ->orderByDesc('is_logo')
                ->orderBy('sort_order')
                ->orderByDesc('id')
                ->get();
        });

        return BannerResource::collection($banners);
    }
}
