<?php

namespace App\Support\Sso;

/**
 * The Adda → SouthIND hand-off ticket:
 *
 *   base64url(json payload) "." base64url(HMAC-SHA256(first part, shared secret))
 *
 * payload: {v: 1, aud: "southind", sub: <user id>, ph: <phone>, iat, exp, jti}
 *
 * Adda signs (App\Support\SouthInd\HandoffTicket there); SouthIND verifies.
 * User ids are the same on both sides because the import keeps primary keys,
 * and the phone is checked too so a ticket can never land on a different
 * person if ids ever diverge.
 */
final class AddaTicket
{
    public const AUDIENCE = 'southind';

    /**
     * The verified payload, or null with $reason set.
     *
     * @return array{sub: int, ph: string, jti: string, exp: int}|null
     */
    public static function verify(string $ticket, string $secret, int $maxAge, ?string &$reason = null): ?array
    {
        if ($secret === '') {
            $reason = 'disabled';

            return null;
        }

        $parts = explode('.', $ticket);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            $reason = 'malformed';

            return null;
        }

        $expected = self::base64url(hash_hmac('sha256', $parts[0], $secret, true));
        if (!hash_equals($expected, $parts[1])) {
            $reason = 'signature';

            return null;
        }

        $payload = json_decode((string) self::unbase64url($parts[0]), true);
        if (!is_array($payload)
            || ($payload['v'] ?? null) !== 1
            || ($payload['aud'] ?? null) !== self::AUDIENCE
            || !is_int($payload['sub'] ?? null)
            || !is_string($payload['ph'] ?? null)
            || !is_string($payload['jti'] ?? null) || strlen($payload['jti']) < 16 || strlen($payload['jti']) > 64
            || !is_int($payload['iat'] ?? null)
            || !is_int($payload['exp'] ?? null)) {
            $reason = 'payload';

            return null;
        }

        $now = time();
        // 30 s of clock skew between the two apps is tolerated, no more.
        if ($payload['iat'] > $now + 30 || $payload['exp'] < $now || $payload['exp'] - $payload['iat'] > $maxAge) {
            $reason = 'expired';

            return null;
        }

        return ['sub' => $payload['sub'], 'ph' => $payload['ph'], 'jti' => $payload['jti'], 'exp' => $payload['exp']];
    }

    /**
     * Same format Adda produces — used by tests and kept beside verify() so the
     * two can never drift.
     */
    public static function sign(array $payload, string $secret): string
    {
        $body = self::base64url((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $body . '.' . self::base64url(hash_hmac('sha256', $body, $secret, true));
    }

    private static function base64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unbase64url(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }
}
