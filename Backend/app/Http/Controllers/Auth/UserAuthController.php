<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AddaSsoRequest;
use App\Http\Requests\Auth\UserBranchCheckRequest;
use App\Http\Requests\Auth\UserChangeMpinRequest;
use App\Http\Requests\Auth\UserMpinLoginRequest;
use App\Http\Requests\Auth\UserVerifyMpinRequest;
use App\Http\Resources\User\UserResource;
use App\Models\Branch;
use App\Models\User;
use App\Support\Sso\AddaTicket;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    /**
     * Adda → SouthIND hand-off: a user already logged in to Adda arrives with a
     * signed one-time ticket instead of their MPIN. Same response as mpin-login.
     */
    public function loginWithAddaTicket(AddaSsoRequest $request)
    {
        $reason = null;
        $claims = AddaTicket::verify(
            (string) $request->validated()['ticket'],
            (string) config('adda.sso_secret'),
            (int) config('adda.sso_max_age', 120),
            $reason,
        );

        if ($claims === null) {
            Log::warning('Adda hand-off rejected.', ['reason' => $reason]);

            return $reason === 'disabled'
                ? response()->json(['message' => 'Not available.'], Response::HTTP_NOT_FOUND)
                : response()->json(['message' => 'This link has expired. Please log in with your phone and PIN.'], Response::HTTP_UNAUTHORIZED);
        }

        $user = User::query()->whereKey($claims['sub'])->where('phone', $claims['ph'])->first();
        if (!$user) {
            return response()->json(['message' => 'Account not found. Please log in with your phone and PIN.'], Response::HTTP_UNAUTHORIZED);
        }

        if ($user->isMpinLocked()) {
            return response()->json(['message' => self::MPIN_LOCKED_MESSAGE], Response::HTTP_LOCKED);
        }

        // Burn the ticket. The unique jti makes a replay fail even when two
        // requests race.
        try {
            DB::table('sso_tickets')->insert([
                'jti' => $claims['jti'],
                'user_id' => $user->id,
                'expires_at' => date('Y-m-d H:i:s', $claims['exp']),
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => 'This link was already used. Please log in with your phone and PIN.'], Response::HTTP_UNAUTHORIZED);
        }
        DB::table('sso_tickets')->where('expires_at', '<', now()->subDay())->delete();

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
