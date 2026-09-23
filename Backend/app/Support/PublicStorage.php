<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Uploads live on the "public" disk (storage/app/public) and reach the browser
 * through whatever prefix that disk's `url` is configured with — "/storage" when
 * the document root is public/ (artisan serve, a normal vhost), or
 * "/storage/app/public" on hosting that points the domain at the project root.
 *
 * That difference is a deployment detail, so it belongs in config rather than in
 * string surgery inside each resource: set FILESYSTEM_PUBLIC_URL per environment
 * and every URL built here follows it.
 */
final class PublicStorage
{
    public static function isAbsolute(string $value): bool
    {
        return (bool) preg_match('#^https?://#i', $value);
    }

    /**
     * Reduce any stored value to the path relative to the public disk root,
     * tolerating the legacy "storage/app/public/..." rows earlier builds wrote.
     */
    public static function relative(?string $stored): string
    {
        if (!is_string($stored) || $stored === '') {
            return '';
        }

        $path = parse_url($stored, PHP_URL_PATH) ?: $stored;
        $path = ltrim((string) $path, '/');

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        while (str_starts_with($path, 'app/public/')) {
            $path = substr($path, strlen('app/public/'));
        }

        return ltrim($path, '/');
    }

    /**
     * The canonical value to persist and hand back to clients: "storage/<file>".
     * Absolute URLs and empty values are returned untouched.
     */
    public static function path(?string $stored): ?string
    {
        if (!is_string($stored) || $stored === '' || self::isAbsolute($stored)) {
            return $stored;
        }

        $relative = self::relative($stored);

        return $relative === '' ? $stored : 'storage/' . $relative;
    }

    /** Browser-reachable URL for a stored path, or null when there is nothing to serve. */
    public static function url(?string $stored): ?string
    {
        if (!is_string($stored) || $stored === '') {
            return null;
        }

        if (self::isAbsolute($stored)) {
            return $stored;
        }

        $relative = self::relative($stored);

        return $relative === '' ? null : Storage::disk('public')->url($relative);
    }
}
