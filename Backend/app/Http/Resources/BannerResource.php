<?php

namespace App\Http\Resources;

use App\Support\PublicStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Banner */
class BannerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $normalizedImagePath = PublicStorage::path($this->image_path);
        $imageUrl = PublicStorage::url($this->image_path);

        return [
            'id' => $this->id,
            'title' => $this->title,
            'image_path' => $normalizedImagePath,
            'image_url' => $imageUrl,
            'is_logo' => $this->is_logo,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'branch_id' => $this->branch_id,
            'created_at' => $this->created_at,
        ];
    }
}
