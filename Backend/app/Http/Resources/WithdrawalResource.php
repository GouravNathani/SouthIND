<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Withdrawal */
class WithdrawalResource extends JsonResource
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
            'branch_id' => $this->branch_id,
            'destination_type' => $this->destination_type,
            'upi_id' => $this->upi_id,
            'account_number' => $this->account_number,
            'ifsc_code' => $this->ifsc_code,
            'account_name' => $this->account_name,
            'notes' => $this->notes,
            'user' => UserResource::make($this->whenLoaded('user')),
            'processed_by' => $this->whenLoaded('processor', fn () => $this->processor->only(['id', 'name', 'phone'])),
            'processed_at' => $this->processed_at,
            'created_at' => $this->created_at,
        ];
    }
}
