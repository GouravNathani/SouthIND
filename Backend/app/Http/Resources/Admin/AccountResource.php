<?php

namespace App\Http\Resources\Admin;

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
            'deposit_limit' => isset($this->deposit_limit) ? (float) $this->deposit_limit : null,
            'min_deposit' => isset($this->min_deposit) ? (float) $this->min_deposit : null,
            'max_deposit' => isset($this->max_deposit) ? (float) $this->max_deposit : null,
            'created_at' => $this->created_at,
            // Set by AccountController@index (latest deposit on this account).
            'last_used_at' => $this->getAttribute('last_used_at'),
        ];
    }
}
