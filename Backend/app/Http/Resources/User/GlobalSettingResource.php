<?php

namespace App\Http\Resources\User;

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
            'instagram_link' => $this->instagram_link,
            'telegram_link' => $this->telegram_link,
            'whatsapp_link' => $this->whatsapp_link,
            'user_panel_maintenance_enabled' => (bool) $this->user_panel_maintenance_enabled,
            'support_chat_enabled' => (bool) ($this->support_chat_enabled ?? true),
        ];
    }
}
