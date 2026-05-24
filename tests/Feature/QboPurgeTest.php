<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\QboService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class QboPurgeTest extends TestCase
{
    private QboService $qboService;

    private string $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        $this->companyId = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $this->companyId, 'name' => 'Acme Corp', 'created_at' => now(), 'updated_at' => now()]);
        $this->qboService = app(QboService::class);
    }

    public function test_purge_deletes_alerts_when_no_data_remains(): void
    {
        $this->seedQboTransaction($this->companyId, 'realm-1');
        $this->seedAlert($this->companyId);
        $this->seedAlertRecommendation($this->companyId);

        $this->assertDatabaseCount('alerts', 1);
        $this->assertDatabaseCount('alert_recommendations', 1);

        $this->qboService->purge($this->companyId, 'realm-1');

        $this->assertDatabaseCount('qbo_transactions', 0);
        $this->assertDatabaseCount('alerts', 0);
        $this->assertDatabaseCount('alert_recommendations', 0);
    }

    public function test_purge_preserves_alerts_when_other_qbo_realm_has_data(): void
    {
        $this->seedQboTransaction($this->companyId, 'realm-1');
        $this->seedQboTransaction($this->companyId, 'realm-2');
        $this->seedAlert($this->companyId);

        $this->qboService->purge($this->companyId, 'realm-1');

        $this->assertDatabaseCount('qbo_transactions', 1);
        $this->assertDatabaseHas('qbo_transactions', ['realm_id' => 'realm-2']);
        $this->assertDatabaseCount('alerts', 1);
    }

    public function test_purge_preserves_alerts_when_file_upload_data_exists(): void
    {
        $this->seedQboTransaction($this->companyId, 'realm-1');
        $this->seedUploadTransaction($this->companyId);
        $this->seedAlert($this->companyId);

        $this->qboService->purge($this->companyId, 'realm-1');

        $this->assertDatabaseCount('qbo_transactions', 0);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('alerts', 1);
    }

    public function test_purge_only_deletes_transactions_for_specified_realm(): void
    {
        $this->seedQboTransaction($this->companyId, 'realm-1');
        $this->seedQboTransaction($this->companyId, 'realm-2');

        $this->qboService->purge($this->companyId, 'realm-1');

        $this->assertDatabaseCount('qbo_transactions', 1);
        $this->assertDatabaseHas('qbo_transactions', ['realm_id' => 'realm-2', 'company_id' => $this->companyId]);
    }

    public function test_purge_does_not_affect_other_companies_alerts(): void
    {
        $otherCompanyId = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $otherCompanyId, 'name' => 'Other Corp', 'created_at' => now(), 'updated_at' => now()]);

        $this->seedQboTransaction($this->companyId, 'realm-1');
        $this->seedAlert($this->companyId);
        $this->seedAlert($otherCompanyId);

        $this->qboService->purge($this->companyId, 'realm-1');

        $this->assertDatabaseCount('alerts', 1);
        $this->assertDatabaseHas('alerts', ['company_id' => $otherCompanyId]);
    }

    private function seedQboTransaction(string $companyId, string $realmId): void
    {
        DB::table('qbo_transactions')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'realm_id' => $realmId,
            'qbo_id' => (string) rand(1000, 9999),
            'amount' => 100.00,
            'status' => 'active',
        ]);
    }

    private function seedUploadTransaction(string $companyId): void
    {
        $uploadId = (string) Str::uuid();
        DB::table('transactions')->insert([
            'id' => (string) Str::uuid(),
            'upload_id' => $uploadId,
            'company_id' => $companyId,
            'amount' => 500.00,
        ]);
    }

    private function seedAlert(string $companyId): void
    {
        DB::table('alerts')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'rule_key' => 'vendor_risk_review',
            'severity' => 'high',
            'title' => 'Test alert',
            'status' => 'open',
            'priority_score' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedAlertRecommendation(string $companyId): void
    {
        DB::table('alert_recommendations')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'source_risk_domain' => 'vendor_risk',
            'alert_type' => 'vendor_risk_review',
            'severity' => 'high',
            'title' => 'Test recommendation',
            'summary' => 'Test',
            'evidence' => json_encode([]),
            'source_rule_ids' => json_encode(['threshold_splitting']),
            'confidence_score' => 0.85,
            'status' => 'pending_review',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSchema(): void
    {
        foreach ([
            'alert_recommendations',
            'alert_groups',
            'alerts',
            'gnucash_transactions',
            'transactions',
            'invoices',
            'qbo_transactions',
            'integrations',
            'companies',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('integrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('provider', 50);
            $table->string('realm_id', 255)->nullable();
            $table->text('access_token_enc')->nullable();
            $table->text('refresh_token_enc')->nullable();
            $table->timestampTz('token_expires_at')->nullable();
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
            $table->string('qbo_id', 255)->nullable();
            $table->string('realm_id', 255)->nullable();
            $table->date('transaction_date')->nullable();
            $table->text('vendor_name')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->text('type')->nullable();
            $table->text('status')->default('active');
            $table->json('raw_payload')->nullable();
            $table->timestamp('synced_at')->nullable();
        });

        Schema::create('transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('upload_id');
            $table->uuid('company_id');
            $table->decimal('amount', 12, 2)->nullable();
            $table->date('date')->nullable();
            $table->text('vendor_customer')->nullable();
        });

        Schema::create('gnucash_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->decimal('amount', 12, 2)->nullable();
        });

        Schema::create('alert_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->timestamps();
        });

        Schema::create('alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('group_id')->nullable();
            $table->text('rule_key');
            $table->text('severity');
            $table->text('title');
            $table->text('detail')->nullable();
            $table->json('evidence')->nullable();
            $table->text('status')->default('open');
            $table->integer('priority_score')->default(50);
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('source', 50)->nullable();
            $table->text('row_content_hash')->nullable();
            $table->timestamps();
        });

        Schema::create('alert_recommendations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->text('source_risk_domain');
            $table->text('alert_type');
            $table->text('severity');
            $table->text('title');
            $table->text('summary');
            $table->json('evidence');
            $table->json('source_rule_ids');
            $table->decimal('confidence_score', 5, 4)->default(0);
            $table->text('status')->default('pending_review');
            $table->uuid('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
        });
    }
}
