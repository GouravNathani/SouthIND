<?php

namespace App\Http\Resources\Super;

use App\Models\Deposit;
use App\Support\PublicStorage;
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
        $normalizedReceiptPath = PublicStorage::path($this->receipt_image_path);
        $receiptUrl = PublicStorage::url($this->receipt_image_path);
        $completedAt = $this->approved_at;
        if (!$completedAt && in_array($this->status, [Deposit::STATUS_APPROVED, Deposit::STATUS_REJECTED, Deposit::STATUS_FAILED], true)) {
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
            'utr_number' => $this->utr_number,
            'branch_id' => $this->branch_id,
            // Prefer deposit-specific notes, otherwise fall back to account notes for additional info
            'notes' => $this->notes ?? $this->account?->notes,
            'receipt_image_path' => $normalizedReceiptPath,
            'receipt_image_url' => $receiptUrl,
            'account' => AccountResource::make($this->whenLoaded('account')),
            'user' => UserResource::make($this->whenLoaded('user')),
            'created_at' => $this->created_at,
            'approved_at' => $completedAt,
            'processing_seconds' => $processingSeconds,
        ];
    }
}
