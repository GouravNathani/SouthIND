<?php

namespace App\Http\Resources\Super;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\GlobalSetting */
class GlobalSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'instagram_link' => $this->instagram_link,
            'telegram_link' => $this->telegram_link,
            'whatsapp_link' => $this->whatsapp_link,
            'mask_user_phone' => (bool) $this->mask_user_phone,
            'user_panel_maintenance_enabled' => (bool) $this->user_panel_maintenance_enabled,
            'bonus_deposit_enabled' => (bool) $this->bonus_deposit_enabled,
            'support_chat_enabled' => (bool) ($this->support_chat_enabled ?? true),
        ];
    }
}
