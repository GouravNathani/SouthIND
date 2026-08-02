<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\BonusCodeRedeemRequest;
use App\Http\Resources\User\BonusRedemptionResource;
use App\Models\BonusCodeRedemption;
use App\Support\Bonus\BonusCodeRedeemer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BonusCodeController extends Controller
{
    /**
     * The user's own redemption history.
     */
    public function index(Request $request)
    {
        $redemptions = BonusCodeRedemption::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(50)
            ->get();

        return BonusRedemptionResource::collection($redemptions);
    }

    /**
     * Dry-run used by the deposit form: confirms the code and shows the reward
     * without consuming anything.
     */
    public function check(BonusCodeRedeemRequest $request): JsonResponse
    {
        $data = $request->validated();
        $amount = isset($data['amount']) ? (float) $data['amount'] : null;

        $bonusCode = BonusCodeRedeemer::validateFor($request->user(), $data['code'], $amount);

        return response()->json([
            'data' => [
                'code' => $bonusCode->code,
                'title' => $bonusCode->title,
                'reward_amount' => (float) $bonusCode->reward_amount,
                'reward_label' => $bonusCode->reward_label,
                'terms_text' => $bonusCode->terms_text,
                'requires_deposit' => (bool) $bonusCode->requires_deposit,
                'min_deposit' => (float) $bonusCode->min_deposit,
            ],
        ]);
    }

    /**
     * Standalone redemption from the Bonus page.
     */
    public function redeem(BonusCodeRedeemRequest $request): JsonResponse
    {
        $redemption = BonusCodeRedeemer::redeem(
            $request->user(),
            $request->validated()['code'],
            null,
            $request,
        );

        return response()->json([
            'message' => $redemption->status === BonusCodeRedemption::STATUS_FULFILLED
                ? 'Bonus applied to your account.'
                : 'Bonus code accepted. It will be credited after review.',
            'data' => new BonusRedemptionResource($redemption),
        ]);
    }
}
