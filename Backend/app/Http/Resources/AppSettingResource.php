<?php

namespace App\Http\Resources;

use App\Support\Cache\GlobalSettingCache;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AppSetting */
class AppSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $global = GlobalSettingCache::current();
        $branchWhatsapp = is_string($this->whatsapp_link)
            ? trim($this->whatsapp_link)
            : '';
        $globalWhatsapp = is_string($global?->whatsapp_link)
            ? trim($global->whatsapp_link)
            : '';
        $resolvedWhatsapp =
            $branchWhatsapp !== '' ? $branchWhatsapp : ($globalWhatsapp !== '' ? $globalWhatsapp : null);

        return [
            'id' => $this->id,
            'deposit_offer_text' => $this->deposit_offer_text,
            'withdrawal_offer_text' => $this->withdrawal_offer_text,
            'instagram_link' => $global?->instagram_link ?? $this->instagram_link,
            'whatsapp_number' => $this->whatsapp_number,
            'deposit_wa' => $this->deposit_wa,
            'withdrawal_wa' => $this->withdrawal_wa,
            'whatsapp_link' => $resolvedWhatsapp,
            'telegram_link' => $global?->telegram_link ?? $this->telegram_link,
            'logo_path' => $this->logo_path,
            'min_deposit_amount' => optional($this->branch)->min_deposit_amount,
            'min_withdrawal_amount' => optional($this->branch)->min_withdrawal_amount,
            'owner_admin_id' => $this->owner_admin_id,
            'branch_id' => $this->branch_id,
            'created_at' => optional($this->created_at)?->timezone(config('app.timezone'))->format('Y-m-d\\TH:i:s'),
            'updated_at' => optional($this->updated_at)?->timezone(config('app.timezone'))->format('Y-m-d\\TH:i:s'),
        ];
    }
}
