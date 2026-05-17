<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\Agents\AgentRiskAnalysisService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AgentToolAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.brevix_agent.api_key' => 'test-agent-key']);

        Schema::dropIfExists('uploads');
        Schema::dropIfExists('users');
        Schema::dropIfExists('companies');

        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('industry')->nullable();
            $table->string('size')->nullable();
            $table->string('website')->nullable();
            $table->string('entity_type')->nullable();
            $table->boolean('has_completed_onboarding')->default(false);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->nullable();
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('role')->default('owner');
            $table->boolean('is_verified')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('uploads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id');
            $table->string('filename');
        });
    }

    public function test_agent_tool_endpoint_rejects_missing_service_key(): void
    {
        [$company] = $this->createCompanyUser();

        $this->getJson("/api/internal/agent-tools/companies/{$company->id}/context")
            ->assertUnauthorized()
            ->assertJson(['error' => 'Unauthorized agent tool request']);
    }

    public function test_agent_tool_endpoint_requires_user_context_for_tenant_boundary(): void
    {
        [$company] = $this->createCompanyUser();

        $this->withToken('test-agent-key')
            ->getJson("/api/internal/agent-tools/companies/{$company->id}/context")
            ->assertForbidden()
            ->assertJson(['error' => 'User is not authorized for this company']);
    }

    public function test_agent_tool_endpoint_rejects_invalid_period(): void
    {
        [$company, $user] = $this->createCompanyUser();

        $this->withToken('test-agent-key')
            ->withHeader('X-Brevix-User-Id', $user->id)
            ->getJson("/api/internal/agent-tools/companies/{$company->id}/risk-summary?period=2026-99")
            ->assertUnprocessable()
            ->assertJson(['error' => 'Invalid period. Use YYYY-MM.']);
    }

    public function test_agent_tool_endpoint_returns_safe_error_when_tool_fails(): void
    {
        [$company, $user] = $this->createCompanyUser();

        $this->app->instance(AgentRiskAnalysisService::class, new class extends AgentRiskAnalysisService {
            public function riskSummary(string $companyId, ?string $period = null): array
            {
                throw new RuntimeException('Sensitive transaction details should not be exposed.');
            }
        });

        $response = $this->withToken('test-agent-key')
            ->withHeader('X-Brevix-User-Id', $user->id)
            ->getJson("/api/internal/agent-tools/companies/{$company->id}/risk-summary?period=2026-05");

        $response->assertStatus(500)
            ->assertJson(['error' => 'Agent tool could not complete the request safely']);
        $this->assertArrayNotHasKey('exception', $response->json());
        $this->assertArrayNotHasKey('trace', $response->json());
    }

    public function test_company_context_returns_authorized_company_context(): void
    {
        [$company, $user] = $this->createCompanyUser(industry: 'Retail');

        $this->withToken('test-agent-key')
            ->withHeader('X-Brevix-User-Id', $user->id)
            ->getJson("/api/internal/agent-tools/companies/{$company->id}/context")
            ->assertOk()
            ->assertJsonPath('company_id', $company->id)
            ->assertJsonPath('company_name', 'Brevix Test Co')
            ->assertJsonPath('industry', 'Retail')
            ->assertJsonPath('user_role', 'owner');
    }

    /**
     * @return array{0: Company, 1: User}
     */
    private function createCompanyUser(?string $industry = null): array
    {
        $company = new Company([
            'name' => 'Brevix Test Co',
            'industry' => $industry,
        ]);
        $company->id = (string) Str::uuid();
        $company->save();

        $user = new User([
            'company_id' => $company->id,
            'email' => Str::uuid() . '@example.com',
            'password_hash' => Hash::make('password'),
            'first_name' => 'Test',
            'last_name' => 'User',
            'role' => 'owner',
        ]);
        $user->id = (string) Str::uuid();
        $user->save();

        return [$company, $user];
    }
}
