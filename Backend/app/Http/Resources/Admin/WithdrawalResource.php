<?php

namespace App\Http\Resources\Admin;

use App\Models\Withdrawal;
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
        $completedAt = $this->processed_at;
        if (!$completedAt && in_array($this->status, [Withdrawal::STATUS_APPROVED, Withdrawal::STATUS_REJECTED, Withdrawal::STATUS_FAILED], true)) {
            $completedAt = $this->updated_at;
        }
        $processingSeconds = null;

        if ($this->created_at && $completedAt) {
            $processingSeconds = abs($completedAt->diffInSeconds($this->created_at, false));
        }

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
            'user' => UserResource::make($this->whenLoaded('user')),
            'created_at' => $this->created_at,
            'processed_at' => $completedAt,
            'processing_seconds' => $processingSeconds,
        ];
    }
}
