<?php

namespace App\Support\Cache;

use App\Models\GlobalSetting;
use Illuminate\Support\Facades\Cache;

class GlobalSettingCache
{
    private const KEY = 'global-settings:current';
    private const TTL_MINUTES = 60 * 24;

    public static function current(): ?GlobalSetting
    {
        /** @var GlobalSetting|null $value */
        $value = Cache::remember(self::KEY, self::ttl(), function () {
            return GlobalSetting::query()->latest('id')->first();
        });

        return $value;
    }

    public static function flush(): void
    {
        Cache::forget(self::KEY);
    }

    private static function ttl(): \DateTimeInterface
    {
        return now()->addMinutes(self::TTL_MINUTES);
    }
}
