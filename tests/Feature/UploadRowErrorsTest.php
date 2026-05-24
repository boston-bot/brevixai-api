<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Upload;
use App\Models\UploadRowError;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UploadRowErrorsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('upload_row_errors');
        Schema::dropIfExists('upload_validation_runs');
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
            $table->text('status')->default('processing');
            $table->text('import_type')->nullable();
            $table->text('original_filename')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('upload_validation_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('upload_id');
            $table->uuid('company_id');
            $table->string('status')->default('pending');
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
    }

    public function test_errors_returns_company_scoped_upload_errors(): void
    {
        [$company, $user, $upload] = $this->createCompanyUserUpload();
        $runId = $this->createValidationRun($company->id, $upload->id);
        $this->createRowError($company->id, $upload->id, $runId, 3, 'blocking', 'MISSING_FIELD', 'Date is required');
        $this->createRowError($company->id, $upload->id, $runId, 7, 'warning', 'INVALID_FORMAT', 'Amount format unrecognized');

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/uploads/{$upload->id}/errors")
            ->assertOk()
            ->assertJsonStructure([
                'errors' => [['id', 'source_row_number', 'severity', 'error_code', 'message']],
            ]);

        $this->assertCount(2, $response->json('errors'));
    }

    public function test_errors_returns_empty_array_when_no_errors(): void
    {
        [$company, $user, $upload] = $this->createCompanyUserUpload();

        Sanctum::actingAs($user);

        $this->getJson("/api/uploads/{$upload->id}/errors")
            ->assertOk()
            ->assertJsonPath('errors', []);
    }

    public function test_errors_does_not_leak_across_company_boundary(): void
    {
        [$companyA, $userA, $uploadA] = $this->createCompanyUserUpload('Company A');
        [$companyB, $userB, $uploadB] = $this->createCompanyUserUpload('Company B');
        $runId = $this->createValidationRun($companyB->id, $uploadB->id);
        $this->createRowError($companyB->id, $uploadB->id, $runId, 1, 'blocking', 'MISSING_FIELD', 'Should not be visible');

        Sanctum::actingAs($userA);

        // Company A user cannot see Company B's upload
        $this->getJson("/api/uploads/{$uploadB->id}/errors")
            ->assertNotFound();
    }

    public function test_errors_returns_404_for_unknown_upload(): void
    {
        [, $user] = $this->createCompanyUserUpload();

        Sanctum::actingAs($user);

        $this->getJson('/api/uploads/' . Str::uuid() . '/errors')
            ->assertNotFound();
    }

    public function test_errors_returns_403_without_company(): void
    {
        $user = new User([
            'email' => Str::uuid() . '@example.com',
            'password_hash' => Hash::make('password'),
            'role' => 'owner',
        ]);
        $user->id = (string) Str::uuid();
        $user->company_id = null;
        $user->save();

        Sanctum::actingAs($user);

        $this->getJson('/api/uploads/' . Str::uuid() . '/errors')
            ->assertForbidden();
    }

    public function test_errors_orders_by_row_number(): void
    {
        [$company, $user, $upload] = $this->createCompanyUserUpload();
        $runId = $this->createValidationRun($company->id, $upload->id);
        $this->createRowError($company->id, $upload->id, $runId, 10, 'warning', 'CODE', 'Row 10');
        $this->createRowError($company->id, $upload->id, $runId, 2, 'blocking', 'CODE', 'Row 2');
        $this->createRowError($company->id, $upload->id, $runId, 5, 'warning', 'CODE', 'Row 5');

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/uploads/{$upload->id}/errors")->assertOk();
        $rowNumbers = array_column($response->json('errors'), 'source_row_number');

        $this->assertSame([2, 5, 10], $rowNumbers);
    }

    /** @return array{0: Company, 1: User, 2: Upload} */
    private function createCompanyUserUpload(string $companyName = 'Test Co'): array
    {
        $company = new Company(['name' => $companyName]);
        $company->id = (string) Str::uuid();
        $company->save();

        $user = new User([
            'company_id' => $company->id,
            'email' => Str::uuid() . '@example.com',
            'password_hash' => Hash::make('password'),
            'role' => 'owner',
        ]);
        $user->id = (string) Str::uuid();
        $user->save();

        $upload = new Upload([
            'company_id' => $company->id,
            'uploaded_by' => $user->id,
            'filename' => 'ledger.csv',
            'import_type' => 'transaction_ledger',
            'status' => 'validated',
        ]);
        $upload->id = (string) Str::uuid();
        $upload->save();

        return [$company, $user, $upload];
    }

    private function createValidationRun(string $companyId, string $uploadId): string
    {
        $id = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('upload_validation_runs')->insert([
            'id' => $id,
            'upload_id' => $uploadId,
            'company_id' => $companyId,
            'status' => 'completed',
            'created_at' => now(),
        ]);
        return $id;
    }

    private function createRowError(
        string $companyId,
        string $uploadId,
        string $runId,
        int $rowNumber,
        string $severity,
        string $errorCode,
        string $message,
    ): void {
        $error = new UploadRowError([
            'upload_id' => $uploadId,
            'company_id' => $companyId,
            'validation_run_id' => $runId,
            'source_row_number' => $rowNumber,
            'severity' => $severity,
            'error_code' => $errorCode,
            'message' => $message,
        ]);
        $error->id = (string) Str::uuid();
        $error->save();
    }
}
