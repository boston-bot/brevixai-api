<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

class SmokeCheck extends Command
{
    protected $signature = 'smoke:check';

    protected $description = 'Verify deployment readiness: routes, config, dependencies, storage';

    private int $failures = 0;

    public function handle(): int
    {
        $this->line('');
        $this->line('<fg=cyan>Brevix API — Deployment Smoke Check</>');
        $this->line(str_repeat('─', 50));

        $this->section('Environment');
        $this->check('APP_KEY is set', fn () => \strlen((string) config('app.key')) > 0);
        $this->check('APP_URL is set', fn () => \strlen((string) config('app.url')) > 0);
        $this->check('DB_CONNECTION is set', fn () => \strlen((string) config('database.default')) > 0);
        $this->check('RECOMMENDATION_EXPIRATION_DAYS resolves', fn () => config('recommendations.expiration_days') >= 1);

        $this->section('Dependencies');
        $this->check('barryvdh/laravel-dompdf installed', fn () => class_exists(\Barryvdh\DomPDF\Facade\Pdf::class));
        $this->check('DomPDF config loaded', fn () => \is_array(config('dompdf')));

        $this->section('Investigation Routes');
        $this->check('GET  api/investigations', fn () => $this->routeExists('GET', 'api/investigations'));
        $this->check('GET  api/investigations/{id}', fn () => $this->routeExists('GET', 'api/investigations/{id}'));
        $this->check('GET  api/investigations/{id}/evidence', fn () => $this->routeExists('GET', 'api/investigations/{id}/evidence'));
        $this->check('POST api/investigations/{id}/evidence', fn () => $this->routeExists('POST', 'api/investigations/{id}/evidence'));
        $this->check('GET  api/investigations/{id}/reports', fn () => $this->routeExists('GET', 'api/investigations/{id}/reports'));
        $this->check('POST api/investigations/{id}/reports', fn () => $this->routeExists('POST', 'api/investigations/{id}/reports'));
        $this->check('POST api/investigations/{id}/package-manifest', fn () => $this->routeExists('POST', 'api/investigations/{id}/package-manifest'));

        $this->section('Scheduled Commands');
        $this->check('recommendations:expire registered', fn () => $this->commandExists('recommendations:expire'));

        $this->section('Storage');
        $this->check('storage/app is writable', fn () => is_writable(storage_path('app')));
        $this->check('storage/logs is writable', fn () => is_writable(storage_path('logs')));

        $this->line('');
        $this->line(str_repeat('─', 50));

        if ($this->failures === 0) {
            $this->info('All checks passed. Ready to deploy.');
            $this->line('');

            return self::SUCCESS;
        }

        $this->error("{$this->failures} check(s) failed. Resolve before deploying.");
        $this->line('');

        return self::FAILURE;
    }

    private function section(string $title): void
    {
        $this->line('');
        $this->line("  <fg=yellow>{$title}</>");
    }

    private function check(string $label, callable $test): void
    {
        try {
            $passed = (bool) $test();
        } catch (\Throwable) {
            $passed = false;
        }

        if ($passed) {
            $this->line("    <fg=green>✓</> {$label}");
        } else {
            $this->line("    <fg=red>✗</> {$label}");
            $this->failures++;
        }
    }

    private function routeExists(string $method, string $uri): bool
    {
        foreach (Route::getRoutes()->get($method) ?? [] as $route) {
            if ($route->uri() === $uri) {
                return true;
            }
        }

        return false;
    }

    private function commandExists(string $signature): bool
    {
        return \array_key_exists($signature, Artisan::all());
    }
}
