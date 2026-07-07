<?php

namespace Tests\Feature;

use App\Enums\ProcessReadiness;
use App\Enums\RexProcess;
use App\Services\Agents\AgentActionExecutorService;
use App\Services\Agents\AgentToolRegistry;
use App\Services\Agents\BrevixAgentRunner;
use App\Services\RexOrchestratorService;
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
        $registeredRoutes = $this->registeredRoutes();
        $definitions = AgentToolRegistry::definitions();

        foreach (RexProcess::cases() as $process) {
            foreach ($process->tools() as $toolKey) {
                $this->assertArrayHasKey(
                    $toolKey,
                    $definitions,
                    "Tool key '{$toolKey}' has no entry in AgentToolRegistry::definitions()."
                );

                $definition = $definitions[$toolKey];
                $expectedRoute = $this->expectedToolRoute($definition);

                $this->assertContains(
                    $expectedRoute,
                    $registeredRoutes,
                    "Tool key '{$toolKey}' (process '{$process->value}') expects route '{$expectedRoute}' but it is not registered."
                );
            }
        }
    }

    public function test_all_tool_catalog_definitions_have_matching_routes(): void
    {
        $registeredRoutes = $this->registeredRoutes();

        foreach (AgentToolRegistry::definitions() as $toolKey => $definition) {
            $expectedRoute = $this->expectedToolRoute($definition);

            $this->assertContains(
                $expectedRoute,
                $registeredRoutes,
                "Catalog tool '{$toolKey}' expects route '{$expectedRoute}' but it is not registered."
            );
        }
    }

    public function test_irs_notice_extract_is_in_method_aware_tool_catalog(): void
    {
        $definition = AgentToolRegistry::definition('irs_notice_extract');

        $this->assertIsArray($definition);
        $this->assertSame('POST', $definition['method']);
        $this->assertSame('irs/notice/extract', $definition['path_suffix']);
        $this->assertSame('global', $definition['scope']);
        $this->assertContains('text', $definition['request_schema']['json']);
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
     * Every ready orchestrator-mode process must appear in the orchestrator's supported routes.
     */
    public function test_orchestrator_processes_are_in_supported_routes(): void
    {
        $orchestrator = app(RexOrchestratorService::class);
        $supported = $orchestrator->supportedRoutes();

        foreach (RexProcess::cases() as $process) {
            if ($process->mode() !== 'orchestrator' || $process->readiness() === ProcessReadiness::Unavailable) {
                continue;
            }
            $this->assertContains(
                $process->value,
                $supported,
                "Orchestrator-mode process '{$process->value}' is not in RexOrchestratorService::supportedRoutes()."
            );
        }
    }

    public function test_supported_orchestrator_routes_have_handlers(): void
    {
        $orchestrator = app(RexOrchestratorService::class);

        foreach ($orchestrator->supportedRoutes() as $route) {
            $this->assertTrue(
                $orchestrator->hasRouteHandler($route),
                "Supported Rex route '{$route}' must have a RexOrchestratorService handler."
            );
        }
    }

    public function test_unavailable_orchestrator_processes_are_not_supported_routes(): void
    {
        $orchestrator = app(RexOrchestratorService::class);
        $supported = $orchestrator->supportedRoutes();

        foreach (RexProcess::cases() as $process) {
            if ($process->mode() !== 'orchestrator' || $process->readiness() !== ProcessReadiness::Unavailable) {
                continue;
            }

            $this->assertNotContains(
                $process->value,
                $supported,
                "Unavailable Rex process '{$process->value}' must not be advertised as a supported route."
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

    /**
     * Tool keys added in Phase 4 that are not in any process's tools() list must still
     * have both a registry entry and a registered Laravel route.
     */
    public function test_phase4_expansion_tool_keys_have_registered_routes(): void
    {
        $expansionKeys = ['transaction_detail', 'pending_recommendations', 'process_registry'];
        $definitions = AgentToolRegistry::definitions();
        $registeredRoutes = $this->registeredRoutes();

        foreach ($expansionKeys as $toolKey) {
            $this->assertArrayHasKey(
                $toolKey,
                $definitions,
                "Phase 4 tool key '{$toolKey}' is missing from AgentToolRegistry::definitions()."
            );

            $expectedRoute = $this->expectedToolRoute($definitions[$toolKey]);

            $this->assertContains(
                $expectedRoute,
                $registeredRoutes,
                "Phase 4 tool key '{$toolKey}' expects route '{$expectedRoute}' but it is not registered."
            );
        }
    }

    /**
     * Every action type whitelisted by BrevixAgentRunner must be supported by the executor.
     */
    public function test_runner_supported_actions_are_all_executor_supported(): void
    {
        $executor = app(AgentActionExecutorService::class);
        $executorSupported = $executor->supportedActionTypes();

        foreach (BrevixAgentRunner::SUPPORTED_RECOMMENDED_ACTIONS as $actionType) {
            $this->assertContains(
                $actionType,
                $executorSupported,
                "Action type '{$actionType}' is whitelisted by BrevixAgentRunner but has no executor case."
            );
        }
    }

    /**
     * Every action type the executor supports must be declared by at least one process in the registry.
     */
    public function test_executor_supported_actions_appear_in_at_least_one_process(): void
    {
        $executor = app(AgentActionExecutorService::class);

        $allRegistryTypes = [];
        foreach (RexProcess::cases() as $process) {
            foreach ($process->approvalTypes() as $type) {
                $allRegistryTypes[] = $type;
            }
        }

        foreach ($executor->supportedActionTypes() as $actionType) {
            $this->assertContains(
                $actionType,
                $allRegistryTypes,
                "Executor supports '{$actionType}' but it is not declared in any process's approvalTypes()."
            );
        }
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

    /** @param array<string, mixed> $definition */
    private function expectedToolRoute(array $definition): string
    {
        $path = preg_replace('/\{[^}]+\}/', '{companyId}', (string) $definition['path_suffix']);

        return $definition['method'].' api/internal/agent-tools/'.$path;
    }
}
