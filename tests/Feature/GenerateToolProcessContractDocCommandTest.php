<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class GenerateToolProcessContractDocCommandTest extends TestCase
{
    public function test_command_generates_tool_and_process_contract_page_from_code(): void
    {
        $path = storage_path('framework/testing/tool-process-contracts.md');
        File::delete($path);

        $this->artisan('contracts:generate-tool-process-doc', ['--path' => $path])
            ->assertExitCode(0);

        $this->assertFileExists($path);

        $contents = File::get($path);

        $this->assertStringContainsString('Generated from `AgentToolRegistry`, `RexProcess`, and `RexOrchestratorService`.', $contents);
        $this->assertStringContainsString('`irs_notice_extract` | `POST` | `/api/internal/agent-tools/irs/notice/extract`', $contents);
        $this->assertStringContainsString('`tax_notice_review` | orchestrator | unavailable | not advertised', $contents);
        $this->assertStringContainsString('`entity_graph_review` | orchestrator | available | orchestrator', $contents);

        File::delete($path);
    }
}
