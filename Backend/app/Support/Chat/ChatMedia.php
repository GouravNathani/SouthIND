<?php

namespace App\Support\Chat;

use App\Support\PublicStorage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ChatMedia
{
    /**
     * Store a base64 data-URL image to the public disk and return its URL,
     * mirroring the deposit-receipt upload path. Returns null when invalid.
     */
    public static function storeBase64(?string $payload): ?string
    {
        if (!$payload || !preg_match('/^data:image\/(\w+);base64,/', $payload, $matches)) {
            return null;
        }

        $extension = strtolower($matches[1]);
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        $data = substr($payload, strpos($payload, ',') + 1);
        $binary = base64_decode($data, true);

        if ($binary === false || strlen($binary) > 5 * 1024 * 1024) {
            return null; // invalid or larger than 5MB
        }

        $relativePath = 'chat-attachments/' . Str::random(40) . '.' . $extension;
        Storage::disk('public')->put($relativePath, $binary);

        return PublicStorage::url($relativePath);
    }

    /**
     * Store a base64 data-URL voice note (audio/*) to the public disk and return
     * its URL. Accepts the codec-tagged MIME types browsers' MediaRecorder emits
     * (e.g. audio/webm;codecs=opus). Returns null when invalid or oversized.
     */
    public static function storeBase64Audio(?string $payload): ?string
    {
        if (!$payload || !preg_match('#^data:audio/([\w.+-]+).*?;base64,#i', $payload, $matches)) {
            return null;
        }

        $extension = match (strtolower($matches[1])) {
            'mpeg', 'mp3' => 'mp3',
            'mp4', 'm4a', 'x-m4a' => 'm4a',
            'aac' => 'aac',
            'ogg' => 'ogg',
            'wav', 'x-wav', 'wave' => 'wav',
            default => 'webm',
        };

        $data = substr($payload, strpos($payload, ',') + 1);
        $binary = base64_decode($data, true);

        if ($binary === false || strlen($binary) > 10 * 1024 * 1024) {
            return null; // invalid or larger than 10MB
        }

        $relativePath = 'chat-voice/' . Str::random(40) . '.' . $extension;
        Storage::disk('public')->put($relativePath, $binary);

        return PublicStorage::url($relativePath);
    }

    /**
     * Turn a stored chat-attachment path into a browser-reachable URL. The
     * prefix uploads are served under is a deployment detail, so it comes from
     * the public disk's configured `url` (see FILESYSTEM_PUBLIC_URL) rather than
     * being hardcoded here; already-absolute URLs are passed through.
     */
    public static function publicUrl(?string $path): ?string
    {
        return PublicStorage::url($path);
    }
}
