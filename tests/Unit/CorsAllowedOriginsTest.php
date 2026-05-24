<?php

namespace Tests\Unit;

use App\Support\CorsAllowedOrigins;
use PHPUnit\Framework\TestCase;

class CorsAllowedOriginsTest extends TestCase
{
    public function test_production_without_configured_origins_fails_closed(): void
    {
        $this->assertSame([], CorsAllowedOrigins::fromEnvironment(
            appEnvironment: 'production',
            frontendUrl: null,
            configuredOrigins: null
        ));
    }

    public function test_production_uses_only_explicit_origins(): void
    {
        $this->assertSame([
            'https://app.brevix.ai',
            'https://admin.brevix.ai',
        ], CorsAllowedOrigins::fromEnvironment(
            appEnvironment: 'production',
            frontendUrl: 'https://app.brevix.ai/',
            configuredOrigins: ' https://admin.brevix.ai/ '
        ));
    }

    public function test_local_environment_includes_development_origins(): void
    {
        $this->assertSame([
            'http://localhost:8081',
            'http://localhost:19006',
            'http://127.0.0.1:8081',
            'http://127.0.0.1:19006',
        ], CorsAllowedOrigins::fromEnvironment(
            appEnvironment: 'local',
            frontendUrl: null,
            configuredOrigins: null
        ));
    }
}
