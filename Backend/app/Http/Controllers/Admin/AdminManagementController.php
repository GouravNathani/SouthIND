<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminRequest;
use App\Http\Requests\Admin\UpdateAdminRequest;
use App\Http\Resources\Super\AdminResource;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminManagementController extends Controller
{
    public function index()
    {
        $branchId = request()->query('branch_id');

        return AdminResource::collection(
            Admin::query()
                ->where('role', 'admin')
                ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                ->with('branch')
                ->orderBy('created_at', 'desc')
                ->get()
        );
    }

    public function store(StoreAdminRequest $request): JsonResponse
    {
        $data = $request->validated();
        unset($data['role']);

        $admin = Admin::create([
            ...$data,
            'role' => 'admin',
        ]);

        return response()->json(new AdminResource($admin->load('branch')), Response::HTTP_CREATED);
    }

    public function show(Admin $admin)
    {
        $this->ensureManageable($admin);

        return new AdminResource($admin->load('branch'));
    }

    public function update(UpdateAdminRequest $request, Admin $admin)
    {
        $this->ensureManageable($admin);

        if ($admin->isSuperAdmin()) {
            return response()->json(['message' => 'Super admin cannot be modified via this endpoint.'], Response::HTTP_FORBIDDEN);
        }

        $payload = array_filter($request->validated(), fn ($value) => $value !== null);
        $branchChanged = array_key_exists('branch_id', $payload)
            && (int) $payload['branch_id'] !== (int) $admin->branch_id;

        $admin->update($payload);

        if ($branchChanged) {
            Admin::query()
                ->where('parent_id', $admin->id)
                ->update(['branch_id' => $admin->branch_id]);
        }

        return new AdminResource($admin->load('branch'));
    }

    public function destroy(Request $request, Admin $admin): JsonResponse
    {
        $this->ensureManageable($admin);

        if ($admin->isSuperAdmin()) {
            return response()->json(['message' => 'Super admin cannot be removed.'], Response::HTTP_FORBIDDEN);
        }

        if ($request->user()->is($admin)) {
            return response()->json(['message' => 'You cannot remove your own admin account.'], Response::HTTP_FORBIDDEN);
        }

        $admin->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    protected function ensureManageable(Admin $admin): void
    {
        if ($admin->role === 'staff') {
            abort(Response::HTTP_NOT_FOUND, 'Admin not found.');
        }
    }
}
