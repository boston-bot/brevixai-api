<?php

namespace Tests\Feature;

use App\Models\AuditCase;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaxpayerTransparencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
    }

    public function test_case_transparency_groups_verified_claims_assumptions_and_unknowns(): void
    {
        [$company, $user, $profileId] = $this->createWorkspace();
        $case = $this->createCase($company->id, $user->id, $profileId);

        Sanctum::actingAs($user);

        $this->postJson("/api/cases/{$case->id}/transparency-items", [
            'category' => 'verified_fact',
            'status_key' => 'return_received',
            'tax_period' => '2024',
            'label' => '2024 return is not reflected as received',
            'detail' => 'The account transcript does not show a posted 2024 return.',
            'source_type' => 'irs_transcript',
            'source_label' => 'Account transcript',
            'source_reference' => 'Transcript pulled 2026-06-10',
            'metadata' => [
                'confidence_note' => 'Transcript reviewed',
                'notice_text' => 'raw private notice text',
            ],
        ], $this->profileHeaders($profileId))
            ->assertCreated()
            ->assertJsonPath('item.category', 'verified_fact')
            ->assertJsonPath('item.sourceType', 'irs_transcript')
            ->assertJsonMissing(['notice_text' => 'raw private notice text']);

        $this->postJson("/api/cases/{$case->id}/transparency-items", [
            'category' => 'unverified_claim',
            'label' => 'Representative states amended return package was mailed',
            'source_type' => 'representative_statement',
            'source_label' => 'Representative email',
        ], $this->profileHeaders($profileId))->assertCreated();

        $this->postJson("/api/cases/{$case->id}/transparency-items", [
            'category' => 'assumption',
            'label' => 'IRS review is assumed to be pending',
            'detail' => 'No transcript or notice confirms the review queue.',
            'source_type' => 'internal_note',
        ], $this->profileHeaders($profileId))->assertCreated();

        $this->postJson("/api/cases/{$case->id}/transparency-items", [
            'category' => 'unknown',
            'label' => 'Internal IRS queue assignment',
            'detail' => 'The current assigned IRS function is not independently visible.',
        ], $this->profileHeaders($profileId))->assertCreated();

        $this->getJson("/api/cases/{$case->id}/transparency", $this->profileHeaders($profileId))
            ->assertOk()
            ->assertJsonPath('principle', 'If it cannot be verified, do not present it as fact.')
            ->assertJsonPath('counts.verifiedFacts', 1)
            ->assertJsonPath('counts.unverifiedClaims', 1)
            ->assertJsonPath('counts.assumptions', 1)
            ->assertJsonPath('counts.unknowns', 1)
            ->assertJsonPath('sections.verifiedFacts.0.label', '2024 return is not reflected as received')
            ->assertJsonPath('sections.unverifiedClaims.0.label', 'Representative states amended return package was mailed')
            ->assertJsonPath('sections.assumptions.0.label', 'IRS review is assumed to be pending')
            ->assertJsonPath('sections.unknowns.0.label', 'Internal IRS queue assignment');

        $this->assertDatabaseHas('audit_case_events', [
            'case_id' => $case->id,
            'company_id' => $company->id,
            'business_profile_id' => $profileId,
            'event_type' => 'taxpayer_transparency_item_created',
        ]);
    }

    public function test_verified_fact_requires_authoritative_source(): void
    {
        [$company, $user, $profileId] = $this->createWorkspace();
        $case = $this->createCase($company->id, $user->id, $profileId);

        Sanctum::actingAs($user);

        $this->postJson("/api/cases/{$case->id}/transparency-items", [
            'category' => 'verified_fact',
            'label' => 'Representative says the return was filed',
            'source_type' => 'representative_statement',
        ], $this->profileHeaders($profileId))
            ->assertStatus(422)
            ->assertJsonPath('error', 'Verified facts require an authoritative source type.');

        $this->assertDatabaseCount('taxpayer_transparency_items', 0);
    }

    public function test_case_transparency_is_business_profile_scoped(): void
    {
        [$company, $user, $profileA] = $this->createWorkspace();
        $profileB = $this->createProfile($company->id, 'Side Business');
        $case = $this->createCase($company->id, $user->id, $profileA);

        Sanctum::actingAs($user);

        $this->getJson("/api/cases/{$case->id}/transparency", $this->profileHeaders($profileB))
            ->assertNotFound()
            ->assertJsonPath('error', 'Case not found');

        $this->postJson("/api/cases/{$case->id}/transparency-items", [
            'category' => 'unknown',
            'label' => 'Current processing queue',
        ], $this->profileHeaders($profileB))
            ->assertNotFound()
            ->assertJsonPath('error', 'Case not found');
    }

    /** @return array{0: Company, 1: User, 2: string} */
    private function createWorkspace(): array
    {
        $company = new Company(['name' => 'Transparency Test Co']);
        $company->id = (string) Str::uuid();
        $company->save();

        $user = new User([
            'company_id' => $company->id,
            'email' => Str::uuid().'@example.test',
            'password_hash' => Hash::make('password'),
            'first_name' => 'Case',
            'last_name' => 'Reviewer',
            'role' => 'owner',
        ]);
        $user->id = (string) Str::uuid();
        $user->save();

        $profileId = $this->createProfile($company->id, 'Main Business', true);

        DB::table('workspace_memberships')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'scope' => 'workspace',
            'granted_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$company, $user, $profileId];
    }

    private function createProfile(string $companyId, string $name, bool $isDefault = false): string
    {
        $profileId = (string) Str::uuid();

        DB::table('business_profiles')->insert([
            'id' => $profileId,
            'company_id' => $companyId,
            'name' => $name,
            'is_default' => $isDefault,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $profileId;
    }

    private function createCase(string $companyId, string $userId, string $profileId): AuditCase
    {
        return AuditCase::create([
            'company_id' => $companyId,
            'business_profile_id' => $profileId,
            'title' => 'IRS status review',
            'description' => 'Review taxpayer case visibility.',
            'status' => 'open',
            'severity' => 'warning',
            'created_by' => $userId,
        ]);
    }

    /** @return array<string, string> */
    private function profileHeaders(string $profileId): array
    {
        return ['X-Brevix-Business-Profile-Id' => $profileId];
    }

    private function createSchema(): void
    {
        foreach ([
            'taxpayer_transparency_items',
            'audit_case_events',
            'audit_cases',
            'workspace_memberships',
            'business_profiles',
            'users',
            'companies',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
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

        Schema::create('audit_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->text('title');
            $table->text('description')->nullable();
            $table->text('status')->default('open');
            $table->text('severity')->default('warning');
            $table->uuid('assigned_to')->nullable();
            $table->uuid('created_by');
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_case_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('case_id');
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->text('event_type');
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('taxpayer_transparency_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->uuid('audit_case_id');
            $table->uuid('created_by')->nullable();
            $table->text('category');
            $table->text('status_key')->nullable();
            $table->text('tax_period')->nullable();
            $table->text('label');
            $table->text('detail')->nullable();
            $table->text('source_type')->nullable();
            $table->text('source_label')->nullable();
            $table->text('source_reference')->nullable();
            $table->date('source_date')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }
}
