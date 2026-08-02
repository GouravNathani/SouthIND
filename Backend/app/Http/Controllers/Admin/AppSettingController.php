<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AppSettingRequest;
use App\Http\Resources\Admin\AppSettingResource;
use App\Models\Admin;
use App\Models\AppSetting;
use App\Models\Branch;
use App\Support\Cache\AppSettingCache;
use App\Support\ResolvesBranch;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AppSettingController extends Controller
{
    use ResolvesBranch;

    public function index(Request $request)
    {
        $branchId = $this->resolveBranchId($request->user());

        $setting = AppSettingCache::rememberBranch($branchId, function () use ($branchId) {
            return AppSetting::query()
                ->with('branch')
                ->where('branch_id', $branchId)
                ->latest('id')
                ->first();
        });

        if (!$setting) {
            $branch = Branch::query()->find($branchId);
            if ($branch) {
                $fallback = new AppSetting();
                $fallback->branch_id = $branchId;
                $fallback->setRelation('branch', $branch);
                return new AppSettingResource($fallback);
            }

            return response()->json([
                'data' => null,
            ]);
        }

        return new AppSettingResource($setting);
    }

    public function store(AppSettingRequest $request)
    {
        $ownerId = $this->resolveOwnerAdminId($request->user());
        $branchId = $this->resolveBranchId($request->user());

        if (AppSetting::where('branch_id', $branchId)->exists()) {
            return response()->json([
                'message' => 'Settings already exist for this branch. Use update instead.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $setting = AppSetting::create([
            ...$request->validated(),
            'owner_admin_id' => $ownerId,
            'created_by' => $request->user()->id,
            'branch_id' => $branchId,
        ]);

        AppSettingCache::flushBranch($branchId);

        return response()->json(
            new AppSettingResource($setting->load('branch')),
            Response::HTTP_CREATED
        );
    }

    public function show(Request $request, AppSetting $appSetting)
    {
        $this->ensureOwnership($appSetting, $request->user());

        return new AppSettingResource($appSetting->load('branch'));
    }

    public function update(AppSettingRequest $request, AppSetting $appSetting)
    {
        $this->ensureOwnership($appSetting, $request->user());

        $appSetting->update($request->validated());

        AppSettingCache::flushBranch($appSetting->branch_id);

        return new AppSettingResource($appSetting->load('branch'));
    }

    protected function resolveOwnerAdminId(Admin $actor): int
    {
        if ($actor->role === 'staff' && $actor->parent_id) {
            return $actor->parent_id;
        }

        return $actor->id;
    }

    protected function ensureOwnership(AppSetting $appSetting, Admin $actor): void
    {
        $branchId = $this->resolveBranchId($actor);

        if ((int) $appSetting->branch_id !== (int) $branchId) {
            abort(Response::HTTP_NOT_FOUND, 'Settings not found.');
        }
    }
}
