<?php

namespace App\Http\Resources;

use App\Support\PhoneMask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => PhoneMask::apply($this->phone),
            'unique_number' => $this->unique_number,
            'play_id' => $this->play_id,
            'mpin' => $this->mpin,
            'status' => $this->status,
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch->only(['id', 'name', 'code', 'domain'])),
            'created_at' => $this->created_at,
        ];
    }
}
