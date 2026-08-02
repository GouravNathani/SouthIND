<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\AccountResource;
use App\Http\Resources\UserResource;

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
            'branch_id' => $this->branch_id,
            // Prefer deposit-specific notes, otherwise fall back to account notes for additional info
            'notes' => $this->notes ?? $this->account?->notes,
            'receipt_image_path' => $normalizedReceiptPath,
            'receipt_image_url' => $receiptUrl,
            'account' => AccountResource::make($this->whenLoaded('account')),
            'user' => UserResource::make($this->whenLoaded('user')),
            'approved_by' => $this->whenLoaded('approver', fn () => $this->approver->only(['id', 'name', 'phone'])),
            'approved_at' => $this->approved_at,
            'created_at' => $this->created_at,
        ];
    }
}
