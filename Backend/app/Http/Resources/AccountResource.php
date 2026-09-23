<?php

namespace App\Http\Resources;

use App\Support\PublicStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Account */
class AccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $normalizedLogoPath = PublicStorage::path($this->logo_path);
        $logoUrl = PublicStorage::url($this->logo_path);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'holder_name' => $this->holder_name,
            'type' => $this->type,
            'upi_id' => $this->upi_id,
            'account_number' => $this->account_number,
            'ifsc_code' => $this->ifsc_code,
            'used_for' => $this->used_for,
            'logo_path' => $normalizedLogoPath,
            'logo_url' => $logoUrl,
            'notes' => $this->notes,
            'status' => $this->status,
            'branch_id' => $this->branch_id,
        ];
    }
}
