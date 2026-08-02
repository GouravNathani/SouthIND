<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StaffLoginRequest;
use App\Http\Resources\Admin\AdminResource;
use App\Models\Admin;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Hash;

class StaffAuthController extends Controller
{
    public function login(StaffLoginRequest $request)
    {
        $credentials = $request->validated();
        $staff = Admin::where('phone', $credentials['phone'])
            ->where('role', 'staff')
            ->first();

        if (!$staff || !Hash::check($credentials['password'], $staff->password)) {
            return response()->json(['message' => 'Invalid credentials.'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$staff->is_active) {
            return response()->json(['message' => 'Account is disabled.'], Response::HTTP_FORBIDDEN);
        }

        $staff->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'token' => $staff->createToken('staff', ['staff'])->plainTextToken,
            'admin' => new AdminResource($staff),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(Request $request)
    {
        return new AdminResource($request->user());
    }
}
