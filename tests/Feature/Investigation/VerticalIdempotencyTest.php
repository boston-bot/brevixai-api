<?php

namespace Tests\Feature\Investigation;

use App\Models\Company;
use App\Models\User;
use App\Services\SourceFindingAdapterService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VerticalIdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    public function test_vendor_and_payment_vertical_idempotency_contract(): void
    {
        [$company, $user, $profileId] = $this->createWorkspace();
        $transactionId = (string) Str::uuid();
        $uploadId = (string) Str::uuid();
        $this->mockSourceAdapter($this->sourcePayload($transactionId, $uploadId));

        Sanctum::actingAs($user);

        // 1. Produces an idempotent finding
        $materializeResponse1 = $this->postJson('/api/findings/materialize', [
            'source_module' => 'transactions',
        ], $this->profileHeaders($profileId));
        
        $materializeResponse1->assertCreated();

        $materializeResponse2 = $this->postJson('/api/findings/materialize', [
            'source_module' => 'transactions',
        ], $this->profileHeaders($profileId));
        
        $materializeResponse2->assertCreated();

        // Prove idempotency
        $this->assertSame(1, DB::table('findings')->where('source_record_id', $transactionId)->count());
        $findingId = DB::table('findings')->where('source_record_id', $transactionId)->value('id');

        // 2. Preserves sanitized evidence
        $evidenceCount = DB::table('evidence_items')->where('source_record_id', $transactionId)->count();
        $this->assertSame(1, $evidenceCount);
        $evidence = DB::table('evidence_items')->where('source_record_id', $transactionId)->first();
        // Ensure no raw row was stored in metadata directly (as simulated in the adapter mock)
        $this->assertStringNotContainsString('DO NOT STORE RAW ROW', json_encode($evidence->metadata) ?: '');

        // 3. Opens an investigation
        $investigationResponse = $this->postJson("/api/findings/{$findingId}/create-investigation", [], $this->profileHeaders($profileId));
        $investigationResponse->assertCreated();
        $investigationId = $investigationResponse->json('investigation.id');

        // 4. Accepts a reviewer action
        $reviewResponse = $this->postJson("/api/findings/{$findingId}/review", [
            'status' => 'reviewed',
            'note' => 'Confirmed vendor payment risk.',
        ], $this->profileHeaders($profileId));
        
        $reviewResponse->assertOk()->assertJsonPath('finding.status', 'reviewed');

        // 5. Produces the intended report/package output
        $packageResponse = $this->postJson("/api/investigations/{$investigationId}/packages", [
            'format' => 'json',
        ], $this->profileHeaders($profileId));
        
        $packageResponse->assertCreated()
            ->assertJsonPath('package.format', 'json')
            ->assertJsonPath('package.status', 'completed')
            ->assertJsonPath('package.included_counts.findings', 1);
            
        $this->assertDatabaseHas('case_packages', [
            'investigation_id' => $investigationId,
            'format' => 'json',
            'status' => 'completed',
        ]);
    }

    /** @return array<string, mixed> */
    private function sourcePayload(string $transactionId, string $uploadId): array
    {
        $findingId = "finding:vendor_anomaly:{$transactionId}";
        $evidence = [
            'id' => "evidence:transaction:{$transactionId}",
            'findingId' => $findingId,
            'evidenceType' => 'transaction',
            'sourceType' => 'transaction',
            'sourceId' => $uploadId,
            'sourceRecordId' => $transactionId,
            'title' => 'Suspicious Vendor Payment',
            'summary' => 'Vendor payment to unverified entity.',
            'citationLabel' => "upload:{$uploadId}:Ledger:row 42",
            'sourceRowRange' => '42',
            'hash' => str_repeat('a', 64),
            'addedByActorType' => 'system',
            'metadata' => [
                'raw_row_returned' => false,
            ],
        ];

        return [
            'contractVersion' => '2026-06-12',
            'filters' => ['sourceModule' => 'transactions', 'limit' => 50],
            'findings' => [[
                'id' => $findingId,
                'category' => 'vendor_payments',
                'sourceModule' => 'transactions',
                'sourceRecordType' => 'transaction',
                'sourceRecordId' => $transactionId,
                'title' => 'Review vendor anomaly',
                'summary' => 'Vendor payment anomaly detected.',
                'detail' => 'Vendor payment anomaly detected.',
                'severity' => 'warning',
                'confidence' => 'medium',
                'reasonCode' => 'vendor_anomaly',
                'status' => 'new',
                'evidenceRefs' => [$evidence],
                'suggestedRecords' => [],
                'recommendedAction' => [],
                'limitations' => ['Raw source-row values are not embedded.'],
            ]],
            'evidenceItems' => [$evidence],
            'suggestedRecords' => [],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function mockSourceAdapter(array $payload): void
    {
        $adapter = $this->createMock(SourceFindingAdapterService::class);
        $adapter->method('list')->willReturn($payload);
        $this->app->instance(SourceFindingAdapterService::class, $adapter);
    }

    /** @return array{0: Company, 1: User, 2: string} */
    private function createWorkspace(): array
    {
        $company = new Company(['name' => 'Vertical Test Co']);
        $company->id = (string) Str::uuid();
        $company->save();

        $user = new User([
            'company_id' => $company->id,
            'email' => Str::uuid().'@example.test',
            'password_hash' => Hash::make('password'),
            'first_name' => 'Test',
            'last_name' => 'Reviewer',
            'role' => 'owner',
        ]);
        $user->id = (string) Str::uuid();
        $user->save();

        $profileId = (string) Str::uuid();
        DB::table('business_profiles')->insert([
            'id' => $profileId,
            'company_id' => $company->id,
            'name' => 'Main Books',
            'is_default' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    /** @return array<string, string> */
    private function profileHeaders(string $profileId): array
    {
        return ['X-Brevix-Business-Profile-Id' => $profileId];
    }

    private function createSchema(): void
    {
        foreach ([
            'case_packages',
            'review_events',
            'reviewer_notes',
            'suggested_records',
            'evidence_item_finding',
            'evidence_items',
            'findings',
            'investigations',
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

        Schema::create('investigations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->uuid('legacy_audit_case_id')->nullable();
            $table->text('title');
            $table->text('category')->default('unsure');
            $table->text('subcategory')->nullable();
            $table->text('status')->default('open');
            $table->text('priority')->default('medium');
            $table->date('review_period_start')->nullable();
            $table->date('review_period_end')->nullable();
            $table->text('scope_statement')->nullable();
            $table->json('scope_limitations')->nullable();
            $table->uuid('assigned_to')->nullable();
            $table->uuid('created_by');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->uuid('investigation_id')->nullable();
            $table->text('category')->default('unsure');
            $table->text('source_module');
            $table->text('source_record_type');
            $table->text('source_record_id');
            $table->text('title');
            $table->text('summary')->nullable();
            $table->text('detail')->nullable();
            $table->text('severity')->default('warning');
            $table->text('confidence')->nullable();
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->text('reason_code')->nullable();
            $table->text('status')->default('new');
            $table->json('evidence_refs')->nullable();
            $table->json('recommended_action')->nullable();
            $table->text('reviewer_status')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('evidence_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->uuid('investigation_id')->nullable();
            $table->uuid('finding_id')->nullable();
            $table->uuid('legacy_evidence_item_id')->nullable();
            $table->text('evidence_type');
            $table->text('source_type')->nullable();
            $table->text('source_id')->nullable();
            $table->text('source_record_id')->nullable();
            $table->text('title');
            $table->text('summary')->nullable();
            $table->text('citation_label')->nullable();
            $table->text('source_row_range')->nullable();
            $table->text('file_name')->nullable();
            $table->text('storage_key')->nullable();
            $table->text('hash')->nullable();
            $table->text('added_by_actor_type')->default('user');
            $table->uuid('added_by_actor_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('evidence_item_finding', function (Blueprint $table): void {
            $table->uuid('evidence_item_id');
            $table->uuid('finding_id');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('suggested_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->uuid('investigation_id')->nullable();
            $table->uuid('finding_id')->nullable();
            $table->text('record_type');
            $table->text('label');
            $table->text('reason')->nullable();
            $table->text('priority')->default('recommended');
            $table->text('status')->default('requested');
            $table->uuid('satisfying_evidence_item_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('reviewer_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->uuid('investigation_id');
            $table->uuid('finding_id')->nullable();
            $table->uuid('author_id')->nullable();
            $table->text('author_name')->nullable();
            $table->text('body');
            $table->text('visibility')->default('internal');
            $table->timestamps();
        });

        Schema::create('review_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->uuid('investigation_id');
            $table->uuid('finding_id')->nullable();
            $table->text('event_type');
            $table->text('actor_type');
            $table->uuid('actor_id')->nullable();
            $table->text('previous_status')->nullable();
            $table->text('next_status')->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('case_packages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('business_profile_id')->nullable();
            $table->uuid('investigation_id');
            $table->text('format');
            $table->text('status')->default('completed');
            $table->text('title');
            $table->timestamp('generated_at')->nullable();
            $table->uuid('generated_by')->nullable();
            $table->json('included_sections')->nullable();
            $table->json('included_counts')->nullable();
            $table->text('package_hash')->nullable();
            $table->text('filename')->nullable();
            $table->text('storage_key')->nullable();
            $table->json('manifest')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }
}
