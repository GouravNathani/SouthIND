<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class LogApiActivity
{
    /** Requests slower than this are also reported to the Laravel log. */
    private const SLOW_REQUEST_MS = 3000;

    /** Response bodies above this size are summarised, never decoded. */
    private const RESPONSE_PREVIEW_BYTES = 4000;

    /** Longer string values (base64 images, voice notes) are replaced by their length. */
    private const MAX_LOGGED_STRING = 1000;

    /** Daily api-*.log files older than this are pruned by `logs:prune-api`. */
    public const RETENTION_DAYS = 7;

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

        // Early warning for the next "one endpoint pegs the CPU" problem: slow
        // requests show up in the daily laravel log without digging in api logs.
        if ($duration >= self::SLOW_REQUEST_MS) {
            Log::warning('Slow API request', [
                'method' => $request->method(),
                'path' => $request->path(),
                'status' => $response->getStatusCode(),
                'duration_ms' => $duration,
            ]);
        }

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

        $this->appendToDailyLog(json_encode($entry));

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

    /**
     * Append one line in O(1). Storage::append() re-reads and rewrites the whole
     * file on every call, so its cost grew with the log (and concurrent requests
     * overwrote each other's lines). One file per day keeps retention trivial.
     */
    protected function appendToDailyLog(string $line): void
    {
        $dir = Storage::disk('local')->path('logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Logging must never break the API response it describes.
        @file_put_contents($dir . '/api-' . now()->format('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    protected function sanitizePayload(array $payload): array
    {
        // Anything that works as a credential on its own. The log is plain text on
        // disk, so a leaked copy must never be enough to sign in as anyone.
        $hiddenKeys = [
            'password',
            'password_confirmation',
            'current_password',
            'new_password',
            'new_password_confirmation',
            'master_password',
            'token',
            'access_token',
            'refresh_token',
            'session_key',
            'encrypted_session_key',
            'mpin',
            'current_mpin',
            'new_mpin',
            'new_mpin_confirmation',
            'mpin_confirmation',
            'pin',
            'otp',
            'code',
            'secret',
            'two_factor_secret',
            'two_factor_code',
            'recovery_code',
            'api_key',
            'api_secret',
            'access_token_encrypted',
        ];

        foreach ($payload as $key => $value) {
            if (in_array($key, $hiddenKeys, true)) {
                $payload[$key] = '********';
                continue;
            }

            if (is_string($value) && strlen($value) > self::MAX_LOGGED_STRING) {
                $payload[$key] = '[' . strlen($value) . ' chars]';
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

        // Big bodies end up as "truncated" anyway; skip the decode → sanitize →
        // re-encode round trip that cost hundreds of ms on large list responses.
        if (strlen($content) > self::RESPONSE_PREVIEW_BYTES) {
            return [
                'truncated' => true,
                'length' => strlen($content),
            ];
        }

        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'length' => strlen($content),
                'message' => 'non-json-response',
            ];
        }

        // A scalar body is still valid JSON — e.g. the WhatsApp webhook verify
        // echoes Meta's numeric hub.challenge, which decodes to an int and used
        // to blow up sanitizePayload()'s array type hint (500 on verification).
        if (!is_array($decoded)) {
            return [
                'length' => strlen($content),
                'message' => 'scalar-response',
            ];
        }

        $sanitized = $this->sanitizePayload($decoded);

        $preview = json_encode($sanitized);
        if ($preview !== false && strlen($preview) > self::RESPONSE_PREVIEW_BYTES) {
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

        // No Schema::hasColumn() probe here: its "static cache" resets on every
        // request, so it cost an information_schema query per API call.
        $lastActive = $user->last_active_at;
        $shouldUpdate = !$lastActive || $lastActive->diffInSeconds(now()) >= 30;
        if ($shouldUpdate) {
            try {
                $user->forceFill(['last_active_at' => now()])->saveQuietly();
            } catch (QueryException) {
                // Column missing in this environment (schema drift) — activity is best-effort.
            }
        }
    }

    protected function touchUserActivity(Request $request): void
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return;
        }

        $lastSeen = $user->last_seen_at;
        $shouldUpdate = !$lastSeen || $lastSeen->diffInSeconds(now()) >= 30;
        if ($shouldUpdate) {
            try {
                $user->forceFill(['last_seen_at' => now()])->saveQuietly();
            } catch (QueryException) {
                // Column missing in this environment (schema drift) — activity is best-effort.
            }
        }
    }
}
