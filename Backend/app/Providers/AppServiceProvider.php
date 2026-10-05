<?php

namespace App\Providers;

use Carbon\Carbon;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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

        // Password/MPIN guessing on the four login endpoints. Keyed on the
        // account being tried plus the IP, never on the IP alone: many real
        // users share one public IP (mobile carrier NAT, a CDN edge), and an
        // IP-only limit would lock all of them out together.
        RateLimiter::for('login', function (Request $request) {
            $identity = Str::lower(trim((string) (
                $request->input('phone') ?? $request->input('email') ?? $request->input('unique_number') ?? ''
            )));

            return Limit::perMinute(10)->by($identity . '|' . $request->ip());
        });
    }
}
