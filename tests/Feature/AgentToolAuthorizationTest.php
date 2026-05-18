<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\Agents\AgentRiskAnalysisService;
use Illuminate\Support\Facades\DB;
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

        Schema::dropIfExists('reconciliation_mismatches');
        Schema::dropIfExists('transaction_reviews');
        Schema::dropIfExists('audit_cases');
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('reconciliation_results');
        Schema::dropIfExists('all_transactions');
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

    public function test_company_context_can_return_sanitized_transaction_summary(): void
    {
        [$company, $user] = $this->createCompanyUser(industry: 'Retail');
        $this->createTransactionToolTables();

        DB::table('all_transactions')->insert([
            [
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'date' => '2026-05-17',
                'department' => 'Finance',
                'vendor_customer' => 'Acme Supplies',
                'type' => 'expense',
                'category' => 'Office Supplies',
                'payment_method' => 'card',
                'amount' => 125.50,
                'invoice_ref' => 'INV-100',
                'memo' => 'Office supplies order',
                'anomaly_flag' => false,
                'anomaly_reason' => null,
                'source_type' => 'file_upload',
                'source_name' => 'May ledger',
            ],
            [
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'date' => '2026-05-01',
                'department' => 'Finance',
                'vendor_customer' => 'Older Vendor',
                'type' => 'expense',
                'category' => 'Services',
                'payment_method' => 'ach',
                'amount' => 300.00,
                'invoice_ref' => 'INV-099',
                'memo' => 'Outside range',
                'anomaly_flag' => false,
                'anomaly_reason' => null,
                'source_type' => 'file_upload',
                'source_name' => 'May ledger',
            ],
        ]);

        $this->withToken('test-agent-key')
            ->withHeader('X-Brevix-User-Id', $user->id)
            ->getJson("/api/internal/agent-tools/companies/{$company->id}/context?include_transactions=1&date_from=2026-05-14&date_to=2026-05-18&limit=5")
            ->assertOk()
            ->assertJsonPath('transaction_summary.total', 1)
            ->assertJsonPath('transaction_summary.returned_count', 1)
            ->assertJsonPath('transaction_summary.transactions.0.vendor', 'Acme Supplies')
            ->assertJsonPath('transaction_summary.transactions.0.amount', 125.5)
            ->assertJsonMissingPath('transaction_summary.transactions.0.memo')
            ->assertJsonMissingPath('transaction_summary.transactions.0.invoice_ref');
    }

    public function test_company_context_can_return_sanitized_dashboard_summary(): void
    {
        [$company, $user] = $this->createCompanyUser(industry: 'Retail');
        $this->createDashboardToolTables();

        DB::table('all_transactions')->insert([
            [
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'date' => now()->format('Y-m-d'),
                'vendor_customer' => 'Acme Supplies',
                'amount' => 125.50,
            ],
            [
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'date' => now()->format('Y-m-d'),
                'vendor_customer' => 'Northstar Consulting',
                'amount' => 2500.00,
            ],
        ]);
        DB::table('alerts')->insert([
            [
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'rule_key' => 'duplicate_invoice',
                'title' => 'Sensitive alert title should not be returned',
                'detail' => 'Sensitive alert detail should not be returned',
                'severity' => 'warning',
                'status' => 'open',
                'created_at' => now(),
            ],
        ]);

        $this->withToken('test-agent-key')
            ->withHeader('X-Brevix-User-Id', $user->id)
            ->getJson("/api/internal/agent-tools/companies/{$company->id}/context?include_dashboard=1")
            ->assertOk()
            ->assertJsonPath('dashboard_summary.risk_score', 10)
            ->assertJsonPath('dashboard_summary.total_transactions', 2)
            ->assertJsonPath('dashboard_summary.flagged_alerts', 1)
            ->assertJsonPath('dashboard_summary.vendors_monitored', 2)
            ->assertJsonMissingPath('dashboard_summary.recentAlerts')
            ->assertJsonMissingPath('dashboard_summary.alertBreakdown')
            ->assertJsonMissing(['title' => 'Sensitive alert title should not be returned']);
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

    private function createTransactionToolTables(): void
    {
        Schema::create('all_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->date('date')->nullable();
            $table->text('department')->nullable();
            $table->text('vendor_customer')->nullable();
            $table->text('type')->nullable();
            $table->text('category')->nullable();
            $table->text('payment_method')->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->text('invoice_ref')->nullable();
            $table->text('memo')->nullable();
            $table->boolean('anomaly_flag')->default(false);
            $table->text('anomaly_reason')->nullable();
            $table->text('source_type')->nullable();
            $table->text('source_name')->nullable();
        });
    }

    private function createDashboardToolTables(): void
    {
        Schema::create('all_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->date('date')->nullable();
            $table->text('vendor_customer')->nullable();
            $table->decimal('amount', 14, 2)->nullable();
        });

        Schema::create('alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('rule_key')->nullable();
            $table->string('title')->nullable();
            $table->text('detail')->nullable();
            $table->string('severity')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
}
