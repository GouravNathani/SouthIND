<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

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
        $imagePath = $this->image_path;
        $imageUrl = null;
        $normalizedImagePath = $imagePath;

        if (is_string($imagePath) && $imagePath !== '') {
            if (preg_match('#^https?://#i', $imagePath)) {
                $imageUrl = $imagePath;
            } else {
                $path = parse_url($imagePath, PHP_URL_PATH) ?: $imagePath;
                $normalizedPath = ltrim((string) $path, '/');
                if (str_starts_with($normalizedPath, 'storage/')) {
                    $normalizedPath = 'storage/app/public/' . substr($normalizedPath, strlen('storage/'));
                }
                if (str_starts_with($normalizedPath, 'storage/app/public/app/public/')) {
                    $normalizedPath = 'storage/app/public/' . substr(
                        $normalizedPath,
                        strlen('storage/app/public/app/public/')
                    );
                }
                $normalizedImagePath = $normalizedPath;
                $relativePath = preg_replace('#^/?storage/#', '', $normalizedPath);
                $relativePath = ltrim((string) $relativePath, '/');
                if ($relativePath !== '') {
                    $imageUrl = Storage::disk('public')->url($relativePath);
                    if ($imageUrl) {
                        if (strpos($imageUrl, '/storage/app/public/') === false) {
                            $imageUrl = preg_replace('#/storage/#', '/storage/app/public/', $imageUrl, 1);
                        }
                    }
                }
            }
        }

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
