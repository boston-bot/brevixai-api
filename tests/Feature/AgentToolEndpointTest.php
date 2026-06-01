<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\Agents\AlertRecommendationService;
use App\Services\Agents\CaseRecommendationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature tests for Phase 4 internal agent tool endpoints:
 * process-registry, pending-recommendations, and transaction-detail.
 */
class AgentToolEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.brevix_agent.api_key' => 'test-tool-key']);

        Schema::dropIfExists('transactions');
        Schema::dropIfExists('business_profile_memberships');
        Schema::dropIfExists('workspace_memberships');
        Schema::dropIfExists('business_profiles');
        Schema::dropIfExists('users');
        Schema::dropIfExists('companies');

        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->boolean('has_completed_onboarding')->default(false);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('role')->default('owner');
            $table->timestamps();
        });

        Schema::create('transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->string('date')->nullable();
            $table->string('vendor_customer')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('type')->nullable();
            $table->string('category')->nullable();
            $table->string('payment_method')->nullable();
            $table->boolean('anomaly_flag')->default(false);
            $table->text('anomaly_reason')->nullable();
            $table->text('memo')->nullable();
        });
    }

    // ── process-registry ─────────────────────────────────────────────────────

    public function test_process_registry_returns_401_without_agent_key(): void
    {
        $this->getJson('/api/internal/agent-tools/process-registry')
            ->assertUnauthorized();
    }

    public function test_process_registry_returns_action_types_array(): void
    {
        $response = $this->withToken('test-tool-key')
            ->getJson('/api/internal/agent-tools/process-registry')
            ->assertOk();

        $types = $response->json('action_types');
        $this->assertIsArray($types);
        $this->assertNotEmpty($types);
    }

    public function test_process_registry_includes_create_alert_as_executable(): void
    {
        $response = $this->withToken('test-tool-key')
            ->getJson('/api/internal/agent-tools/process-registry')
            ->assertOk();

        $createAlert = collect($response->json('action_types'))->firstWhere('type', 'create_alert');

        $this->assertNotNull($createAlert, "'create_alert' must appear in process registry action_types");
        $this->assertTrue($createAlert['requires_approval']);
        $this->assertTrue($createAlert['executable']);
    }

    public function test_process_registry_non_executable_types_have_executable_false(): void
    {
        $response = $this->withToken('test-tool-key')
            ->getJson('/api/internal/agent-tools/process-registry')
            ->assertOk();

        $nonExecutable = collect($response->json('action_types'))
            ->filter(fn (array $t) => ! $t['executable'])
            ->values();

        foreach ($nonExecutable as $t) {
            $this->assertFalse($t['executable'], "Type '{$t['type']}' must have executable=false");
        }
    }

    // ── transaction-detail ────────────────────────────────────────────────────

    public function test_transaction_detail_returns_401_without_agent_key(): void
    {
        [$company, $user] = $this->createCompanyUser();

        $this->getJson("/api/internal/agent-tools/company/{$company->id}/transaction-detail?ids[]=".Str::uuid())
            ->assertUnauthorized();
    }

    public function test_transaction_detail_returns_403_without_user_id_header(): void
    {
        [$company] = $this->createCompanyUser();

        $this->withToken('test-tool-key')
            ->getJson("/api/internal/agent-tools/company/{$company->id}/transaction-detail?ids[]=".Str::uuid())
            ->assertForbidden();
    }

    public function test_transaction_detail_returns_422_when_ids_are_missing(): void
    {
        [$company, $user] = $this->createCompanyUser();

        $this->withToken('test-tool-key')
            ->withHeaders(['X-Brevix-User-Id' => $user->id])
            ->getJson("/api/internal/agent-tools/company/{$company->id}/transaction-detail")
            ->assertUnprocessable();
    }

    public function test_transaction_detail_returns_422_for_non_uuid_ids(): void
    {
        [$company, $user] = $this->createCompanyUser();

        $this->withToken('test-tool-key')
            ->withHeaders(['X-Brevix-User-Id' => $user->id])
            ->getJson("/api/internal/agent-tools/company/{$company->id}/transaction-detail?ids[]=not-a-uuid")
            ->assertUnprocessable();
    }

    public function test_transaction_detail_returns_422_when_more_than_20_ids_provided(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $ids = array_map(fn () => (string) Str::uuid(), range(1, 21));
        $query = implode('&', array_map(fn ($id) => "ids[]={$id}", $ids));

        $this->withToken('test-tool-key')
            ->withHeaders(['X-Brevix-User-Id' => $user->id])
            ->getJson("/api/internal/agent-tools/company/{$company->id}/transaction-detail?{$query}")
            ->assertUnprocessable();
    }

    public function test_transaction_detail_returns_matched_transactions(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $txnId = (string) Str::uuid();

        DB::table('transactions')->insert([
            'id' => $txnId,
            'company_id' => $company->id,
            'date' => '2026-05-01',
            'vendor_customer' => 'ACME Corp',
            'amount' => '500.00',
            'type' => 'expense',
            'category' => 'Software',
            'anomaly_flag' => 0,
        ]);

        $response = $this->withToken('test-tool-key')
            ->withHeaders(['X-Brevix-User-Id' => $user->id])
            ->getJson("/api/internal/agent-tools/company/{$company->id}/transaction-detail?ids[]={$txnId}")
            ->assertOk();

        $this->assertSame(1, $response->json('found_count'));
        $this->assertSame($txnId, $response->json('transactions.0.id'));
        $this->assertSame('ACME Corp', $response->json('transactions.0.vendor'));
        $this->assertEquals(500.0, $response->json('transactions.0.amount'));
    }

    public function test_transaction_detail_does_not_return_other_company_transactions(): void
    {
        [$companyA, $userA] = $this->createCompanyUser('Company A');
        [$companyB] = $this->createCompanyUser('Company B');
        $txnId = (string) Str::uuid();

        DB::table('transactions')->insert([
            'id' => $txnId,
            'company_id' => $companyB->id,
            'amount' => '100.00',
            'anomaly_flag' => 0,
        ]);

        $response = $this->withToken('test-tool-key')
            ->withHeaders(['X-Brevix-User-Id' => $userA->id])
            ->getJson("/api/internal/agent-tools/company/{$companyA->id}/transaction-detail?ids[]={$txnId}")
            ->assertOk();

        $this->assertSame(0, $response->json('found_count'));
        $this->assertEmpty($response->json('transactions'));
    }

    public function test_transaction_detail_is_scoped_to_active_business_profile(): void
    {
        [$company, $user] = $this->createCompanyUser();
        [$profileA, $profileB] = $this->createBusinessProfiles($company->id);
        $txnA = (string) Str::uuid();
        $txnB = (string) Str::uuid();

        DB::table('transactions')->insert([
            [
                'id' => $txnA,
                'company_id' => $company->id,
                'business_profile_id' => $profileA,
                'date' => '2026-05-01',
                'vendor_customer' => 'Profile A Vendor',
                'amount' => '500.00',
                'anomaly_flag' => 0,
            ],
            [
                'id' => $txnB,
                'company_id' => $company->id,
                'business_profile_id' => $profileB,
                'date' => '2026-05-02',
                'vendor_customer' => 'Profile B Vendor',
                'amount' => '700.00',
                'anomaly_flag' => 0,
            ],
        ]);

        $response = $this->withToken('test-tool-key')
            ->withHeaders([
                'X-Brevix-User-Id' => $user->id,
                'X-Brevix-Business-Profile-Id' => $profileA,
            ])
            ->getJson("/api/internal/agent-tools/company/{$company->id}/transaction-detail?ids[]={$txnA}&ids[]={$txnB}")
            ->assertOk();

        $this->assertSame($profileA, $response->json('business_profile_id'));
        $this->assertSame(1, $response->json('found_count'));
        $this->assertSame($txnA, $response->json('transactions.0.id'));
        $this->assertSame('Profile A Vendor', $response->json('transactions.0.vendor'));
    }

    // ── pending-recommendations ───────────────────────────────────────────────

    public function test_pending_recommendations_returns_401_without_agent_key(): void
    {
        [$company] = $this->createCompanyUser();

        $this->getJson("/api/internal/agent-tools/company/{$company->id}/pending-recommendations")
            ->assertUnauthorized();
    }

    public function test_pending_recommendations_returns_403_without_user_id_header(): void
    {
        [$company] = $this->createCompanyUser();

        $this->withToken('test-tool-key')
            ->getJson("/api/internal/agent-tools/company/{$company->id}/pending-recommendations")
            ->assertForbidden();
    }

    public function test_pending_recommendations_returns_response_shape(): void
    {
        [$company, $user] = $this->createCompanyUser();

        $this->mock(AlertRecommendationService::class, function ($mock): void {
            $mock->shouldReceive('getAlertRecommendations')
                ->once()
                ->andReturn(['recommended_alerts' => []]);
        });
        $this->mock(CaseRecommendationService::class, function ($mock): void {
            $mock->shouldReceive('getCaseRecommendations')
                ->once()
                ->andReturn(['case_recommendations' => []]);
        });

        $response = $this->withToken('test-tool-key')
            ->withHeaders(['X-Brevix-User-Id' => $user->id])
            ->getJson("/api/internal/agent-tools/company/{$company->id}/pending-recommendations")
            ->assertOk();

        $this->assertSame($company->id, $response->json('company_id'));
        $this->assertIsArray($response->json('alert_recommendations'));
        $this->assertIsArray($response->json('case_recommendations'));
    }

    public function test_pending_recommendations_requires_profile_when_workspace_has_multiple_profiles(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $this->createBusinessProfiles($company->id);

        $this->mock(AlertRecommendationService::class, function ($mock): void {
            $mock->shouldNotReceive('getAlertRecommendations');
        });
        $this->mock(CaseRecommendationService::class, function ($mock): void {
            $mock->shouldNotReceive('getCaseRecommendations');
        });

        $this->withToken('test-tool-key')
            ->withHeaders(['X-Brevix-User-Id' => $user->id])
            ->getJson("/api/internal/agent-tools/company/{$company->id}/pending-recommendations")
            ->assertUnprocessable()
            ->assertJson(['error' => 'Select an active business profile before continuing.']);
    }

    public function test_pending_recommendations_passes_active_business_profile_to_services(): void
    {
        [$company, $user] = $this->createCompanyUser();
        [$profileA] = $this->createBusinessProfiles($company->id);

        $this->mock(AlertRecommendationService::class, function ($mock) use ($company, $profileA): void {
            $mock->shouldReceive('getAlertRecommendations')
                ->once()
                ->with($company->id, $profileA)
                ->andReturn(['recommended_alerts' => [['id' => 'alert-rec-1']]]);
        });
        $this->mock(CaseRecommendationService::class, function ($mock) use ($company, $profileA): void {
            $mock->shouldReceive('getCaseRecommendations')
                ->once()
                ->with($company->id, $profileA)
                ->andReturn(['case_recommendations' => [['id' => 'case-rec-1']]]);
        });

        $response = $this->withToken('test-tool-key')
            ->withHeaders([
                'X-Brevix-User-Id' => $user->id,
                'X-Brevix-Business-Profile-Id' => $profileA,
            ])
            ->getJson("/api/internal/agent-tools/company/{$company->id}/pending-recommendations")
            ->assertOk();

        $this->assertSame($profileA, $response->json('business_profile_id'));
        $this->assertSame('alert-rec-1', $response->json('alert_recommendations.0.id'));
        $this->assertSame('case-rec-1', $response->json('case_recommendations.0.id'));
    }

    /** @return array{0: Company, 1: User} */
    private function createCompanyUser(string $name = 'Test Co'): array
    {
        $company = new Company(['name' => $name]);
        $company->id = (string) Str::uuid();
        $company->save();

        $user = new User([
            'company_id' => $company->id,
            'email' => Str::uuid().'@example.com',
            'password_hash' => Hash::make('password'),
            'role' => 'owner',
        ]);
        $user->id = (string) Str::uuid();
        $user->save();

        return [$company, $user];
    }

    /** @return array{0: string, 1: string} */
    private function createBusinessProfiles(string $companyId): array
    {
        Schema::create('business_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        $profileA = (string) Str::uuid();
        $profileB = (string) Str::uuid();

        DB::table('business_profiles')->insert([
            [
                'id' => $profileA,
                'company_id' => $companyId,
                'name' => 'Profile A',
                'is_default' => true,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $profileB,
                'company_id' => $companyId,
                'name' => 'Profile B',
                'is_default' => false,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        return [$profileA, $profileB];
    }
}
