<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Company;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FirstSnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'alerts',
            'qbo_transactions',
            'gnucash_imports',
            'integrations',
            'uploads',
            'onboarding_answers',
            'onboarding_sessions',
            'workspace_memberships',
            'business_profiles',
            'users',
            'companies',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        $this->createSchema();
    }

    public function test_first_snapshot_with_no_evidence(): void
    {
        [$company, $user, $profile] = $this->createWorkspace();
        Sanctum::actingAs($user);

        $this->patchJson('/api/onboarding/session', [
            'primaryIntent' => 'routine_books_review',
        ], ['X-Brevix-Business-Profile-Id' => $profile->id])->assertOk();

        $this->postJson('/api/reviews/first-snapshot', [], ['X-Brevix-Business-Profile-Id' => $profile->id])
            ->assertOk()
            ->assertJsonPath('contractVersion', '2026-05-31')
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('confidence', 'low')
            ->assertJsonPath('readinessScore', 0)
            ->assertJsonPath('riskIndicators', [])
            ->assertJsonPath('dataQualityIssues.0.issueKey', 'insufficient_required_evidence');
    }

    public function test_first_snapshot_with_sufficient_evidence(): void
    {
        [$company, $user, $profile] = $this->createWorkspace();
        Sanctum::actingAs($user);

        $this->patchJson('/api/onboarding/session', [
            'primaryIntent' => 'routine_books_review',
        ], ['X-Brevix-Business-Profile-Id' => $profile->id])->assertOk();

        DB::table('uploads')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'business_profile_id' => $profile->id,
            'uploaded_by' => $user->id,
            'filename' => 'ledger.csv',
            'original_filename' => 'ledger.csv',
            'status' => 'promoted',
            'row_count' => 42,
            'import_type' => 'transaction_ledger',
            'created_at' => now(),
            'uploaded_at' => now(),
            'promoted_at' => now(),
        ]);

        DB::table('alerts')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'business_profile_id' => $profile->id,
            'title' => 'Unusual spike in transactions',
            'severity' => 'warning',
            'status' => 'open',
            'created_at' => now(),
        ]);

        $this->postJson('/api/reviews/first-snapshot', [], ['X-Brevix-Business-Profile-Id' => $profile->id])
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('confidence', 'medium')
            ->assertJsonPath('dataQualityIssues', [])
            ->assertJsonPath('riskIndicators.0.title', 'Unusual spike in transactions');
    }

    private function createSchema(): void
    {
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
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('role')->default('owner');
            $table->timestamps();
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

        Schema::create('onboarding_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->string('status')->default('in_progress');
            $table->string('primary_intent')->nullable();
            $table->string('current_step')->default('intent');
            $table->date('review_period_start')->nullable();
            $table->date('review_period_end')->nullable();
            $table->string('scope_mode')->default('standard');
            $table->json('business_context')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('scope_acknowledged_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('onboarding_answers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('onboarding_session_id');
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->uuid('answered_by')->nullable();
            $table->string('answer_key');
            $table->json('answer_value');
            $table->timestamps();
        });

        Schema::create('uploads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->uuid('uploaded_by');
            $table->text('filename');
            $table->text('original_filename')->nullable();
            $table->string('status')->default('created');
            $table->integer('row_count')->default(0);
            $table->string('import_type')->nullable();
            $table->text('status_detail')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->timestamp('validated_at')->nullable();
        });

        Schema::create('integrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->string('provider');
            $table->string('realm_id')->nullable();
            $table->string('sync_status')->nullable();
            $table->unsignedSmallInteger('sync_progress')->default(0);
            $table->timestamps();
        });

        Schema::create('qbo_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->string('realm_id')->nullable();
            $table->date('transaction_date')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
        });

        Schema::create('gnucash_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->string('filename');
            $table->string('file_format');
            $table->string('status')->default('completed');
            $table->integer('transaction_count')->default(0);
            $table->integer('account_count')->default(0);
            $table->date('date_range_start')->nullable();
            $table->date('date_range_end')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->string('title');
            $table->string('severity')->default('info');
            $table->string('status')->default('open');
            $table->timestamp('created_at')->nullable();
        });
    }

    /** @return array{0: Company, 1: User, 2: BusinessProfile} */
    private function createWorkspace(): array
    {
        $company = new Company(['name' => 'Brevix Test Workspace']);
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

        WorkspaceMembership::create([
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'scope' => 'workspace',
            'granted_by' => $user->id,
        ]);

        $profile = $this->createProfile($company, 'Default Profile', isDefault: true);

        return [$company, $user, $profile];
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
}
