<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\RexOrchestratorService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessProfileTenancyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        foreach ([
            'alerts',
            'all_transactions',
            'chat_usage_daily',
            'chat_messages',
            'chat_sessions',
            'qbo_transactions',
            'integrations',
            'uploads',
            'business_profile_memberships',
            'workspace_memberships',
            'business_profiles',
            'subscriptions',
            'users',
            'companies',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        $this->createSchema();
    }

    public function test_free_and_starter_plans_are_limited_to_one_business_profile(): void
    {
        [$company, $owner] = $this->createWorkspace('free');
        $this->createProfile($company, 'Default Business', isDefault: true);

        Sanctum::actingAs($owner);

        $this->postJson('/api/business-profiles', ['name' => 'Second Business'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Your current plan is limited to one active business profile.');

        Subscription::where('company_id', $company->id)->update(['tier' => 'growth']);

        $this->postJson('/api/business-profiles', ['name' => 'Second Business'])
            ->assertCreated()
            ->assertJsonPath('businessProfile.name', 'Second Business');
    }

    public function test_auth_me_returns_business_profiles_with_profile_specific_role_override(): void
    {
        [$company, $owner] = $this->createWorkspace('growth');
        $defaultProfile = $this->createProfile($company, 'Main Business', isDefault: true);
        $restrictedProfile = $this->createProfile($company, 'Side Business');

        $member = $this->createUser($company, 'viewer');
        WorkspaceMembership::create([
            'company_id' => $company->id,
            'user_id' => $member->id,
            'role' => 'viewer',
            'scope' => 'workspace',
            'granted_by' => $owner->id,
        ]);
        DB::table('business_profile_memberships')->insert([
            'id' => (string) Str::uuid(),
            'business_profile_id' => $restrictedProfile->id,
            'user_id' => $member->id,
            'role' => 'admin',
            'granted_by' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($member);

        $response = $this->getJson('/api/auth/me')->assertOk();

        $profiles = collect($response->json('businessProfiles'))->keyBy('id');
        $this->assertSame('viewer', $profiles[$defaultProfile->id]['role']);
        $this->assertSame('admin', $profiles[$restrictedProfile->id]['role']);
    }

    public function test_uploads_enforce_free_file_limit_and_are_profile_scoped(): void
    {
        [$company, $owner] = $this->createWorkspace('free');
        $profileA = $this->createProfile($company, 'A', isDefault: true);
        $profileB = $this->createProfile($company, 'B');

        Sanctum::actingAs($owner);

        $this->postJson('/api/uploads', [
            'importType' => 'transaction_ledger',
            'originalFilename' => 'large.csv',
            'claimedContentType' => 'text/csv',
            'fileSizeBytes' => (25 * 1024 * 1024) + 1,
        ], ['X-Brevix-Business-Profile-Id' => $profileA->id])
            ->assertStatus(422);

        $uploadId = $this->postJson('/api/uploads', [
            'importType' => 'transaction_ledger',
            'originalFilename' => 'small.csv',
            'claimedContentType' => 'text/csv',
            'fileSizeBytes' => 1024,
        ], ['X-Brevix-Business-Profile-Id' => $profileA->id])
            ->assertCreated()
            ->json('uploadId');

        $this->assertDatabaseHas('uploads', [
            'id' => $uploadId,
            'company_id' => $company->id,
            'business_profile_id' => $profileA->id,
        ]);

        $this->getJson('/api/uploads', ['X-Brevix-Business-Profile-Id' => $profileB->id])
            ->assertOk()
            ->assertJsonCount(0);

        $this->getJson('/api/uploads', ['X-Brevix-Business-Profile-Id' => $profileA->id])
            ->assertOk()
            ->assertJsonPath('0.id', $uploadId);
    }

    public function test_quickbooks_status_is_scoped_to_active_business_profile(): void
    {
        [$company, $owner] = $this->createWorkspace('growth');
        $profileA = $this->createProfile($company, 'A', isDefault: true);
        $profileB = $this->createProfile($company, 'B');

        DB::table('integrations')->insert([
            [
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'business_profile_id' => $profileA->id,
                'provider' => 'quickbooks',
                'realm_id' => 'realm-a',
                'access_token_enc' => encrypt('token-a'),
                'refresh_token_enc' => encrypt('refresh-a'),
                'environment' => 'sandbox',
                'sync_status' => 'idle',
                'sync_progress' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'business_profile_id' => $profileB->id,
                'provider' => 'quickbooks',
                'realm_id' => 'realm-b',
                'access_token_enc' => encrypt('token-b'),
                'refresh_token_enc' => encrypt('refresh-b'),
                'environment' => 'sandbox',
                'sync_status' => 'idle',
                'sync_progress' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Sanctum::actingAs($owner);

        $this->getJson('/api/integrations/status', ['X-Brevix-Business-Profile-Id' => $profileA->id])
            ->assertOk()
            ->assertJsonCount(1, 'integrations')
            ->assertJsonPath('integrations.0.realm_id', 'realm-a');
    }

    public function test_rex_chat_sessions_are_profile_scoped(): void
    {
        [$company, $owner] = $this->createWorkspace('growth');
        $profileA = $this->createProfile($company, 'A', isDefault: true);
        $profileB = $this->createProfile($company, 'B');

        Sanctum::actingAs($owner);

        $sessionA = $this->postJson('/api/chat/sessions', ['title' => 'A'], ['X-Brevix-Business-Profile-Id' => $profileA->id])
            ->assertCreated()
            ->json('id');

        $this->postJson('/api/chat/sessions', ['title' => 'B'], ['X-Brevix-Business-Profile-Id' => $profileB->id])
            ->assertCreated();

        $this->getJson('/api/chat/sessions', ['X-Brevix-Business-Profile-Id' => $profileA->id])
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $sessionA);
    }

    public function test_dashboard_and_analytics_require_profile_context_for_multi_profile_workspace(): void
    {
        [$company, $owner] = $this->createWorkspace('growth');
        $this->createProfile($company, 'A', isDefault: true);
        $this->createProfile($company, 'B');

        Sanctum::actingAs($owner);

        $this->getJson('/api/dashboard/summary')
            ->assertStatus(422)
            ->assertJsonPath('error', 'Select an active business profile before continuing.');

        $this->getJson('/api/analytics/summary')
            ->assertStatus(422)
            ->assertJsonPath('error', 'Select an active business profile before continuing.');
    }

    public function test_dashboard_and_analytics_use_single_profile_fallback(): void
    {
        [$company, $owner] = $this->createWorkspace('starter');
        $profile = $this->createProfile($company, 'Default Business', isDefault: true);

        $this->insertTransaction($company->id, $profile->id, 'Fallback Vendor', 125.25);

        Sanctum::actingAs($owner);

        $this->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('stats.totalTransactions', 1)
            ->assertJsonPath('stats.vendorsMonitored', 1)
            ->assertJsonPath('stats.amountReviewed', 125.25);

        $this->getJson('/api/analytics/summary')
            ->assertOk()
            ->assertJsonPath('transactionCount.value', 1)
            ->assertJsonPath('totalSpend.value', 125.25);
    }

    public function test_dashboard_and_analytics_are_profile_scoped(): void
    {
        [$company, $owner] = $this->createWorkspace('growth');
        $profileA = $this->createProfile($company, 'A', isDefault: true);
        $profileB = $this->createProfile($company, 'B');

        $this->insertTransaction($company->id, $profileA->id, 'Profile A Vendor', 100.00);
        $this->insertTransaction($company->id, $profileA->id, 'Profile A Vendor', 50.00, anomaly: true);
        $this->insertTransaction($company->id, $profileB->id, 'Profile B Vendor', 999.00, anomaly: true);

        $this->insertAlert($company->id, $profileA->id, 'Profile A Finding', 'critical', 'duplicate_invoice');
        $this->insertAlert($company->id, $profileB->id, 'Profile B Finding', 'warning', 'cash_spike');

        Sanctum::actingAs($owner);

        $this->getJson('/api/dashboard/summary', ['X-Brevix-Business-Profile-Id' => $profileA->id])
            ->assertOk()
            ->assertJsonPath('stats.totalTransactions', 2)
            ->assertJsonPath('stats.flaggedAlerts', 1)
            ->assertJsonPath('stats.vendorsMonitored', 1)
            ->assertJsonPath('stats.amountReviewed', 150)
            ->assertJsonPath('riskScore', 20)
            ->assertJsonPath('recentAlerts.0.title', 'Profile A Finding')
            ->assertJsonPath('alertBreakdown.Duplicate Invoice', 1);

        $this->getJson('/api/analytics/summary', ['X-Brevix-Business-Profile-Id' => $profileA->id])
            ->assertOk()
            ->assertJsonPath('totalSpend.value', 150)
            ->assertJsonPath('transactionCount.value', 2)
            ->assertJsonPath('flaggedAmount.value', 50);

        $this->getJson('/api/analytics/vendors?limit=1', ['X-Brevix-Business-Profile-Id' => $profileA->id])
            ->assertOk()
            ->assertJsonPath('0.name', 'Profile A Vendor')
            ->assertJsonPath('0.amount', 150)
            ->assertJsonPath('0.count', 2);

        $this->getJson('/api/analytics/cash-flow', ['X-Brevix-Business-Profile-Id' => $profileA->id])
            ->assertOk()
            ->assertJsonPath('monthlyBurn', 150)
            ->assertJsonPath('trailingMonths.0.spend', 150);
    }

    public function test_rex_alert_route_is_profile_scoped(): void
    {
        [$company] = $this->createWorkspace('growth');
        $profileA = $this->createProfile($company, 'A', isDefault: true);
        $profileB = $this->createProfile($company, 'B');

        $this->insertAlert($company->id, $profileA->id, 'Profile A Finding', 'critical', 'duplicate_invoice');
        $this->insertAlert($company->id, $profileB->id, 'Profile B Finding', 'warning', 'cash_spike');

        $result = app(RexOrchestratorService::class)->handleRoute($company->id, 'alerts', $profileA->id);

        $alerts = collect($result['artifacts'][0]['data']['alerts'] ?? []);

        $this->assertCount(1, $alerts);
        $this->assertSame('Profile A Finding', $alerts->first()->title);
        $this->assertSame($profileA->id, $alerts->first()->business_profile_id);
    }

    private function createSchema(): void
    {
        Schema::create('all_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->date('date')->nullable();
            $table->text('vendor_customer')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->boolean('anomaly_flag')->default(false);
        });

        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('industry')->nullable();
            $table->string('entity_type')->nullable();
            $table->boolean('has_completed_onboarding')->default(false);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('role')->default('owner');
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->uuid('company_id')->primary();
            $table->string('tier', 50)->default('free');
            $table->string('status', 50)->default('active');
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('business_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('industry')->nullable();
            $table->string('entity_type')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('workspace_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('user_id');
            $table->string('role');
            $table->string('scope')->default('workspace');
            $table->uuid('granted_by')->nullable();
            $table->timestamps();
        });

        Schema::create('business_profile_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_profile_id');
            $table->uuid('user_id');
            $table->string('role');
            $table->uuid('granted_by')->nullable();
            $table->timestamps();
        });

        Schema::create('uploads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->uuid('uploaded_by');
            $table->text('filename');
            $table->integer('file_size')->nullable();
            $table->text('status')->default('created');
            $table->integer('row_count')->default(0);
            $table->text('import_type')->nullable();
            $table->text('original_filename')->nullable();
            $table->text('storage_filename')->nullable();
            $table->text('quarantine_bucket')->nullable();
            $table->text('quarantine_key')->nullable();
            $table->text('claimed_content_type')->nullable();
            $table->text('file_extension')->nullable();
            $table->bigInteger('file_size_bytes')->nullable();
            $table->text('status_detail')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('integrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->string('provider', 50);
            $table->string('realm_id', 255)->nullable();
            $table->text('access_token_enc')->nullable();
            $table->text('refresh_token_enc')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->text('client_id_enc')->nullable();
            $table->text('client_secret_enc')->nullable();
            $table->string('environment', 50)->nullable();
            $table->string('sync_status', 50)->nullable();
            $table->unsignedSmallInteger('sync_progress')->default(0);
            $table->text('sync_error')->nullable();
            $table->timestamps();
        });

        Schema::create('qbo_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->string('qbo_id')->nullable();
            $table->string('realm_id')->nullable();
            $table->date('transaction_date')->nullable();
            $table->text('vendor_name')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->text('type')->nullable();
        });

        Schema::create('chat_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->uuid('user_id');
            $table->string('title')->default('New Chat with Rex');
            $table->timestamps();
        });

        Schema::create('chat_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('session_id');
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->string('role');
            $table->text('content');
            $table->json('structured_payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('chat_usage_daily', function (Blueprint $table): void {
            $table->id();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->date('date');
            $table->integer('message_count')->default(0);
            $table->timestamps();
        });

        Schema::create('alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->string('title');
            $table->text('detail')->nullable();
            $table->string('severity');
            $table->string('status')->default('open');
            $table->string('rule_key')->nullable();
            $table->timestamps();
        });
    }

    /** @return array{0: Company, 1: User} */
    private function createWorkspace(string $tier): array
    {
        $company = new Company(['name' => 'Brevix Test Workspace']);
        $company->id = (string) Str::uuid();
        $company->save();

        Subscription::create([
            'company_id' => $company->id,
            'tier' => $tier,
            'status' => 'active',
        ]);

        $owner = $this->createUser($company, 'owner');
        WorkspaceMembership::create([
            'company_id' => $company->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'scope' => 'workspace',
            'granted_by' => $owner->id,
        ]);

        return [$company, $owner];
    }

    private function createProfile(Company $company, string $name, bool $isDefault = false): BusinessProfile
    {
        $profile = new BusinessProfile([
            'company_id' => $company->id,
            'name' => $name,
            'is_default' => $isDefault,
            'status' => 'active',
        ]);
        $profile->id = (string) Str::uuid();
        $profile->save();

        return $profile;
    }

    private function createUser(Company $company, string $role): User
    {
        $user = new User([
            'company_id' => $company->id,
            'email' => Str::uuid().'@example.com',
            'password_hash' => Hash::make('password'),
            'role' => $role,
        ]);
        $user->id = (string) Str::uuid();
        $user->save();

        return $user;
    }

    private function insertTransaction(
        string $companyId,
        string $businessProfileId,
        string $vendor,
        float $amount,
        bool $anomaly = false,
    ): void {
        DB::table('all_transactions')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'business_profile_id' => $businessProfileId,
            'date' => now()->startOfMonth()->addDays(2)->toDateString(),
            'vendor_customer' => $vendor,
            'amount' => $amount,
            'anomaly_flag' => $anomaly,
        ]);
    }

    private function insertAlert(string $companyId, string $businessProfileId, string $title, string $severity, string $ruleKey): void
    {
        DB::table('alerts')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'business_profile_id' => $businessProfileId,
            'title' => $title,
            'detail' => $title.' detail',
            'severity' => $severity,
            'status' => 'open',
            'rule_key' => $ruleKey,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
