<?php

namespace App\Http\Resources\Super;

use App\Support\PhoneMask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $depositApprovedTotal = (float) ($this->deposit_approved_total ?? 0);
        $withdrawalApprovedTotal = (float) ($this->withdrawal_approved_total ?? 0);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => PhoneMask::apply($this->phone),
            'unique_number' => $this->unique_number,
            'play_id' => $this->play_id,
            'mpin' => $this->mpin,
            'status' => $this->status,
            'user_type' => $this->user_type,
            'agent_status' => $this->agent_status,
            'referral_code' => $this->referral_code,
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch->only(['id', 'name', 'code', 'domain'])),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'color' => $tag->color,
            ])->values()),
            'created_at' => $this->created_at,
            'last_seen_at' => $this->last_seen_at,
            'deposit_approved_total' => $depositApprovedTotal,
            'withdrawal_approved_total' => $withdrawalApprovedTotal,
            'profit_total' => $depositApprovedTotal - $withdrawalApprovedTotal,
        ];
    }
}
