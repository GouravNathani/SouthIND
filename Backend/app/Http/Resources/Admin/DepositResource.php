<?php

namespace App\Http\Resources\Admin;

use App\Models\Deposit;
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
        $receiptPath = $this->receipt_image_path;
        $receiptUrl = null;
        $normalizedReceiptPath = $receiptPath;
        $completedAt = $this->approved_at;
        if (!$completedAt && in_array($this->status, [Deposit::STATUS_APPROVED, Deposit::STATUS_REJECTED, Deposit::STATUS_FAILED], true)) {
            $completedAt = $this->updated_at;
        }
        $processingSeconds = null;

        if ($this->created_at && $completedAt) {
            $processingSeconds = abs($completedAt->diffInSeconds($this->created_at, false));
        }

        if (is_string($receiptPath) && $receiptPath !== '') {
            if (preg_match('#^https?://#i', $receiptPath)) {
                $receiptUrl = $receiptPath;
            } else {
                $path = parse_url($receiptPath, PHP_URL_PATH) ?: $receiptPath;
                $normalizedPath = ltrim((string) $path, '/');
                if (str_starts_with($normalizedPath, 'storage/')) {
                    $normalizedPath = 'storage/app/public/' . substr($normalizedPath, strlen('storage/'));
                }
                if (str_starts_with($normalizedPath, 'storage/app/public/app/public/')) {
                    $normalizedPath = 'storage/app/public/' . substr(
                        $normalizedPath,
                        strlen('storage/app/public/app/public/')
                    );
                }
                $normalizedReceiptPath = $normalizedPath;
                $receiptUrl = url($normalizedPath);
            }
        }

        return [
            'id' => $this->id,
            'play_id' => $this->play_id,
            'amount' => $this->amount,
            'status' => $this->status,
            'utr_number' => $this->utr_number,
            // Prefer deposit-specific notes, otherwise fall back to account notes for additional info
            'notes' => $this->notes ?? $this->account?->notes,
            'receipt_image_path' => $normalizedReceiptPath,
            'receipt_image_url' => $receiptUrl,
            'account' => AccountResource::make($this->whenLoaded('account')),
            'user' => UserResource::make($this->whenLoaded('user')),
            // Bonus code the user applied on the deposit form, if any.
            'bonus' => $this->whenLoaded('bonusRedemption', fn () => $this->bonusRedemption ? [
                'id' => $this->bonusRedemption->id,
                'code' => $this->bonusRedemption->code,
                'amount' => (float) $this->bonusRedemption->amount,
                'reward_label' => $this->bonusRedemption->reward_label,
                'status' => $this->bonusRedemption->status,
            ] : null),
            'created_at' => $this->created_at,
            'approved_at' => $completedAt,
            'processing_seconds' => $processingSeconds,
        ];
    }
}
