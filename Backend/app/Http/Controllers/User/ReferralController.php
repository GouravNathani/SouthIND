<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\CommissionEntry;
use App\Models\CommissionPayout;
use App\Models\ReferralSetting;
use App\Models\User;
use App\Support\Referral\ReferralCode;
use App\Support\Referral\ReferralPresenter;
use App\Support\Referral\ReferralReports;
use App\Support\Referral\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The agent-facing side of the referral programme.
 *
 * The user app calls this feature "Account" — never "wallet", which in this
 * codebase means the external message-billing wallet.
 */
class ReferralController extends Controller
{
    public function __construct(protected ReferralService $service)
    {
    }

    /**
     * Balance, team totals, rate and the share code.
     *
     * AGENTS ONLY. A plain player never sees an Account — their panel stays
     * exactly as it was. Becoming an agent happens when an admin connects
     * someone under them, which is what promotes them and opens this up.
     */
    public function account(Request $request): JsonResponse
    {
        $user = $request->user();
        $settings = $this->settingsFor($user);

        if (!$settings->enabled || !$user->isAgent()) {
            return response()->json(['data' => ['enabled' => false, 'is_agent' => false]]);
        }

        // Minted lazily rather than at promotion time, so an agent created by a
        // direct DB edit still gets a working code on first open.
        ReferralCode::ensureFor($user);

        return response()->json(['data' => ReferralReports::agentDashboard($user->refresh()) + [
            'enabled' => true,
        ]]);
    }

    /** Who joined under me, what they deposited, what I earned from them. */
    public function team(Request $request): JsonResponse
    {
        $user = $request->user();
        $settings = $this->settingsFor($user);

        if (!$settings->enabled || !$user->isAgent()) {
            return response()->json(['data' => []]);
        }

        return response()->json(['data' => ReferralReports::team($user, forAdmin: false)]);
    }

    /** The agent's own ledger. */
    public function entries(Request $request): JsonResponse
    {
        $user = $request->user();
        $settings = $this->settingsFor($user);

        if (!$settings->enabled || !$user->isAgent()) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }

        $query = CommissionEntry::query()
            ->where('agent_id', $user->id)
            // A void entry is a request that never happened — showing it in the
            // agent's own history only produces "where did this go?" tickets.
            ->where('status', '<>', CommissionEntry::STATUS_VOID);

        if (filled($request->query('type'))) {
            $query->where('type', $request->query('type'));
        }

        $paginator = $query->orderByDesc('id')
            ->paginate(min(50, max(5, (int) $request->query('per_page', 20))));

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn ($entry) => ReferralPresenter::entryForUser($entry))
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Enter someone's referral code. Only possible while the account has no
     * referrer and only for a code from the same branch — attribution is a
     * one-time, one-way decision.
     */
    public function applyCode(Request $request): JsonResponse
    {
        $user = $request->user();
        $settings = $this->settingsFor($user);

        if (!$settings->enabled || !$settings->allow_self_signup_code) {
            return response()->json(['message' => 'Referral codes are not being accepted right now.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($user->referred_by) {
            return response()->json(['message' => 'A referral code is already applied to your account.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $request->validate([
            'referral_code' => ['required', 'string', 'max:16'],
        ]);

        $referrer = ReferralCode::resolve($data['referral_code'], (int) $user->branch_id);

        if (!$referrer) {
            return response()->json(['message' => 'That referral code is not valid.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->service->attach(
                user: $user,
                referrer: $referrer,
                adminId: null,
                reason: 'Code entered by the user.',
                actorType: 'user',
                // A user entering a code never unlocks retroactive commission on
                // their own past deposits — that is an admin decision only.
                retroactive: false,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => [
            'referrer_name' => $referrer->name,
            'referral_code' => $referrer->referral_code,
        ]]);
    }

    public function payouts(Request $request): JsonResponse
    {
        $payouts = CommissionPayout::query()
            ->where('agent_id', $request->user()->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $payouts->map(fn ($payout) => ReferralPresenter::payout($payout, forAdmin: false))->all(),
        ]);
    }

    /** Ask for the money — cash out, or credit it into the game balance. */
    public function requestPayout(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:9999999'],
            'method' => ['required', 'string', 'in:' . implode(',', CommissionPayout::METHODS)],
            'upi_id' => ['required_if:method,upi', 'nullable', 'string', 'max:255'],
            'account_number' => ['required_if:method,bank', 'nullable', 'string', 'max:40'],
            'ifsc_code' => ['required_if:method,bank', 'nullable', 'string', 'max:20'],
            'account_name' => ['required_if:method,bank', 'nullable', 'string', 'max:120'],
        ]);

        try {
            $payout = $this->service->requestPayout(
                agent: $user,
                amount: (float) $data['amount'],
                method: $data['method'],
                destination: $data,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => ReferralPresenter::payout($payout, forAdmin: false)], Response::HTTP_CREATED);
    }

    protected function settingsFor(User $user): ReferralSetting
    {
        if (!$user->branch_id) {
            abort(Response::HTTP_FORBIDDEN, 'Branch assignment required.');
        }

        return ReferralSetting::forBranch((int) $user->branch_id);
    }
}
