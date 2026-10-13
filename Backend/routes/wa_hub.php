<?php

use App\Http\Controllers\Admin\WaHubController as AdminWaHubController;
use App\Http\Controllers\Super\WaHubController as SuperWaHubController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WA Hub (WhatsApp from each branch's own number)
|--------------------------------------------------------------------------
|
| Loaded from bootstrap/app.php under the same `api` prefix and middleware
| group as routes/api.php. The panels never talk to the hub themselves: these
| routes sign and forward their calls. All of it is behind the super admin's
| `wa_hub_enabled` switch (`wa-hub-on` → 403 wa_hub_disabled).
|
*/

// Branch admins: their own branch only. Staff accounts are excluded.
Route::prefix('admin/wa')
    ->middleware(['auth:sanctum', 'admin', 'prevent-staff', 'wa-hub-on'])
    ->group(function () {
        Route::get('connection', [AdminWaHubController::class, 'connection']);
        Route::put('connection', [AdminWaHubController::class, 'updateConnection']);
        Route::post('connection/link', [AdminWaHubController::class, 'link']);
        Route::post('connection/unlink', [AdminWaHubController::class, 'unlink']);

        Route::get('events', [AdminWaHubController::class, 'events']);
        Route::put('events/{event}', [AdminWaHubController::class, 'updateEvent'])
            ->where('event', '[a-z_]+\.[a-z_]+');
        Route::post('events/{event}/preview', [AdminWaHubController::class, 'previewEvent'])
            ->where('event', '[a-z_]+\.[a-z_]+');

        Route::post('test', [AdminWaHubController::class, 'test'])->middleware('throttle:20,1');

        Route::get('templates', [AdminWaHubController::class, 'templates']);
        Route::post('templates', [AdminWaHubController::class, 'storeTemplate']);
        Route::put('templates/{id}', [AdminWaHubController::class, 'updateTemplate'])->whereNumber('id');
        Route::delete('templates/{id}', [AdminWaHubController::class, 'destroyTemplate'])->whereNumber('id');

        Route::post('campaigns/preview', [AdminWaHubController::class, 'previewCampaign']);
        Route::post('campaigns', [AdminWaHubController::class, 'storeCampaign'])->middleware('throttle:10,1');
        Route::get('campaigns', [AdminWaHubController::class, 'campaigns']);
        Route::get('campaigns/{id}', [AdminWaHubController::class, 'campaign'])->whereNumber('id');
        Route::post('campaigns/{id}/cancel', [AdminWaHubController::class, 'cancelCampaign'])->whereNumber('id');

        Route::get('messages', [AdminWaHubController::class, 'messages']);
        Route::get('usage', [AdminWaHubController::class, 'usage']);
    });

// Super admin: every branch.
Route::prefix('super/wa')
    ->middleware(['auth:sanctum', 'super-admin', 'wa-hub-on'])
    ->group(function () {
        Route::get('connections', [SuperWaHubController::class, 'connections']);
        Route::post('connections/{branch}/enable', [SuperWaHubController::class, 'enable'])->whereNumber('branch');
        Route::post('connections/{branch}/disable', [SuperWaHubController::class, 'disable'])->whereNumber('branch');

        Route::get('messages', [SuperWaHubController::class, 'messages']);
        Route::get('campaigns', [SuperWaHubController::class, 'campaigns']);
        Route::get('usage', [SuperWaHubController::class, 'usage']);
    });
