<?php

namespace App\Support\Cache;

use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class WithdrawalCache
{
    private const USER_PREFIX = 'withdrawals:user:';
    private const ADMIN_PREFIX = 'withdrawals:admin:';
    private const ADMIN_KEYS = 'withdrawals:admin:keys';
    private const TTL_MINUTES = 60 * 24;

    /**
     * @param callable(): Collection $callback
     */
    public static function rememberForPlay(string $playId, callable $callback): Collection
    {
        $key = self::USER_PREFIX . $playId;

        /** @var Collection $value */
        $value = Cache::remember($key, self::ttl(), $callback);

        return $value;
    }

    public static function forgetForPlay(?string $playId): void
    {
        if (!$playId) {
            return;
        }

        Cache::forget(self::USER_PREFIX . $playId);
    }

    /**
     * @param array<string, mixed> $filters
     * @param callable(): Collection $callback
     */
    public static function rememberAdmin(array $filters, callable $callback): Collection
    {
        ksort($filters);
        $key = self::ADMIN_PREFIX . md5(json_encode($filters));

        try {
            /** @var Collection $value */
            $value = Cache::remember($key, self::ttl(), $callback);
            self::storeAdminKey($key);

            return $value;
        } catch (QueryException) {
            // Prevent repeated cache-size SQL errors from flooding laravel.log.
            return $callback();
        }
    }

    public static function flushAdmin(): void
    {
        $keys = Cache::pull(self::ADMIN_KEYS, []);

        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }

    private static function storeAdminKey(string $key): void
    {
        $keys = Cache::get(self::ADMIN_KEYS, []);

        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
            Cache::put(self::ADMIN_KEYS, $keys, self::ttl());
        }
    }

    private static function ttl(): \DateTimeInterface
    {
        return now()->addMinutes(self::TTL_MINUTES);
    }
}
