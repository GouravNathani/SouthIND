<?php

namespace App\Http\Controllers\PublicApi;

use App\Http\Controllers\Controller;
use App\Http\Resources\User\AppSettingResource;
use App\Models\AppSetting;
use App\Support\Cache\AppSettingCache;
use App\Support\BranchResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AppSettingController extends Controller
{
    public function show(Request $request)
    {
        $branch = BranchResolver::resolve($request);
        $branchId = $branch?->id;

        if (!$branchId) {
            return response()->json([
                'message' => 'Settings not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $setting = AppSettingCache::rememberPublic($branchId, function () use ($branchId, $branch) {
            $resolved = AppSetting::query()
                ->with('branch')
                ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                ->latest('id')
                ->first();

            if ($resolved) {
                return $resolved;
            }

            if ($branch) {
                $fallback = new AppSetting();
                $fallback->branch_id = $branchId;
                $fallback->setRelation('branch', $branch);
                return $fallback;
            }

            return null;
        });

        if (!$setting) {
            return response()->json([
                'message' => 'Settings not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        return new AppSettingResource($setting);
    }
}
