<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\Agents\ReconciliationRiskScoringService;
use App\Services\Agents\VendorRiskScoringService;
use App\Services\IrsTaxNoticeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SourceFindingAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->createSchema();
    }

    public function test_transaction_anomalies_are_exposed_as_normalized_findings_with_source_row_evidence(): void
    {
        [$company, $user] = $this->createWorkspace();
        $uploadId = $this->createUpload($company->id, $user->id);
        $transactionId = (string) Str::uuid();

        DB::table('transactions')->insert([
            'id' => $transactionId,
            'upload_id' => $uploadId,
            'company_id' => $company->id,
            'txn_id' => 'TXN-100',
            'date' => '2026-05-12',
            'vendor_customer' => 'Northstar Consulting',
            'type' => 'payment',
            'category' => 'vendor payment',
            'payment_method' => 'ach',
            'amount' => 4900.00,
            'memo' => 'approval threshold review',
            'anomaly_flag' => true,
            'anomaly_reason' => 'Vendor payment was split near approval threshold.',
            'raw_row' => json_encode(['private_note' => 'DO NOT RETURN THIS RAW ROW']),
            'import_batch_id' => (string) Str::uuid(),
            'source_sheet_name' => 'Ledger',
            'source_row_number' => 42,
            'validation_status' => 'valid',
            'parse_warnings' => json_encode([]),
            'row_content_hash' => str_repeat('a', 64),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/findings?source_module=transactions');

        $response->assertOk()
            ->assertJsonPath('contractVersion', '2026-06-12')
            ->assertJsonPath('findings.0.sourceModule', 'transactions')
            ->assertJsonPath('findings.0.sourceRecordType', 'transaction')
            ->assertJsonPath('findings.0.sourceRecordId', $transactionId)
            ->assertJsonPath('findings.0.category', 'vendor_payments')
            ->assertJsonPath('findings.0.evidenceRefs.0.evidenceType', 'transaction')
            ->assertJsonPath('evidenceItems.0.sourceRecordId', $transactionId)
            ->assertJsonPath('evidenceItems.0.citationLabel', "upload:{$uploadId}:Ledger:row 42")
            ->assertJsonPath('evidenceItems.0.sourceRowRange', '42')
            ->assertJsonPath('evidenceItems.0.metadata.raw_row_returned', false)
            ->assertJsonPath('suggestedRecords.0.recordType', 'transaction_support');

        $this->assertStringNotContainsString('DO NOT RETURN THIS RAW ROW', json_encode($response->json()) ?: '');
    }

    public function test_upload_validation_errors_are_exposed_without_raw_values(): void
    {
        [$company, $user] = $this->createWorkspace();
        $uploadId = $this->createUpload($company->id, $user->id);

        DB::table('upload_row_errors')->insert([
            'id' => '11111111-1111-4111-8111-111111111111',
            'upload_id' => $uploadId,
            'company_id' => $company->id,
            'validation_run_id' => (string) Str::uuid(),
            'source_sheet_name' => 'AP',
            'source_row_number' => 9,
            'canonical_field_key' => 'amount',
            'severity' => 'blocking',
            'error_code' => 'missing_amount',
            'message' => 'Amount is required.',
            'raw_value' => 'SECRET RAW VALUE',
            'created_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/findings?source_module=upload_validation');

        $response->assertOk()
            ->assertJsonPath('findings.0.sourceModule', 'upload_validation')
            ->assertJsonPath('findings.0.reasonCode', 'missing_amount')
            ->assertJsonPath('findings.0.severity', 'critical')
            ->assertJsonPath('evidenceItems.0.evidenceType', 'source_row')
            ->assertJsonPath('evidenceItems.0.citationLabel', "upload:{$uploadId}:AP:row 9")
            ->assertJsonPath('evidenceItems.0.metadata.raw_value_returned', false)
            ->assertJsonPath('suggestedRecords.0.priority', 'required');

        $this->assertStringNotContainsString('SECRET RAW VALUE', json_encode($response->json()) ?: '');
    }

    public function test_reconciliation_analyzer_output_maps_to_findings_and_evidence_references(): void
    {
        [$company, $user] = $this->createWorkspace();
        $uploadId = $this->createUpload($company->id, $user->id);
        $transactionId = (string) Str::uuid();
        $discrepancyId = (string) Str::uuid();

        DB::table('transactions')->insert([
            'id' => $transactionId,
            'upload_id' => $uploadId,
            'company_id' => $company->id,
            'date' => '2026-05-10',
            'vendor_customer' => 'Customer Deposit',
            'type' => 'deposit',
            'category' => 'revenue',
            'amount' => 1250.00,
            'anomaly_flag' => false,
            'source_sheet_name' => 'Bank',
            'source_row_number' => 18,
            'row_content_hash' => str_repeat('b', 64),
        ]);

        $this->app->instance(
            ReconciliationRiskScoringService::class,
            new class($discrepancyId, $transactionId) extends ReconciliationRiskScoringService {
                public function __construct(
                    private readonly string $discrepancyId,
                    private readonly string $transactionId,
                ) {}

                public function scoreReconciliation(string $companyId, ?string $businessProfileId = null): array
                {
                    return [
                        'company_id' => $companyId,
                        'business_profile_id' => $businessProfileId,
                        'reconciliation_risk_score' => 45,
                        'risk_level' => 'medium',
                        'triggered_rules' => [[
                            'rule_key' => 'bank_ledger_mismatch',
                            'name' => 'Bank-to-Ledger Mismatches',
                            'weight' => 15,
                            'explanation' => 'Bank activity is not matched to ledger records.',
                        ]],
                        'supporting_evidence' => [
                            'bank_ledger_mismatch' => [
                                'discrepancies' => [[
                                    'id' => $this->discrepancyId,
                                    'run_id' => 'run-1',
                                    'amount' => 1250.00,
                                    'category' => 'missing_from_books',
                                    'reason_code' => 'bank_transaction_without_ledger_match',
                                    'risk_level' => 'medium',
                                    'status' => 'new',
                                    'bank_txn_id' => $this->transactionId,
                                    'ledger_txn_id' => null,
                                    'created_at' => '2026-05-13T00:00:00+00:00',
                                ]],
                            ],
                        ],
                        'recommended_next_action' => 'Review unmatched bank activity before package export.',
                    ];
                }
            },
        );

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/findings?source_module=reconciliation');

        $response->assertOk()
            ->assertJsonPath('findings.0.sourceModule', 'reconciliation')
            ->assertJsonPath('findings.0.reasonCode', 'bank_ledger_mismatch')
            ->assertJsonPath('findings.0.confidence', 'high')
            ->assertJsonPath('evidenceItems.0.evidenceType', 'reconciliation_discrepancy')
            ->assertJsonPath('evidenceItems.0.sourceRecordId', $discrepancyId)
            ->assertJsonPath('evidenceItems.1.evidenceType', 'transaction')
            ->assertJsonPath('evidenceItems.1.sourceRecordId', $transactionId)
            ->assertJsonPath('suggestedRecords.0.recordType', 'reconciliation_support');
    }

    public function test_vendor_risk_analyzer_output_maps_transaction_support_to_findings(): void
    {
        [$company, $user] = $this->createWorkspace();
        $uploadId = $this->createUpload($company->id, $user->id);
        $transactionId = (string) Str::uuid();

        DB::table('transactions')->insert([
            'id' => $transactionId,
            'upload_id' => $uploadId,
            'company_id' => $company->id,
            'date' => '2026-05-16',
            'vendor_customer' => 'Northstar Consulting',
            'type' => 'payment',
            'category' => 'contractor',
            'amount' => 4700.00,
            'anomaly_flag' => false,
            'source_sheet_name' => 'Ledger',
            'source_row_number' => 55,
            'row_content_hash' => str_repeat('c', 64),
        ]);

        $this->app->instance(
            VendorRiskScoringService::class,
            new class($transactionId) extends VendorRiskScoringService {
                public function __construct(private readonly string $transactionId) {}

                public function scoreAllVendors(string $companyId, ?string $businessProfileId = null): array
                {
                    return [[
                        'vendor_name' => 'Northstar Consulting',
                        'business_profile_id' => $businessProfileId,
                        'vendor_risk_score' => 75,
                        'risk_level' => 'high',
                        'triggered_rules' => [[
                            'rule_key' => 'threshold_splitting',
                            'name' => 'Threshold Splitting Behavior',
                            'weight' => 20,
                            'explanation' => 'Multiple payments were just under the approval threshold.',
                        ]],
                        'supporting_evidence' => [
                            'threshold_splitting' => [
                                'split_transaction_groups' => [[
                                    ['id' => $this->transactionId, 'amount' => 4700.00, 'date' => '2026-05-16'],
                                ]],
                            ],
                        ],
                        'recommended_next_action' => 'Validate vendor approvals and payment support.',
                    ]];
                }
            },
        );

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/findings?source_module=vendor_risk');

        $response->assertOk()
            ->assertJsonPath('findings.0.sourceModule', 'vendor_risk')
            ->assertJsonPath('findings.0.category', 'vendor_payments')
            ->assertJsonPath('findings.0.reasonCode', 'threshold_splitting')
            ->assertJsonPath('findings.0.severity', 'critical')
            ->assertJsonPath('evidenceItems.0.sourceType', 'vendor_risk_transaction')
            ->assertJsonPath('evidenceItems.0.sourceRecordId', $transactionId)
            ->assertJsonPath('suggestedRecords.0.recordType', 'vendor_support');
    }

    public function test_tax_notice_interpretation_returns_normalized_finding_without_notice_text(): void
    {
        [$company, $user] = $this->createWorkspace();

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

        $noticeText = 'IRS CP2000 notice for the taxpayer. Response is due soon. Private marker NOTICE-RAW-SECRET.';
        $response = $this->postJson('/api/tax-notices/interpret', [
            'notice_text' => $noticeText,
        ]);

        $response->assertOk()
            ->assertJsonPath('normalizedFinding.sourceModule', 'tax_notices')
            ->assertJsonPath('normalizedFinding.category', 'tax')
            ->assertJsonPath('normalizedFinding.reasonCode', 'CP2000')
            ->assertJsonPath('normalizedFinding.recommendedAction.requiresConfirmation', true)
            ->assertJsonPath('normalizedEvidenceItems.0.evidenceType', 'tax_notice')
            ->assertJsonPath('normalizedEvidenceItems.0.metadata.raw_notice_text_returned', false)
            ->assertJsonPath('normalizedSuggestedRecords.0.recordType', 'notice_supporting_records');

        $this->assertNotEmpty($response->json('normalizedEvidenceItems.0.hash'));
        $this->assertStringNotContainsString('NOTICE-RAW-SECRET', json_encode($response->json()) ?: '');
    }

    private function createSchema(): void
    {
        foreach ([
            'upload_row_errors',
            'alerts',
            'reconciliation_discrepancies',
            'reconciliation_results',
            'transactions',
            'uploads',
            'users',
            'companies',
        ] as $table) {
            Schema::dropIfExists($table);
        }

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
            $table->uuid('company_id')->nullable();
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
            $table->uuid('company_id');
            $table->uuid('uploaded_by');
            $table->text('filename');
            $table->integer('file_size')->nullable();
            $table->string('status')->default('processing');
            $table->json('sheets_parsed')->nullable();
            $table->integer('row_count')->default(0);
            $table->string('import_type')->nullable();
            $table->text('original_filename')->nullable();
            $table->text('storage_filename')->nullable();
            $table->text('claimed_content_type')->nullable();
            $table->text('file_extension')->nullable();
            $table->bigInteger('file_size_bytes')->nullable();
            $table->text('sha256')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('upload_id');
            $table->uuid('company_id');
            $table->string('txn_id')->nullable();
            $table->date('date')->nullable();
            $table->string('department')->nullable();
            $table->string('vendor_customer')->nullable();
            $table->string('type')->nullable();
            $table->string('category')->nullable();
            $table->string('payment_method')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('invoice_ref')->nullable();
            $table->text('memo')->nullable();
            $table->boolean('anomaly_flag')->default(false);
            $table->text('anomaly_reason')->nullable();
            $table->json('raw_row')->nullable();
            $table->uuid('import_batch_id')->nullable();
            $table->string('source_sheet_name')->nullable();
            $table->integer('source_row_number')->nullable();
            $table->string('validation_status')->nullable();
            $table->json('parse_warnings')->nullable();
            $table->text('row_content_hash')->nullable();
        });

        Schema::create('reconciliation_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->timestamp('run_at')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->integer('total_mismatches')->default(0);
            $table->decimal('total_impact', 15, 2)->default(0);
            $table->string('status')->default('completed');
            $table->json('results');
        });

        Schema::create('reconciliation_discrepancies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('run_id');
            $table->uuid('bank_txn_id')->nullable();
            $table->uuid('ledger_txn_id')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('category');
            $table->string('reason_code');
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->string('risk_level');
            $table->string('recommended_action');
            $table->text('recommendation_explanation');
            $table->string('status')->default('new');
            $table->text('resolution_notes')->nullable();
            $table->json('metadata');
            $table->timestamps();
        });

        Schema::create('alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('group_id')->nullable();
            $table->string('rule_key');
            $table->string('severity');
            $table->string('title');
            $table->text('detail')->nullable();
            $table->json('evidence')->nullable();
            $table->string('status')->default('open');
            $table->integer('priority_score')->default(50);
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('upload_row_errors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('upload_id');
            $table->uuid('company_id');
            $table->uuid('validation_run_id')->nullable();
            $table->string('source_sheet_name')->nullable();
            $table->integer('source_row_number')->nullable();
            $table->string('canonical_field_key')->nullable();
            $table->string('severity');
            $table->string('error_code');
            $table->text('message');
            $table->text('raw_value')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    /** @return array{0: Company, 1: User} */
    private function createWorkspace(): array
    {
        $company = new Company(['name' => 'Source Finding Test Co']);
        $company->id = (string) Str::uuid();
        $company->save();

        $user = new User([
            'company_id' => $company->id,
            'email' => Str::uuid().'@example.test',
            'password_hash' => Hash::make('password'),
            'first_name' => 'Source',
            'last_name' => 'Reviewer',
            'role' => 'owner',
        ]);
        $user->id = (string) Str::uuid();
        $user->save();

        return [$company, $user];
    }

    private function createUpload(string $companyId, string $userId): string
    {
        $uploadId = (string) Str::uuid();

        DB::table('uploads')->insert([
            'id' => $uploadId,
            'company_id' => $companyId,
            'uploaded_by' => $userId,
            'filename' => 'ledger.csv',
            'original_filename' => 'ledger.csv',
            'status' => 'promoted',
            'sheets_parsed' => json_encode(['Ledger']),
            'row_count' => 100,
            'import_type' => 'transaction_ledger',
            'created_at' => now(),
            'uploaded_at' => now(),
        ]);

        return $uploadId;
    }
}
