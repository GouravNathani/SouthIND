<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Http\Requests\Super\GlobalSettingRequest;
use App\Http\Resources\Super\GlobalSettingResource;
use App\Models\GlobalSetting;
use App\Support\Cache\AppSettingCache;
use App\Support\Cache\GlobalSettingCache;
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
        $setting = GlobalSetting::query()->latest('id')->first();

        if (!$setting) {
            $setting = GlobalSetting::create([
                ...$request->validated(),
                'created_by' => $request->user()->id,
            ]);
        } else {
            $setting->update($request->validated());
        }

        GlobalSettingCache::flush();
        AppSettingCache::flushGlobal();

        return response()->json(new GlobalSettingResource($setting), Response::HTTP_OK);
    }
}
