<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Http\Requests\Super\GlobalSettingRequest;
use App\Http\Resources\Super\GlobalSettingResource;
use App\Models\GlobalSetting;
use App\Support\Cache\AppSettingCache;
use App\Support\Cache\GlobalSettingCache;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class GlobalSettingController extends Controller
{
    public function show()
    {
        $setting = GlobalSetting::query()->latest('id')->first();

        if (!$setting) {
            return response()->json(['data' => null]);
        }

        return new GlobalSettingResource($setting);
    }

    public function update(GlobalSettingRequest $request)
    {
        $payload = $request->validated();
        // Until the column exists (it may be added by hand), ignore the switch instead of failing.
        if (!Schema::hasColumn('global_settings', 'support_chat_enabled')) {
            unset($payload['support_chat_enabled']);
        }

        $setting = GlobalSetting::query()->latest('id')->first();

        if (!$setting) {
            $setting = GlobalSetting::create([
                ...$payload,
                'created_by' => $request->user()->id,
            ]);
        } else {
            $setting->update($payload);
        }

        GlobalSettingCache::flush();
        AppSettingCache::flushGlobal();

        return response()->json(new GlobalSettingResource($setting), Response::HTTP_OK);
    }
}
