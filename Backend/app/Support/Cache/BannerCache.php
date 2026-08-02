<?php

namespace App\Support\Cache;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class BannerCache
{
    private const PUBLIC_PREFIX = 'banners:public:';
    private const ADMIN_PREFIX = 'banners:admin:';
    private const TTL_MINUTES = 60 * 24;

    /**
     * @param callable(): Collection $callback
     */
    public static function rememberPublic(int $branchId, callable $callback): Collection
    {
        $key = self::PUBLIC_PREFIX . $branchId;
        /** @var Collection $value */
        $value = Cache::remember($key, self::ttl(), $callback);

        return $value;
    }

    /**
     * @param callable(): Collection $callback
     */
    public static function rememberAdmin(int $branchId, callable $callback): Collection
    {
        $key = self::ADMIN_PREFIX . $branchId;
        /** @var Collection $value */
        $value = Cache::remember($key, self::ttl(), $callback);

        return $value;
    }

    public static function flushBranch(int $branchId): void
    {
        Cache::forget(self::PUBLIC_PREFIX . $branchId);
        Cache::forget(self::ADMIN_PREFIX . $branchId);
    }

    private static function ttl(): \DateTimeInterface
    {
        return now()->addMinutes(self::TTL_MINUTES);
    }
}
