<?php

namespace Tests\Feature\WaHub;

use App\Models\GlobalSetting;
use App\Support\Cache\GlobalSettingCache;
use Illuminate\Http\Client\Request;

/**
 * Shared setup for the WA Hub tests: a configured (fake) hub, the super admin
 * switch, and helpers to read what was sent to the hub.
 */
trait WaHubTestHelpers
{
    protected string $hubUrl = 'https://hub.test';

    protected string $hubKey = 'wh_test_key';

    protected string $hubSecret = 'wh-test-secret';

    protected function configureHub(bool $switchOn = true): void
    {
        config([
            'services.wahub.url' => $this->hubUrl,
            'services.wahub.key' => $this->hubKey,
            'services.wahub.secret' => $this->hubSecret,
            'services.wahub.timeout' => 2,
            'services.wahub.login_link' => 'https://southindiaexch.com',
        ]);

        $this->setGlobal(['wa_hub_enabled' => $switchOn]);
    }

    /** @param  array<string, mixed>  $values */
    protected function setGlobal(array $values): void
    {
        $setting = GlobalSetting::query()->latest('id')->first();

        if ($setting) {
            $setting->update($values);
        } else {
            GlobalSetting::create($values);
        }

        GlobalSettingCache::flush();
    }

    protected static function hubPath(Request $request): string
    {
        return (string) parse_url($request->url(), PHP_URL_PATH);
    }

    /** @return array<string, mixed> */
    protected static function hubQuery(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    /** @return array<string, mixed> */
    protected static function hubJson(Request $request): array
    {
        return (array) json_decode($request->body(), true);
    }

    /**
     * A hub Connection object.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected static function connectionObject(string $key, string $label, array $overrides = []): array
    {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'status' => 'connected',
            'socket' => 'awake',
            'me' => ['number' => '919876543210', 'name' => 'Branch', 'platform' => 'android'],
            'qr' => null,
            'qr_expires_in' => 0,
            'qr_attempt' => 0,
            'enabled' => true,
            'header' => '',
            'footer' => '',
            'today' => ['sent' => 3, 'cap' => 200],
            'linked_at' => '2026-10-07T16:00:00Z',
            'last_sent_at' => null,
        ], $overrides);
    }
}
