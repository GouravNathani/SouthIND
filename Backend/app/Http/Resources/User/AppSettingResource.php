<?php

namespace App\Http\Resources\User;

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
            'deposit_offer_text' => $this->deposit_offer_text,
            'withdrawal_offer_text' => $this->withdrawal_offer_text,
            'instagram_link' => $global?->instagram_link ?? $this->instagram_link,
            'whatsapp_number' => $this->whatsapp_number,
            'whatsapp_link' => $resolvedWhatsapp,
            'telegram_link' => $global?->telegram_link ?? $this->telegram_link,
            'min_deposit_amount' => optional($this->branch)->min_deposit_amount,
            'min_withdrawal_amount' => optional($this->branch)->min_withdrawal_amount,
            // Deposit bonus input shows when EITHER the branch admin or the
            // super admin (global) has switched it on.
            'bonus_deposit_enabled' =>
                (bool) $this->bonus_deposit_enabled || (bool) ($global?->bonus_deposit_enabled),
        ];
    }
}
