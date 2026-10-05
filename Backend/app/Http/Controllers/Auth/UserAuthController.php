<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UserBranchCheckRequest;
use App\Http\Requests\Auth\UserChangeMpinRequest;
use App\Http\Requests\Auth\UserMpinLoginRequest;
use App\Http\Requests\Auth\UserVerifyMpinRequest;
use App\Http\Resources\User\UserResource;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UserAuthController extends Controller
{
    protected const MPIN_LOCKED_MESSAGE = 'Account locked after too many wrong PIN attempts. Please ask admin for a new PIN.';

    public function loginWithMpin(UserMpinLoginRequest $request)
    {
        $credentials = $request->validated();
        $phoneQuery = User::query()->where('phone', $credentials['phone']);
        $branchCode = strtoupper(trim((string) ($credentials['branch_code'] ?? '')));

        if ($branchCode !== '') {
            $branch = Branch::query()->where('code', $branchCode)->first();
            if (!$branch) {
                return response()->json(
                    ['message' => 'Invalid branch code.'],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
            $phoneQuery->where('branch_id', $branch->id);
        } else {
            $matches = (clone $phoneQuery)->count();
            if ($matches > 1) {
                return response()->json(
                    ['message' => 'Branch code required for this phone number.'],
                    Response::HTTP_CONFLICT,
                );
            }
        }

        $user = $phoneQuery->first();

        if (!$user) {
            return response()->json(['message' => 'Invalid credentials.'], Response::HTTP_UNAUTHORIZED);
        }

        if ($user->isMpinLocked()) {
            return response()->json(['message' => self::MPIN_LOCKED_MESSAGE], Response::HTTP_LOCKED);
        }

        if (!$this->mpinMatches($user->mpin, $credentials['mpin'])) {
            $user->registerFailedMpin();

            if ($user->isMpinLocked()) {
                return response()->json(['message' => self::MPIN_LOCKED_MESSAGE], Response::HTTP_LOCKED);
            }

            return response()->json(['message' => 'Invalid credentials.'], Response::HTTP_UNAUTHORIZED);
        }

        $user->clearMpinLock();

        return response()->json([
            'token' => $user->createToken('user')->plainTextToken,
            'user' => new UserResource($user),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(Request $request)
    {
        return new UserResource($request->user());
    }

    public function verifyMpin(UserVerifyMpinRequest $request)
    {
        $user = $request->user();
        $mpin = $request->validated()['mpin'];

        if ($user->isMpinLocked()) {
            return response()->json([
                'valid' => false,
                'message' => self::MPIN_LOCKED_MESSAGE,
            ], Response::HTTP_LOCKED);
        }

        if ($this->mpinMatches($user->mpin, $mpin)) {
            $user->clearMpinLock();

            return response()->json([
                'valid' => true,
                'message' => 'MPIN is correct.',
            ], Response::HTTP_OK);
        }

        $user->registerFailedMpin();

        if ($user->isMpinLocked()) {
            return response()->json([
                'valid' => false,
                'message' => self::MPIN_LOCKED_MESSAGE,
            ], Response::HTTP_LOCKED);
        }

        return response()->json([
            'valid' => false,
            'message' => 'Invalid MPIN.',
        ], Response::HTTP_UNAUTHORIZED);
    }

    public function checkBranch(UserBranchCheckRequest $request)
    {
        $phone = trim((string) $request->validated()['phone']);
        $branchIds = User::query()
            ->where('phone', $phone)
            ->pluck('branch_id')
            ->filter()
            ->unique()
            ->values();
        $needsBranchCode = $branchIds->count() > 1;
        $branches = $needsBranchCode
            ? Branch::query()
                ->whereIn('id', $branchIds)
                ->get(['id', 'name', 'code'])
            : [];

        return response()->json([
            'needs_branch_code' => $needsBranchCode,
            'branches' => $branches,
        ]);
    }

    public function changeMpin(UserChangeMpinRequest $request)
    {
        $user = $request->user();
        $data = $request->validated();

        if (!$this->mpinMatches($user->mpin, $data['old_mpin'])) {
            return response()->json(['message' => 'Old MPIN is incorrect.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user->update(['mpin' => $data['new_mpin']]);

        return response()->json([
            'message' => 'MPIN updated successfully.',
        ]);
    }

    protected function mpinMatches(?string $storedMpin, string $mpin): bool
    {
        if ($storedMpin === null || $storedMpin === '') {
            return false;
        }

        return hash_equals((string) $storedMpin, (string) $mpin);
    }
}
