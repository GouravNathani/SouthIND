<?php

namespace App\Http\Resources\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin \App\Models\Account */
class AccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $logoPath = $this->logo_path;
        $logoUrl = null;
        $normalizedLogoPath = $logoPath;

        if (is_string($logoPath) && $logoPath !== '') {
            if (preg_match('#^https?://#i', $logoPath)) {
                $logoUrl = $logoPath;
            } else {
                $path = parse_url($logoPath, PHP_URL_PATH) ?: $logoPath;
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
                $normalizedLogoPath = $normalizedPath;
                $relativePath = preg_replace('#^/?storage/#', '', $normalizedPath);
                $relativePath = ltrim((string) $relativePath, '/');
                if ($relativePath !== '') {
                    $logoUrl = Storage::disk('public')->url($relativePath);
                    if ($logoUrl) {
                        if (strpos($logoUrl, '/storage/app/public/') === false) {
                            $logoUrl = preg_replace('#/storage/#', '/storage/app/public/', $logoUrl, 1);
                        }
                    }
                }
            }
        }

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
            'min_deposit' => isset($this->min_deposit) ? (float) $this->min_deposit : null,
            'max_deposit' => isset($this->max_deposit) ? (float) $this->max_deposit : null,
            'is_active' => $this->status === 'active',
        ];
    }
}
