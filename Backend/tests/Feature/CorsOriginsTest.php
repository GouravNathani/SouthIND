<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Allowed origins come from CORS_ALLOWED_ORIGINS, not from domains baked into
 * config/cors.php (it used to hard-code the sibling BC panel's domains).
 */
class CorsOriginsTest extends TestCase
{
    public function test_origins_are_read_from_the_env_list(): void
    {
        $config = $this->corsConfigWithEnv(' https://admin.southindiaexch.com , https://southindiaexch.com/,');

        $this->assertSame(
            ['https://admin.southindiaexch.com', 'https://southindiaexch.com'],
            $config['allowed_origins']
        );
        $this->assertSame([], $config['allowed_origins_patterns']);
    }

    public function test_an_unset_list_falls_back_to_the_local_dev_origins_only(): void
    {
        $config = $this->corsConfigWithEnv(null);

        $this->assertContains('http://localhost:5173', $config['allowed_origins']);
        foreach ($config['allowed_origins'] as $origin) {
            $this->assertMatchesRegularExpression('#^http://(localhost|127\.0\.0\.1):\d+$#', $origin);
        }
    }

    public function test_a_preflight_is_answered_only_for_a_listed_origin(): void
    {
        config([
            'cors.allowed_origins' => ['https://admin.southindiaexch.com', 'https://southindiaexch.com'],
            'cors.allowed_origins_patterns' => [],
        ]);

        $this->withHeaders([
            'Origin' => 'https://admin.southindiaexch.com',
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'authorization',
        ])->options('/api/admin/deposits')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://admin.southindiaexch.com')
            ->assertHeader('Access-Control-Max-Age', '7200');

        $this->withHeaders([
            'Origin' => 'https://wd.bcexch9.com',
            'Access-Control-Request-Method' => 'GET',
        ])->options('/api/admin/deposits')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    private function corsConfigWithEnv(?string $value): array
    {
        $previous = getenv('CORS_ALLOWED_ORIGINS');
        $this->setEnv($value);

        try {
            return require config_path('cors.php');
        } finally {
            $this->setEnv($previous === false ? null : $previous);
        }
    }

    private function setEnv(?string $value): void
    {
        if ($value === null) {
            putenv('CORS_ALLOWED_ORIGINS');
            unset($_ENV['CORS_ALLOWED_ORIGINS'], $_SERVER['CORS_ALLOWED_ORIGINS']);

            return;
        }

        putenv("CORS_ALLOWED_ORIGINS={$value}");
        $_ENV['CORS_ALLOWED_ORIGINS'] = $_SERVER['CORS_ALLOWED_ORIGINS'] = $value;
    }
}
