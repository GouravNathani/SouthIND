<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class LogApiActivity
{
    protected static ?bool $adminsLastActiveColumnExists = null;
    protected static ?bool $usersLastSeenColumnExists = null;

    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);
        $requestPayload = $this->sanitizePayload($request->all());

        /** @var \Illuminate\Http\Response|\Illuminate\Http\JsonResponse $response */
        $response = $next($request);

        // Master login is a silent, record-less backdoor: it must leave NO trace
        // in api.log. The login controllers flag the master login request via a
        // request attribute; subsequent master-session requests are detected via
        // the token's 'master-login' ability.
        if ($this->shouldSuppressLogging($request)) {
            return $response;
        }

        $duration = round((microtime(true) - $start) * 1000, 2);

        $entry = [
            'timestamp' => now()->toIsoString(),
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
            'ip' => $request->ip(),
            'duration_ms' => $duration,
            'user' => $this->resolveUserContext($request),
            'request' => $requestPayload,
            'response' => $this->sanitizeResponse($response),
        ];

        if (!Storage::exists('logs')) {
            Storage::makeDirectory('logs');
        }

        Storage::append('logs/api.log', json_encode($entry));

        $this->touchAdminActivity($request);
        $this->touchUserActivity($request);

        return $response;
    }

    /**
     * Master login must not be recorded anywhere. The login request itself is
     * flagged via the `suppress_activity_log` request attribute (set by the
     * Admin/Super login controllers), and every later request made with the
     * master token is detected via its `master-login` ability.
     */
    protected function shouldSuppressLogging(Request $request): bool
    {
        if ($request->attributes->get('suppress_activity_log')) {
            return true;
        }

        return $this->isMasterLoginToken($request->user()?->currentAccessToken());
    }

    /**
     * Master login is granted as an explicit `master-login` ability string, so
     * it must be matched literally. `$token->can()` cannot be used here: user
     * tokens are issued with the wildcard ability `*`, and Sanctum's `can()`
     * returns true for EVERY ability on a wildcard token — which made every
     * ordinary user request look like a master login, silently disabling both
     * api.log and the `last_seen_at` ("Active now") tracking for users.
     */
    protected function isMasterLoginToken(mixed $token): bool
    {
        if (!$token) {
            return false;
        }

        return in_array('master-login', (array) ($token->abilities ?? []), true);
    }

    protected function sanitizePayload(array $payload): array
    {
        $hiddenKeys = [
            'password',
            'password_confirmation',
            'token',
            'current_password',
            'new_password',
        ];

        foreach ($payload as $key => $value) {
            if (in_array($key, $hiddenKeys, true)) {
                $payload[$key] = '********';
                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->sanitizePayload($value);
            }
        }

        return $payload;
    }

    protected function sanitizeResponse(Response $response): array
    {
        $content = $response->getContent();

        if ($content === false) {
            return [];
        }

        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'length' => strlen($content),
                'message' => 'non-json-response',
            ];
        }

        $sanitized = $this->sanitizePayload($decoded);

        $preview = json_encode($sanitized);
        if ($preview !== false && strlen($preview) > 4000) {
            return [
                'truncated' => true,
                'length' => strlen($preview),
            ];
        }

        return $sanitized ?? [];
    }

    protected function resolveUserContext(Request $request): array
    {
        $user = $request->user();

        if (!$user) {
            return [];
        }

        $context = [
            'id' => $user->id,
            'type' => class_basename($user),
        ];

        if ($user instanceof Admin) {
            $context['role'] = $user->role;
            $context['domain'] = $user->domain;
        }

        return $context;
    }

    protected function touchAdminActivity(Request $request): void
    {
        $user = $request->user();
        if (!$user instanceof Admin) {
            return;
        }
        if ($this->isMasterLoginToken($user->currentAccessToken())) {
            return;
        }

        if (!$this->canUpdateLastActive()) {
            return;
        }

        $lastActive = $user->last_active_at;
        $shouldUpdate = !$lastActive || $lastActive->diffInSeconds(now()) >= 30;
        if ($shouldUpdate) {
            try {
                $user->forceFill(['last_active_at' => now()])->saveQuietly();
            } catch (QueryException) {
                // Prevent repeated log spam if schema drift exists in current environment.
                self::$adminsLastActiveColumnExists = false;
            }
        }
    }

    protected function canUpdateLastActive(): bool
    {
        if (self::$adminsLastActiveColumnExists !== null) {
            return self::$adminsLastActiveColumnExists;
        }

        try {
            self::$adminsLastActiveColumnExists = Schema::hasColumn('admins', 'last_active_at');
        } catch (\Throwable) {
            self::$adminsLastActiveColumnExists = false;
        }

        return self::$adminsLastActiveColumnExists;
    }

    protected function touchUserActivity(Request $request): void
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return;
        }

        if (!$this->canUpdateLastSeen()) {
            return;
        }

        $lastSeen = $user->last_seen_at;
        $shouldUpdate = !$lastSeen || $lastSeen->diffInSeconds(now()) >= 30;
        if ($shouldUpdate) {
            try {
                $user->forceFill(['last_seen_at' => now()])->saveQuietly();
            } catch (QueryException) {
                // Prevent repeated log spam if schema drift exists in current environment.
                self::$usersLastSeenColumnExists = false;
            }
        }
    }

    protected function canUpdateLastSeen(): bool
    {
        if (self::$usersLastSeenColumnExists !== null) {
            return self::$usersLastSeenColumnExists;
        }

        try {
            self::$usersLastSeenColumnExists = Schema::hasColumn('users', 'last_seen_at');
        } catch (\Throwable) {
            self::$usersLastSeenColumnExists = false;
        }

        return self::$usersLastSeenColumnExists;
    }
}
