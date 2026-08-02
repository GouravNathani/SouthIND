<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\BonusCode */
class BonusCodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'branch_id' => $this->branch_id,
            'title' => $this->title,
            'terms_text' => $this->terms_text,

            'reward_amount' => (float) $this->reward_amount,
            'reward_label' => $this->reward_label,

            'frequency' => $this->frequency,
            'per_user_limit' => (int) $this->per_user_limit,
            'max_redemptions' => $this->max_redemptions,
            'redeemed_count' => (int) $this->redeemed_count,
            'remaining_redemptions' => $this->remainingRedemptions(),

            'starts_at' => $this->starts_at,
            'expires_at' => $this->expires_at,
            'status' => $this->status,
            // Live state: also reflects expiry / exhaustion / not-started-yet.
            'effective_status' => $this->effectiveStatus(),

            'auto_approve' => (bool) $this->auto_approve,
            'requires_deposit' => (bool) $this->requires_deposit,
            'min_deposit' => (float) $this->min_deposit,
            'new_user_days' => $this->new_user_days,

            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'color' => $tag->color,
            ])->values()),

            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->only(['id', 'name'])),
            'created_by_role' => $this->created_by_role,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
