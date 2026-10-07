<?php

namespace App\Http\Resources\Super;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Branch */
class BranchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'domain' => $this->domain,
            'is_active' => $this->is_active,
            'min_deposit_amount' => $this->min_deposit_amount,
            'min_withdrawal_amount' => $this->min_withdrawal_amount,
            // Agent (referral) programme switch — the same flag the Agent page
            // toggles, surfaced here so the owner can flip it per branch.
            'agent_enabled' => (bool) ($this->referralSetting?->enabled ?? false),
            // The branch's WhatsApp numbers from its app settings: the deposit and
            // withdrawal queues share a request to these.
            'deposit_wa' => $this->appSetting?->deposit_wa,
            'withdrawal_wa' => $this->appSetting?->withdrawal_wa,
            'admins_count' => $this->admins_count,
            'users_count' => $this->users_count,
            'created_at' => $this->created_at,
        ];
    }
}
