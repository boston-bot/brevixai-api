<?php

namespace Tests\Feature;

use App\Enums\RexProcess;
use App\Services\Agents\AgentToolRegistry;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * CI parity gate for the canonical process registry.
 *
 * Ensures every tool key declared in the registry has a matching Laravel route,
 * every approval type has a matching executor case, and new orchestrator
 * processes are listed in the orchestrator's supported routes.
 */
class ProcessRegistryParityTest extends TestCase
{
    /**
     * Every tool key declared by any RexProcess must resolve to a registered
     * internal agent-tool route so the agent can actually call it.
     */
    public function test_all_registry_tool_keys_have_a_matching_route(): void
    {
        $registeredUris = $this->internalToolUris();
        $routeSuffixes = AgentToolRegistry::routeSuffixes();

        // Normalize registered URIs by replacing UUIDs and parameter tokens.
        $normalizedRegistered = array_map(
            fn (string $uri) => preg_replace('/\{[^}]+\}/', '{companyId}', $uri),
            $registeredUris
        );

        foreach (RexProcess::cases() as $process) {
            foreach ($process->tools() as $toolKey) {
                $this->assertArrayHasKey(
                    $toolKey,
                    $routeSuffixes,
                    "Tool key '{$toolKey}' has no entry in AgentToolRegistry::routeSuffixes()."
                );

                $expectedSuffix = $routeSuffixes[$toolKey];
                $expectedUri = 'api/internal/agent-tools/' . $expectedSuffix;

                $this->assertContains(
                    $expectedUri,
                    $normalizedRegistered,
                    "Tool key '{$toolKey}' (process '{$process->value}') expects route '{$expectedUri}' but it is not registered."
                );
            }
        }
    }

    /**
     * Every approval type declared by any RexProcess must have a matching
     * case in AgentActionExecutorService::execute().
     */
    public function test_all_registry_approval_types_have_an_executor_case(): void
    {
        $executorSource = file_get_contents(
            app_path('Services/Agents/AgentActionExecutorService.php')
        );

        foreach (RexProcess::cases() as $process) {
            foreach ($process->approvalTypes() as $actionType) {
                $this->assertStringContainsString(
                    "'{$actionType}'",
                    $executorSource,
                    "Approval type '{$actionType}' declared by process '{$process->value}' has no executor case."
                );
            }
        }
    }

    /**
     * Every orchestrator-mode process must appear in the orchestrator's supported routes.
     */
    public function test_orchestrator_processes_are_in_supported_routes(): void
    {
        $orchestrator = app(\App\Services\RexOrchestratorService::class);
        $supported = $orchestrator->supportedRoutes();

        foreach (RexProcess::cases() as $process) {
            if ($process->mode() !== 'orchestrator') {
                continue;
            }
            $this->assertContains(
                $process->value,
                $supported,
                "Orchestrator-mode process '{$process->value}' is not in RexOrchestratorService::supportedRoutes()."
            );
        }
    }

    /**
     * The approval endpoints must be registered and protected.
     */
    public function test_approval_endpoints_are_registered(): void
    {
        $routes = $this->registeredRoutes();

        $this->assertContains('POST api/agent-approvals/{id}/approve', $routes);
        $this->assertContains('POST api/agent-approvals/{id}/reject', $routes);
    }

    /** @return list<string> */
    private function internalToolUris(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/internal/agent-tools'))
            ->map(fn ($r) => $r->uri())
            ->values()
            ->all();
    }

    /** @return list<string> */
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
