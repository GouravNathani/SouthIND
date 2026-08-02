<?php

namespace App\Support\Cache;

use Illuminate\Support\Facades\Cache;

class AppSettingCache
{
    private const BRANCH_PREFIX = 'app-settings:branch:';
    private const PUBLIC_PREFIX = 'app-settings:public:';
    private const TTL_MINUTES = 60 * 24;

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function rememberBranch(int $branchId, callable $callback)
    {
        $key = self::BRANCH_PREFIX . $branchId;

        return Cache::remember($key, self::ttl(), $callback);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function rememberPublic(?int $branchId, callable $callback)
    {
        $key = self::PUBLIC_PREFIX . ($branchId ?? 'global');

        return Cache::remember($key, self::ttl(), $callback);
    }

    public static function flushBranch(int $branchId): void
    {
        Cache::forget(self::BRANCH_PREFIX . $branchId);
        Cache::forget(self::PUBLIC_PREFIX . $branchId);
        self::flushGlobal();
    }

    public static function flushGlobal(): void
    {
        Cache::forget(self::PUBLIC_PREFIX . 'global');
    }

    private static function ttl(): \DateTimeInterface
    {
        return now()->addMinutes(self::TTL_MINUTES);
    }
}
