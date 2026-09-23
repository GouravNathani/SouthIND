<?php

namespace App\Http\Resources\User;

use App\Support\PublicStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Banner */
class BannerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $normalizedImagePath = PublicStorage::path($this->image_path);
        $imageUrl = PublicStorage::url($this->image_path);

        return [
            'id' => $this->id,
            'image_path' => $normalizedImagePath,
            'image_url' => $imageUrl,
            'is_active' => $this->is_active,
        ];
    }
}
