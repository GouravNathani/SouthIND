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

    /**
     * Support chat is ON unless the super admin switched it off. A missing row
     * or column (migration not run yet) counts as ON.
     */
    public static function supportChatEnabled(): bool
    {
        return (bool) (self::current()?->support_chat_enabled ?? true);
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
