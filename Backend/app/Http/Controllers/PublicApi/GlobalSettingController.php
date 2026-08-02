<?php

namespace App\Http\Controllers\PublicApi;

use App\Http\Controllers\Controller;
use App\Http\Resources\User\GlobalSettingResource;
use App\Support\Cache\GlobalSettingCache;
use Symfony\Component\HttpFoundation\Response;

class GlobalSettingController extends Controller
{
    public function show()
    {
        $setting = GlobalSettingCache::current();

        if (!$setting) {
            return response()->json(['data' => null], Response::HTTP_OK);
        }

        return new GlobalSettingResource($setting);
    }
}
