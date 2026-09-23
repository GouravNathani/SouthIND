<?php

namespace App\Http\Resources\Admin;

use App\Support\AccountStatusAudit;
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
            // Why/who/when of the latest status change (see AccountStatusAudit).
            // status_changed_by is null when the system made the change. The
            // admin summaries only appear when the relation was eager-loaded:
            // DepositResource embeds this resource per row, and lazy-loading
            // there would add two queries to every deposit in the list.
            'status_reason' => $this->status_reason,
            'status_changed_by' => $this->whenLoaded('statusChangedBy', fn () => AccountStatusAudit::summarize($this->statusChangedBy, $this->status_changed_by_role)),
            'status_changed_by_role' => $this->status_changed_by_role,
            'status_changed_at' => $this->status_changed_at,
            'status_meta' => $this->status_meta,
            'deposit_limit' => isset($this->deposit_limit) ? (float) $this->deposit_limit : null,
            'min_deposit' => isset($this->min_deposit) ? (float) $this->min_deposit : null,
            'max_deposit' => isset($this->max_deposit) ? (float) $this->max_deposit : null,
            'created_at' => $this->created_at,
            'created_by' => $this->whenLoaded('creator', fn () => AccountStatusAudit::summarize($this->creator)),
            // Set by AccountController@index (latest deposit on this account).
            'last_used_at' => $this->getAttribute('last_used_at'),
        ];
    }
}
