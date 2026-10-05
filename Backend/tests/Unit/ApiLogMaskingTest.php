<?php

namespace Tests\Unit;

use App\Http\Middleware\LogApiActivity;
use PHPUnit\Framework\TestCase;

/**
 * api-*.log is plain text kept for 7 days: nothing in it may work as a credential.
 */
class ApiLogMaskingTest extends TestCase
{
    public function test_credential_variants_and_push_secrets_are_masked(): void
    {
        $sanitized = $this->sanitize([
            'phone' => '9000012345',
            'old_mpin' => '1234',
            'old_password' => 'hunter2',
            'hub_verify_token' => 'meta-verify',
            'webhook_verify_token' => 'abc',
            'mpin' => '5678',
            'keys' => ['p256dh' => 'BPublicKey', 'auth' => 'push-auth-secret'],
        ]);

        $this->assertSame('9000012345', $sanitized['phone']);
        $this->assertSame('BPublicKey', $sanitized['keys']['p256dh']);
        foreach (['old_mpin', 'old_password', 'hub_verify_token', 'webhook_verify_token', 'mpin'] as $key) {
            $this->assertSame('********', $sanitized[$key], $key);
        }
        $this->assertSame('********', $sanitized['keys']['auth']);
    }

    private function sanitize(array $payload): array
    {
        $method = new \ReflectionMethod(LogApiActivity::class, 'sanitizePayload');

        return $method->invoke(new LogApiActivity(), $payload);
    }
}
