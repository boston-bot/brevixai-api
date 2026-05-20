<?php

namespace Tests\Feature;

use Tests\TestCase;

class SmokeCheckCommandTest extends TestCase
{
    public function test_smoke_check_passes_in_test_environment(): void
    {
        $this->artisan('smoke:check')
            ->expectsOutputToContain('All checks passed')
            ->assertExitCode(0);
    }

    public function test_smoke_check_verifies_investigation_routes(): void
    {
        $this->artisan('smoke:check')
            ->expectsOutputToContain('GET  api/investigations')
            ->expectsOutputToContain('POST api/investigations/{id}/reports')
            ->expectsOutputToContain('POST api/investigations/{id}/package-manifest')
            ->assertExitCode(0);
    }

    public function test_smoke_check_verifies_recommendations_expire_command(): void
    {
        $this->artisan('smoke:check')
            ->expectsOutputToContain('recommendations:expire registered')
            ->assertExitCode(0);
    }

    public function test_smoke_check_verifies_dompdf(): void
    {
        $this->artisan('smoke:check')
            ->expectsOutputToContain('barryvdh/laravel-dompdf installed')
            ->assertExitCode(0);
    }
}
