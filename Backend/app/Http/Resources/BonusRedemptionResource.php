<?php

namespace App\Http\Resources;

use App\Support\PhoneMask;
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
            'bonus_code_id' => $this->bonus_code_id,
            'code' => $this->code,
            'amount' => (float) $this->amount,
            'reward_label' => $this->reward_label,
            'status' => $this->status,
            'deposit_id' => $this->deposit_id,
            'redeemed_at' => $this->redeemed_at,
            'fulfilled_at' => $this->fulfilled_at,
            'notes' => $this->notes,
            'ip_address' => $this->ip_address,
            // Full hash stays server-side; a short prefix is enough to spot
            // several accounts redeeming from one device.
            'device_fingerprint' => $this->device_hash ? substr($this->device_hash, 0, 12) : null,
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'phone' => PhoneMask::apply($this->user->phone),
                'unique_number' => $this->user->unique_number,
                'play_id' => $this->user->play_id,
            ] : null),
            'fulfilled_by' => $this->whenLoaded('fulfiller', fn () => $this->fulfiller?->only(['id', 'name'])),
            'created_at' => $this->created_at,
        ];
    }
}
