<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStaffRequest;
use App\Http\Requests\Admin\UpdateStaffRequest;
use App\Http\Resources\Admin\AdminResource;
use App\Models\Admin;
use App\Support\ResolvesBranch;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StaffController extends Controller
{
    use ResolvesBranch;

    public function index(Request $request)
    {
        $staff = $this->staffQuery($request->user())->latest()->get();

        return AdminResource::collection($staff);
    }

    public function store(StoreStaffRequest $request)
    {
        $admin = $request->user();
        $branchId = $this->resolveBranchId($admin);

        $staff = Admin::create([
            ...$request->validated(),
            'role' => 'staff',
            'parent_id' => $admin->id,
            'branch_id' => $branchId,
        ]);

        return response()->json(new AdminResource($staff), Response::HTTP_CREATED);
    }

    public function show(Request $request, Admin $staff)
    {
        $this->authorizeStaff($request->user(), $staff);

        return new AdminResource($staff);
    }

    public function update(UpdateStaffRequest $request, Admin $staff)
    {
        $this->authorizeStaff($request->user(), $staff);

        $payload = array_filter($request->validated(), static fn ($value) => $value !== null);

        $staff->update($payload);

        return new AdminResource($staff);
    }

    public function destroy(Request $request, Admin $staff): Response
    {
        $this->authorizeStaff($request->user(), $staff);

        $staff->delete();

        return response()->noContent();
    }

    protected function authorizeStaff(Admin $actingAdmin, Admin $staff): void
    {
        if ($staff->role !== 'staff') {
            abort(Response::HTTP_NOT_FOUND, 'Staff member not found.');
        }

        if ($actingAdmin->isSuperAdmin()) {
            return;
        }

        abort_unless(
            $staff->parent_id === $actingAdmin->id,
            Response::HTTP_FORBIDDEN,
            'You may only manage your own staff.'
        );
    }

    protected function staffQuery(Admin $actor)
    {
        $query = Admin::query()->where('role', 'staff');

        if ($actor->isSuperAdmin()) {
            return $query;
        }

        return $query->where('parent_id', $actor->id);
    }
}
