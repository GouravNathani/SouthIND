<?php

namespace App\Http\Resources\Super;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Admin */
class AdminResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'role' => $this->role,
            'is_active' => $this->is_active,
            'allow_profit_view' => $this->allow_profit_view,
            'last_login_at' => $this->last_login_at,
            'last_active_at' => $this->last_active_at,
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch->only(['id', 'name', 'code', 'domain'])),
        ];
    }
}
