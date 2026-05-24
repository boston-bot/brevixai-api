<?php

namespace Tests\Feature;

use App\Jobs\PromoteUploadJob;
use App\Jobs\ScanUploadJob;
use App\Jobs\ValidateUploadJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class UploadPipelineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.default', 'local');
        Storage::fake('local');
        $this->createSchema();
    }

    public function test_csv_upload_jobs_promote_rows_to_transactions(): void
    {
        $companyId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $uploadId = (string) Str::uuid();
        $mappingId = (string) Str::uuid();
        $quarantineKey = "quarantine/{$companyId}/{$uploadId}_ledger.csv";

        DB::table('companies')->insert([
            'id' => $companyId,
            'name' => 'Brevix Test Co',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('users')->insert([
            'id' => $userId,
            'company_id' => $companyId,
            'email' => 'owner@example.test',
            'password_hash' => 'hash',
            'role' => 'owner',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('uploads')->insert([
            'id' => $uploadId,
            'company_id' => $companyId,
            'uploaded_by' => $userId,
            'filename' => 'ledger.csv',
            'original_filename' => 'ledger.csv',
            'file_extension' => 'csv',
            'import_type' => 'transaction_ledger',
            'quarantine_bucket' => 'local',
            'quarantine_key' => $quarantineKey,
            'latest_mapping_version_id' => $mappingId,
            'status' => 'uploaded_to_quarantine',
            'status_detail' => 'Ready for scan',
            'created_at' => now(),
        ]);
        DB::table('upload_mapping_versions')->insert([
            'id' => $mappingId,
            'upload_id' => $uploadId,
            'company_id' => $companyId,
            'version_number' => 1,
            'import_type' => 'transaction_ledger',
            'source_sheet_name' => 'Sheet1',
            'field_mappings' => json_encode([
                'date' => 'Date',
                'vendor_customer' => 'Vendor',
                'amount' => 'Amount',
                'type' => 'Type',
                'memo' => 'Memo',
            ]),
            'confirmed_by' => $userId,
            'created_at' => now(),
        ]);

        Storage::disk('local')->put($quarantineKey, implode("\n", [
            'Date,Vendor,Amount,Type,Memo',
            '2026-05-01,Acme Supplies,"$1,250.75",expense,Office supplies',
            '2026-05-02,Northwind Services,-42.50,expense,Adjustment',
        ]));

        (new ScanUploadJob($uploadId, $companyId, $userId))->handle();
        (new ValidateUploadJob($uploadId, $companyId, $userId))->handle();
        (new PromoteUploadJob($uploadId, $companyId, $userId))->handle();

        $this->assertDatabaseHas('uploads', [
            'id' => $uploadId,
            'company_id' => $companyId,
            'status' => 'promoted',
            'row_count' => 2,
            'scan_status' => 'clean',
        ]);
        $this->assertDatabaseHas('upload_validation_runs', [
            'upload_id' => $uploadId,
            'company_id' => $companyId,
            'status' => 'validated',
            'total_row_count' => 2,
            'valid_row_count' => 2,
            'blocking_error_count' => 0,
        ]);
        $this->assertDatabaseHas('import_batches', [
            'upload_id' => $uploadId,
            'company_id' => $companyId,
            'imported_row_count' => 2,
            'trusted_target_domain' => 'transaction_ledger',
        ]);
        $this->assertDatabaseHas('transactions', [
            'upload_id' => $uploadId,
            'company_id' => $companyId,
            'date' => '2026-05-01',
            'vendor_customer' => 'Acme Supplies',
            'amount' => 1250.75,
            'type' => 'expense',
            'memo' => 'Office supplies',
            'source_row_number' => 2,
            'validation_status' => 'promoted',
        ]);
        $this->assertDatabaseHas('transactions', [
            'upload_id' => $uploadId,
            'company_id' => $companyId,
            'date' => '2026-05-02',
            'vendor_customer' => 'Northwind Services',
            'amount' => -42.50,
            'source_row_number' => 3,
        ]);
        $this->assertDatabaseCount('upload_row_errors', 0);

        $inspection = DB::table('upload_inspections')
            ->where('upload_id', $uploadId)
            ->where('company_id', $companyId)
            ->first();
        $this->assertNotNull($inspection);
        $sheetInventory = json_decode($inspection->sheet_inventory, true);
        $this->assertSame(['Date', 'Vendor', 'Amount', 'Type', 'Memo'], $sheetInventory[0]['headers']);
        $this->assertSame(2, $sheetInventory[0]['rowCount']);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('upload_row_errors');
        Schema::dropIfExists('upload_validation_runs');
        Schema::dropIfExists('upload_mapping_versions');
        Schema::dropIfExists('upload_inspections');
        Schema::dropIfExists('uploads');
        Schema::dropIfExists('users');
        Schema::dropIfExists('companies');

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
            $table->string('role')->default('owner');
            $table->timestamps();
        });

        Schema::create('uploads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
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
            $table->text('failure_code')->nullable();
            $table->text('scan_status')->nullable();
            $table->uuid('latest_mapping_version_id')->nullable();
            $table->uuid('latest_validation_run_id')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('upload_inspections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('upload_id');
            $table->uuid('company_id');
            $table->json('workbook_metadata')->nullable();
            $table->json('sheet_inventory')->nullable();
            $table->json('parser_warnings')->nullable();
            $table->json('sample_preview')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('upload_mapping_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('upload_id');
            $table->uuid('company_id');
            $table->integer('version_number');
            $table->string('import_type');
            $table->string('source_sheet_name')->nullable();
            $table->json('field_mappings')->nullable();
            $table->uuid('confirmed_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('upload_validation_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('upload_id');
            $table->uuid('company_id');
            $table->uuid('mapping_version_id')->nullable();
            $table->string('status')->default('pending');
            $table->integer('total_row_count')->default(0);
            $table->integer('valid_row_count')->default(0);
            $table->integer('invalid_row_count')->default(0);
            $table->integer('blocking_error_count')->default(0);
            $table->integer('warning_count')->default(0);
            $table->json('summary')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('upload_row_errors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('upload_id');
            $table->uuid('company_id');
            $table->uuid('validation_run_id');
            $table->string('source_sheet_name')->nullable();
            $table->integer('source_row_number')->nullable();
            $table->string('canonical_field_key')->nullable();
            $table->string('severity');
            $table->string('error_code');
            $table->text('message');
            $table->text('raw_value')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('import_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('upload_id');
            $table->uuid('company_id');
            $table->uuid('mapping_version_id')->nullable();
            $table->uuid('validation_run_id')->nullable();
            $table->string('trusted_target_domain');
            $table->integer('imported_row_count')->default(0);
            $table->uuid('promoted_by')->nullable();
            $table->timestamp('promoted_at')->nullable();
        });

        Schema::create('transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('upload_id');
            $table->uuid('company_id');
            $table->uuid('import_batch_id')->nullable();
            $table->text('txn_id')->nullable();
            $table->date('date')->nullable();
            $table->text('department')->nullable();
            $table->text('vendor_customer')->nullable();
            $table->text('type')->nullable();
            $table->text('category')->nullable();
            $table->text('payment_method')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->text('invoice_ref')->nullable();
            $table->text('memo')->nullable();
            $table->json('raw_row')->nullable();
            $table->text('source_sheet_name')->nullable();
            $table->integer('source_row_number')->nullable();
            $table->text('validation_status')->nullable();
            $table->text('row_content_hash')->nullable();
        });
    }
}
