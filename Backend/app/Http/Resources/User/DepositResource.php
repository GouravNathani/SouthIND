<?php

namespace App\Http\Resources\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Deposit */
class DepositResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'play_id' => $this->play_id,
            'amount' => $this->amount,
            'status' => $this->status,
            // Prefer deposit-specific notes, otherwise fall back to account notes for additional info
            'notes' => $this->notes ?? $this->account?->notes,
            'created_at' => $this->created_at,
        ];
    }
}
