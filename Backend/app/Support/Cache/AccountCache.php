<?php

namespace App\Support\Cache;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class AccountCache
{
    private const USER_PREFIX = 'accounts:user:';
    private const BRANCH_PREFIX = 'accounts:branch:';
    private const TTL_MINUTES = 60 * 24;

    /**
     * @param callable(): Collection $callback
     */
    public static function rememberForUser(string $usage, int $branchId, callable $callback): Collection
    {
        $key = self::USER_PREFIX . $branchId . ':' . $usage;

        /** @var Collection $value */
        $value = Cache::remember($key, now()->addMinutes(self::TTL_MINUTES), $callback);

        return $value;
    }

    /**
     * @param callable(): Collection $callback
     */
    public static function rememberForBranch(int $branchId, callable $callback): Collection
    {
        $key = self::BRANCH_PREFIX . $branchId;

        /** @var Collection $value */
        $value = Cache::remember($key, now()->addMinutes(self::TTL_MINUTES), $callback);

        return $value;
    }

    public static function forgetUserLists(int $branchId): void
    {
        foreach (['deposit', 'withdraw', 'both'] as $usage) {
            Cache::forget(self::USER_PREFIX . $branchId . ':' . $usage);
        }
    }

    public static function forgetBranch(int $branchId): void
    {
        Cache::forget(self::BRANCH_PREFIX . $branchId);
    }

    public static function flushForBranch(int $branchId): void
    {
        self::forgetBranch($branchId);
        self::forgetUserLists($branchId);
    }
}
