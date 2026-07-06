<?php

namespace App\Console\Commands;

use App\Enums\RexProcess;
use App\Services\Agents\AgentToolRegistry;
use App\Services\RexOrchestratorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GenerateToolProcessContractDoc extends Command
{
    protected $signature = 'contracts:generate-tool-process-doc {--path=docs/tool-process-contracts.md : Output Markdown path}';

    protected $description = 'Generate the lightweight Rex process and agent tool contract documentation from code.';

    public function handle(RexOrchestratorService $orchestrator): int
    {
        $path = $this->resolveOutputPath((string) $this->option('path'));

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->renderMarkdown($orchestrator));

        $this->info("Generated tool/process contract doc: {$path}");

        return self::SUCCESS;
    }

    private function renderMarkdown(RexOrchestratorService $orchestrator): string
    {
        return implode("\n", [
            '# Tool And Process Contracts',
            '',
            'Generated from `AgentToolRegistry`, `RexProcess`, and `RexOrchestratorService`.',
            'Refresh with `php artisan contracts:generate-tool-process-doc`.',
            '',
            '## Agent Tool Catalog',
            '',
            $this->toolCatalogTable(),
            '',
            '## Rex Process Catalog',
            '',
            $this->processCatalogTable($orchestrator),
            '',
            '## Supported Orchestrator Routes',
            '',
            $this->orchestratorRouteTable($orchestrator),
            '',
        ]);
    }

    private function toolCatalogTable(): string
    {
        $rows = [
            '| Tool | Method | Path | Scope | Readiness | Optional | Advertised | Purpose |',
            '| --- | --- | --- | --- | --- | --- | --- | --- |',
        ];

        foreach (AgentToolRegistry::definitions() as $toolKey => $definition) {
            $rows[] = sprintf(
                '| `%s` | `%s` | `%s` | %s | %s | %s | %s | %s |',
                $this->escape($toolKey),
                $this->escape((string) $definition['method']),
                $this->escape('/api/internal/agent-tools/'.(string) $definition['path_suffix']),
                $this->escape((string) $definition['scope']),
                $this->escape((string) $definition['readiness']),
                $this->yesNo((bool) $definition['optional']),
                $this->yesNo((bool) ($definition['advertise_by_default'] ?? false)),
                $this->escape((string) $definition['purpose']),
            );
        }

        return implode("\n", $rows);
    }

    private function processCatalogTable(RexOrchestratorService $orchestrator): string
    {
        $rows = [
            '| Process | Mode | Readiness | Handler | Tools | Approval Types |',
            '| --- | --- | --- | --- | --- | --- |',
        ];

        foreach (RexProcess::cases() as $process) {
            $handler = $process->mode() === 'agent'
                ? 'agent service'
                : ($orchestrator->hasRouteHandler($process->value) ? 'orchestrator' : 'not advertised');

            $rows[] = sprintf(
                '| `%s` | %s | %s | %s | %s | %s |',
                $this->escape($process->value),
                $this->escape($process->mode()),
                $this->escape($process->readiness()->value),
                $this->escape($handler),
                $this->inlineList($process->tools()),
                $this->inlineList($process->approvalTypes()),
            );
        }

        return implode("\n", $rows);
    }

    private function orchestratorRouteTable(RexOrchestratorService $orchestrator): string
    {
        $rows = [
            '| Route | Handler |',
            '| --- | --- |',
        ];

        foreach ($orchestrator->supportedRoutes() as $route) {
            $rows[] = sprintf(
                '| `%s` | %s |',
                $this->escape($route),
                $this->yesNo($orchestrator->hasRouteHandler($route)),
            );
        }

        return implode("\n", $rows);
    }

    /** @param list<string> $values */
    private function inlineList(array $values): string
    {
        if ($values === []) {
            return 'none';
        }

        return implode(', ', array_map(
            fn (string $value): string => '`'.$this->escape($value).'`',
            $values
        ));
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }

    private function escape(string $value): string
    {
        return str_replace('|', '\\|', $value);
    }

    private function resolveOutputPath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return base_path($path);
    }
}
