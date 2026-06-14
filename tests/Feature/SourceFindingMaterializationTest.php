<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\IrsTaxNoticeService;
use App\Services\SourceFindingAdapterService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SourceFindingMaterializationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
    }

    public function test_materialize_endpoint_persists_source_projection_idempotently(): void
    {
        [$company, $user, $profileId] = $this->createWorkspace();
        $transactionId = (string) Str::uuid();
        $uploadId = (string) Str::uuid();
        $this->mockSourceAdapter($this->sourcePayload($transactionId, $uploadId));

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/findings/materialize', [
            'source_module' => 'transactions',
        ], $this->profileHeaders($profileId));

        $response->assertCreated()
            ->assertJsonPath('materialized.findings', 1)
            ->assertJsonPath('materialized.findingsCreated', 1)
            ->assertJsonPath('materialized.evidenceItems', 1)
            ->assertJsonPath('materialized.suggestedRecords', 1)
            ->assertJsonPath('findings.0.sourceModule', 'transactions')
            ->assertJsonPath('findings.0.evidenceRefs.0.citationLabel', "upload:{$uploadId}:Ledger:row 42")
            ->assertJsonPath('findings.0.suggestedRecords.0.recordType', 'transaction_support');

        $this->assertDatabaseHas('findings', [
            'company_id' => $company->id,
            'business_profile_id' => $profileId,
            'source_module' => 'transactions',
            'source_record_type' => 'transaction',
            'source_record_id' => $transactionId,
            'status' => 'new',
        ]);
        $this->assertDatabaseHas('evidence_items', [
            'company_id' => $company->id,
            'business_profile_id' => $profileId,
            'source_type' => 'transaction',
            'source_record_id' => $transactionId,
            'citation_label' => "upload:{$uploadId}:Ledger:row 42",
        ]);
        $this->assertDatabaseHas('suggested_records', [
            'company_id' => $company->id,
            'business_profile_id' => $profileId,
            'record_type' => 'transaction_support',
            'status' => 'requested',
        ]);

        $this->postJson('/api/findings/materialize', [
            'source_module' => 'transactions',
        ], $this->profileHeaders($profileId))->assertCreated();

        $this->assertSame(1, DB::table('findings')->where('source_record_id', $transactionId)->count());
        $this->assertSame(1, DB::table('evidence_items')->where('source_record_id', $transactionId)->count());
        $this->assertSame(1, DB::table('suggested_records')->where('record_type', 'transaction_support')->count());

        $this->getJson('/api/findings?source_module=transactions', $this->profileHeaders($profileId))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('findings.0.sourceRecordId', $transactionId)
            ->assertJsonPath('findings.0.evidenceRefs.0.sourceRowRange', '42');
    }

    public function test_create_investigation_attaches_materialized_evidence_and_suggested_records(): void
    {
        [$company, $user, $profileId] = $this->createWorkspace();
        $transactionId = (string) Str::uuid();
        $uploadId = (string) Str::uuid();
        $this->mockSourceAdapter($this->sourcePayload($transactionId, $uploadId));

        Sanctum::actingAs($user);
        $this->postJson('/api/findings/materialize', [
            'source_module' => 'transactions',
        ], $this->profileHeaders($profileId))->assertCreated();

        $findingId = DB::table('findings')->where('source_record_id', $transactionId)->value('id');
        $this->assertNotNull($findingId);
        $this->assertDatabaseHas('evidence_items', ['finding_id' => $findingId, 'investigation_id' => null]);
        $this->assertDatabaseHas('suggested_records', ['finding_id' => $findingId, 'investigation_id' => null]);

        $opened = $this->postJson("/api/findings/{$findingId}/create-investigation", [], $this->profileHeaders($profileId));

        $opened->assertCreated()
            ->assertJsonPath('investigation.title', 'Review transaction anomaly')
            ->assertJsonPath('investigation.category', 'vendor_payments');

        $investigationId = $opened->json('investigation.id');
        $this->assertDatabaseHas('findings', [
            'id' => $findingId,
            'investigation_id' => $investigationId,
            'status' => 'in_review',
        ]);
        $this->assertDatabaseHas('evidence_items', [
            'finding_id' => $findingId,
            'investigation_id' => $investigationId,
        ]);
        $this->assertDatabaseHas('suggested_records', [
            'finding_id' => $findingId,
            'investigation_id' => $investigationId,
        ]);

        $this->getJson('/api/findings?source_module=transactions', $this->profileHeaders($profileId))
            ->assertOk()
            ->assertJsonPath('findings.0.evidenceRefs.0.investigationId', $investigationId);
    }

    public function test_tax_notice_interpretation_can_persist_normalized_finding_when_requested(): void
    {
        [$company, $user, $profileId] = $this->createWorkspace();
        $this->app->instance(IrsTaxNoticeService::class, new class extends IrsTaxNoticeService {
            public function __construct() {}

            public function interpretNotice(string $noticeText): array
            {
                return [
                    'notice_type' => 'CP2000',
                    'deadline_days' => 60,
                    'deadline_description' => '60 days from notice date',
                    'required_action' => 'Review the proposed changes and supporting records.',
                    'risk_level' => 'high',
                    'key_amount' => 1234.56,
                    'summary' => 'The notice proposes changes based on reported income mismatches.',
                    'disclaimer' => 'This is not tax advice.',
                ];
            }
        });

        Sanctum::actingAs($user);
        $noticeText = 'IRS CP2000 notice for the taxpayer. Response is due soon. Private marker TAX-RAW-SECRET.';

        $response = $this->postJson('/api/tax-notices/interpret', [
            'notice_text' => $noticeText,
            'persist_finding' => true,
        ], $this->profileHeaders($profileId));

        $response->assertOk()
            ->assertJsonPath('persistedFinding.sourceModule', 'tax_notices')
            ->assertJsonPath('persistedFinding.category', 'tax')
            ->assertJsonPath('materialization.findingsCreated', 1)
            ->assertJsonPath('materialization.evidenceItemsCreated', 1)
            ->assertJsonPath('materialization.suggestedRecordsCreated', 1);

        $this->assertDatabaseHas('findings', [
            'company_id' => $company->id,
            'business_profile_id' => $profileId,
            'source_module' => 'tax_notices',
            'source_record_type' => 'tax_notice_interpretation',
            'reason_code' => 'CP2000',
        ]);
        $this->assertDatabaseHas('evidence_items', [
            'company_id' => $company->id,
            'business_profile_id' => $profileId,
            'evidence_type' => 'tax_notice',
            'source_type' => 'tax_notice_text',
        ]);
        $this->assertStringNotContainsString('TAX-RAW-SECRET', json_encode($response->json()) ?: '');
    }

    /** @return array<string, mixed> */
    private function sourcePayload(string $transactionId, string $uploadId): array
    {
        $findingId = "finding:transaction_anomaly:{$transactionId}";
        $evidence = [
            'id' => "evidence:transaction:{$transactionId}",
            'findingId' => $findingId,
            'evidenceType' => 'transaction',
            'sourceType' => 'transaction',
            'sourceId' => $uploadId,
            'sourceRecordId' => $transactionId,
            'title' => 'Northstar Consulting',
            'summary' => '2026-05-12 transaction for $4,900.00: Vendor payment was split near approval threshold.',
            'citationLabel' => "upload:{$uploadId}:Ledger:row 42",
            'sourceRowRange' => '42',
            'hash' => str_repeat('a', 64),
            'addedByActorType' => 'system',
            'metadata' => [
                'raw_row_returned' => false,
                'raw_row' => 'DO NOT STORE RAW ROW',
            ],
        ];
        $suggestedRecord = [
            'id' => "suggested-record:transaction:{$transactionId}:support",
            'findingId' => $findingId,
            'recordType' => 'transaction_support',
            'label' => 'Transaction support',
            'reason' => 'Receipt, invoice, approval, or bank detail can help a reviewer resolve this transaction anomaly.',
            'priority' => 'recommended',
            'status' => 'requested',
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
                'title' => 'Review transaction anomaly',
                'summary' => 'Vendor payment was split near approval threshold.',
                'detail' => 'Vendor payment was split near approval threshold.',
                'severity' => 'warning',
                'confidence' => 'medium',
                'reasonCode' => 'transaction_anomaly',
                'status' => 'new',
                'evidenceRefs' => [$evidence],
                'suggestedRecords' => [$suggestedRecord],
                'recommendedAction' => [
                    'key' => 'review_transaction_support',
                    'label' => 'Review support',
                    'requiresConfirmation' => true,
                ],
                'limitations' => ['Raw source-row values are not embedded in normalized findings.'],
            ]],
            'evidenceItems' => [$evidence],
            'suggestedRecords' => [$suggestedRecord],
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
        $company = new Company(['name' => 'Materialization Test Co']);
        $company->id = (string) Str::uuid();
        $company->save();

        $user = new User([
            'company_id' => $company->id,
            'email' => Str::uuid().'@example.test',
            'password_hash' => Hash::make('password'),
            'first_name' => 'Finding',
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
