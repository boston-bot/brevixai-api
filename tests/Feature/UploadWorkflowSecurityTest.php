<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UploadWorkflowSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->createSchema();
        config()->set('filesystems.default', 'local');
    }

    public function test_upload_session_persists_quarantine_metadata(): void
    {
        [$company, $user] = $this->createCompanyUser('Tenant A');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/uploads', [
            'importType' => 'transaction_ledger',
            'originalFilename' => 'ledger.csv',
            'claimedContentType' => 'text/csv',
            'fileSizeBytes' => 1234,
        ]);

        $response->assertCreated();
        $uploadId = $response->json('uploadId');

        $this->assertDatabaseHas('uploads', [
            'id' => $uploadId,
            'company_id' => $company->id,
            'import_type' => 'transaction_ledger',
            'quarantine_key' => "quarantine/{$company->id}/{$uploadId}_ledger.csv",
            'storage_filename' => "{$uploadId}.csv",
            'status_detail' => 'Upload session created.',
        ]);
    }

    public function test_upload_session_rejects_unsupported_import_type(): void
    {
        [, $user] = $this->createCompanyUser('Tenant A');
        Sanctum::actingAs($user);

        $this->postJson('/api/uploads', [
            'importType' => 'bank_statement',
            'originalFilename' => 'ledger.csv',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['importType']);
    }

    public function test_upload_workflow_endpoints_do_not_cross_company_boundary(): void
    {
        [, $user] = $this->createCompanyUser('Tenant A');
        [$otherCompany, $otherUser] = $this->createCompanyUser('Tenant B');
        $otherUpload = $this->createUpload($otherCompany->id, $otherUser->id, 'validated');
        $this->createInspection($otherCompany->id, $otherUpload->id);

        Sanctum::actingAs($user);

        $this->getJson("/api/uploads/{$otherUpload->id}/preview")
            ->assertNotFound();

        $this->postJson("/api/uploads/{$otherUpload->id}/mappings", [
            'sourceSheetName' => 'Ledger',
            'fieldMappings' => ['date' => 'Date'],
        ])->assertNotFound();

        $this->postJson("/api/uploads/{$otherUpload->id}/validate")
            ->assertNotFound();

        $this->postJson("/api/uploads/{$otherUpload->id}/promote")
            ->assertNotFound();
    }

    private function createSchema(): void
    {
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
            $table->uuid('latest_mapping_version_id')->nullable();
            $table->uuid('latest_validation_run_id')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('scanned_at')->nullable();
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
            $table->id();
            $table->uuid('upload_id');
            $table->uuid('company_id');
            $table->integer('version_number');
            $table->string('import_type');
            $table->string('source_sheet_name')->nullable();
            $table->json('field_mappings')->nullable();
            $table->uuid('confirmed_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    /** @return array{0: Company, 1: User} */
    private function createCompanyUser(string $companyName): array
    {
        $company = new Company(['name' => $companyName]);
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

    private function createUpload(string $companyId, string $userId, string $status = 'created'): Upload
    {
        $upload = new Upload([
            'company_id' => $companyId,
            'uploaded_by' => $userId,
            'filename' => 'ledger.csv',
            'original_filename' => 'ledger.csv',
            'import_type' => 'transaction_ledger',
            'status' => $status,
            'status_detail' => 'Ready',
            'latest_mapping_version_id' => (string) Str::uuid(),
        ]);
        $upload->id = (string) Str::uuid();
        $upload->save();

        return $upload;
    }

    private function createInspection(string $companyId, string $uploadId): void
    {
        DB::table('upload_inspections')->insert([
            'id' => (string) Str::uuid(),
            'upload_id' => $uploadId,
            'company_id' => $companyId,
            'workbook_metadata' => json_encode(['detectedContentType' => 'text/csv']),
            'sheet_inventory' => json_encode([]),
            'parser_warnings' => json_encode([]),
            'sample_preview' => json_encode([]),
            'created_at' => now(),
        ]);
    }
}
