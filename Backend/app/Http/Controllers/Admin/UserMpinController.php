<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ResolvesBranch;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class UserMpinController extends Controller
{
    use ResolvesBranch;

    public function store(User $user): JsonResponse
    {
        $branchId = $this->resolveBranchId(request()->user());
        if ((int) $user->branch_id !== (int) $branchId) {
            abort(Response::HTTP_NOT_FOUND, 'User not found.');
        }

        $mpin = User::generateMpin();
        $user->update([
            'mpin' => $mpin,
            'mpin_failed_attempts' => 0,
            'mpin_locked_at' => null,
        ]);

        return response()->json([
            'message' => 'MPIN generated successfully.',
            'user_id' => $user->unique_number,
            'mpin' => $mpin,
        ]);
    }
}
