<?php

namespace App\Http\Resources\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\BonusCodeRedemption */
class BonusRedemptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'amount' => (float) $this->amount,
            'reward_label' => $this->reward_label,
            'status' => $this->status,
            'deposit_id' => $this->deposit_id,
            'redeemed_at' => $this->redeemed_at,
            'created_at' => $this->created_at,
        ];
    }
}
