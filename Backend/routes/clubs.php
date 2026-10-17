<?php

use App\Http\Controllers\Admin\ClubController as AdminClubController;
use App\Http\Controllers\Admin\TickerController as AdminTickerController;
use App\Http\Controllers\Super\ClubController as SuperClubController;
use App\Http\Controllers\Super\TickerController as SuperTickerController;
use App\Http\Controllers\User\HomeExtrasController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Clubs and the home-page strip
|--------------------------------------------------------------------------
|
| Loaded from bootstrap/app.php under the same `api` prefix and middleware
| group as routes/api.php.
|
| Clubs: activity tiers per branch; the system assigns users (ClubAssigner).
| Ticker: the running strip on the user home page, per branch.
|
*/

// User app: the strip + the user's club badge, for the home page.
Route::middleware(['user-panel-open', 'auth:sanctum'])
    ->get('home/extras', HomeExtrasController::class);

// Branch admins: their own branch. Staff may look at clubs; only admins edit.
Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('clubs', [AdminClubController::class, 'index']);

    Route::middleware('prevent-staff')->group(function () {
        Route::post('clubs', [AdminClubController::class, 'store']);
        Route::post('clubs/defaults', [AdminClubController::class, 'defaults']);
        Route::post('clubs/refresh', [AdminClubController::class, 'refresh'])->middleware('throttle:10,1');
        Route::put('clubs/{club}', [AdminClubController::class, 'update'])->whereNumber('club');
        Route::delete('clubs/{club}', [AdminClubController::class, 'destroy'])->whereNumber('club');

        Route::get('ticker', [AdminTickerController::class, 'show']);
        Route::put('ticker', [AdminTickerController::class, 'update']);
    });
});

// Super admin: any branch, or every branch at once.
Route::prefix('super')->middleware(['auth:sanctum', 'super-admin'])->group(function () {
    Route::get('clubs', [SuperClubController::class, 'index']);
    Route::post('clubs', [SuperClubController::class, 'store']);
    Route::post('clubs/defaults', [SuperClubController::class, 'defaults']);
    Route::post('clubs/refresh', [SuperClubController::class, 'refresh'])->middleware('throttle:10,1');
    Route::get('clubs/settings', [SuperClubController::class, 'settings']);
    Route::put('clubs/settings', [SuperClubController::class, 'updateSettings']);
    Route::put('clubs/{club}', [SuperClubController::class, 'update'])->whereNumber('club');
    Route::delete('clubs/{club}', [SuperClubController::class, 'destroy'])->whereNumber('club');

    Route::get('tickers', [SuperTickerController::class, 'index']);
    Route::put('tickers', [SuperTickerController::class, 'updateAll']);
    Route::put('tickers/{branch}', [SuperTickerController::class, 'update'])->whereNumber('branch');
});
