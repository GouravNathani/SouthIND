<?php

namespace App\Http\Resources\User;

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
            'destination_type' => $this->destination_type,
            'upi_id' => $this->upi_id,
            'account_number' => $this->account_number,
            'ifsc_code' => $this->ifsc_code,
            'account_name' => $this->account_name,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
