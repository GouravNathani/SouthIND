<?php

namespace App\Providers;

use Carbon\Carbon;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $timezone = config('app.timezone', 'Asia/Kolkata');
        Carbon::serializeUsing(static function (Carbon $carbon) use ($timezone): string {
            return $carbon->copy()->timezone($timezone)->format('Y-m-d\\TH:i:s');
        });
    }
}
