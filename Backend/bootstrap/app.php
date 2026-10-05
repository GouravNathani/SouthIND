<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\Illuminate\Http\Middleware\HandleCors::class);

        $middleware->alias([
            'user' => \App\Http\Middleware\EnsureUser::class,
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
            'super-admin' => \App\Http\Middleware\EnsureSuperAdmin::class,
            'prevent-staff' => \App\Http\Middleware\PreventStaffAccess::class,
            'staff' => \App\Http\Middleware\EnsureStaff::class,
            'user-panel-open' => \App\Http\Middleware\EnsureUserPanelAvailable::class,
            'support-chat-on' => \App\Http\Middleware\EnsureSupportChatEnabled::class,
            // HMAC gate for server-to-server calls from BookFlowControl.
            'wallet-hmac' => \App\Http\Middleware\VerifyWalletHmac::class,
        ]);

        // There is no `login` route: this is an API. Laravel's Authenticate middleware
        // resolves the guest redirect BEFORE the AuthenticationException handler
        // below runs, so any unauthenticated request without `Accept: application/json`
        // died with a 500 "Route [login] not defined" instead of a JSON 401.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->appendToGroup('api', \App\Http\Middleware\LogApiActivity::class);

        // Conditional GET. Settings, banners and account lists are polled
        // constantly by three SPAs but change rarely, so answering 304 with an
        // empty body is the cheapest win available — and it needs no controller
        // change, which is why it lives here rather than per-endpoint.
        $middleware->appendToGroup('api', \App\Http\Middleware\ETagResponse::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $exception, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });
    })->create();
