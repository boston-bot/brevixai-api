<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Route contract gate — enforces Phase 1 route drift resolution.
 *
 * Required routes must exist. Removed/replaced routes must be absent.
 * Update the allowlist below only with a documented product reason and removal note.
 */
class RouteContractTest extends TestCase
{
    /**
     * Routes that MUST exist after Phase 1.
     * Failing here means a new backend implementation is missing.
     */
    public function test_required_routes_are_registered(): void
    {
        $required = [
            'POST api/auth/forgot-password',
            'POST api/auth/reset-password',
            'GET api/uploads/{id}/errors',
            'GET api/entity-graph',
            'GET api/entity-graph/node/{id}',
        ];

        $registered = $this->registeredRoutes();

        foreach ($required as $route) {
            $this->assertContains(
                $route,
                $registered,
                "Required Phase 1 route is missing: {$route}"
            );
        }
    }

    /**
     * Routes that must NOT exist — they were replaced by existing routes or hidden.
     *
     * If any of these appear, the frontend is calling a stale endpoint instead of
     * the real one, and new route drift has been introduced.
     *
     * Allowlist exceptions (with reason and removal note):
     * — none currently.
     */
    public function test_replaced_routes_are_absent(): void
    {
        $replaced = [
            // dismiss-pattern → PATCH /api/alerts/{id} with status payload
            'POST api/alerts/{id}/dismiss-pattern',
            // create-case → POST /api/cases with alert_ids payload
            'POST api/alerts/{id}/create-case',
            // create-case → POST /api/cases with transaction_ids payload
            'POST api/transactions/{id}/create-case',
            // Legacy reports → investigation report flows
            'GET api/reports/summary',
            'GET api/reports/export',
            // No multi-client backend — content must be hidden
            'GET api/clients',
            // Legal workflow not implemented — static intake only
            'POST api/legal-escalations/from-alert/{id}',
            // Case PDF → POST /api/investigations/{id}/reports
            'GET api/cases/{id}/pdf',
            'GET api/cases/{id}/pdf/status/{job}',
            // Reconciliation run not deterministically safe in Phase 1 — button disabled
            'POST api/reconciliation/run',
        ];

        $registered = $this->registeredRoutes();

        foreach ($replaced as $route) {
            $this->assertNotContains(
                $route,
                $registered,
                "Replaced/removed route must not be registered: {$route}. Add a real backend implementation or an explicit allowlist entry with a reason."
            );
        }
    }

    /** @return list<string> List of "METHOD api/path" strings for all registered API routes. */
    private function registeredRoutes(): array
    {
        $routes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $routes[] = $method . ' ' . $route->uri();
            }
        }
        return array_values(array_unique($routes));
    }
}
