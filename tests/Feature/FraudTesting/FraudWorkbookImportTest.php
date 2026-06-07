<?php

namespace Tests\Feature\FraudTesting;

use App\Services\FraudTesting\FraudWorkbookImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class FraudWorkbookImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.fraud_testing.api_key' => 'test-fraud-token']);
        $this->createImportTables();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('fraud_scenario_submissions');
        Schema::dropIfExists('fraud_scenario_imports');
        parent::tearDown();
    }

    public function test_import_valid_workbook_creates_submissions(): void
    {
        $path = $this->buildWorkbook([
            ['PAYROLL-001', 'Ghost Employee', 'A payroll manager created a fictitious employee.', 'Personal Experience', 'High', 'Ready'],
            ['PAYROLL-002', 'Expense Fraud', 'An employee submitted duplicate receipts.', '', 'Medium', 'Draft'],
        ]);

        $service = app(FraudWorkbookImportService::class);
        $import = $service->importFromPath($path);

        $this->assertEquals('completed', $import->status, 'Import errors: ' . json_encode($import->validation_errors));
        $this->assertEquals(2, $import->total_rows);
        $this->assertEquals(2, $import->successful_rows);
        $this->assertEquals(0, $import->failed_rows);

        $this->assertDatabaseHas('fraud_scenario_submissions', [
            'external_scenario_id' => 'PAYROLL-001',
            'title' => 'Ghost Employee',
            'severity' => 'High',
        ]);
        $this->assertDatabaseHas('fraud_scenario_submissions', [
            'external_scenario_id' => 'PAYROLL-002',
            'title' => 'Expense Fraud',
        ]);
    }

    public function test_import_skips_row_with_missing_narrative(): void
    {
        $path = $this->buildWorkbook([
            ['PAYROLL-001', 'Ghost Employee', '', 'Personal Experience', 'High', 'Ready'],
        ]);

        $service = app(FraudWorkbookImportService::class);
        $import = $service->importFromPath($path);

        $this->assertEquals(1, $import->total_rows);
        $this->assertEquals(0, $import->successful_rows);
        $this->assertEquals(1, $import->failed_rows);
        $this->assertNotEmpty($import->validation_errors);
    }

    public function test_import_skips_row_with_missing_title(): void
    {
        $path = $this->buildWorkbook([
            ['PAYROLL-001', '', 'A narrative without a title.', '', 'High', 'Ready'],
        ]);

        $service = app(FraudWorkbookImportService::class);
        $import = $service->importFromPath($path);

        $this->assertEquals(0, $import->successful_rows);
        $this->assertEquals(1, $import->failed_rows);
    }

    public function test_import_deduplicates_by_scenario_id(): void
    {
        $path = $this->buildWorkbook([
            ['PAYROLL-001', 'Ghost Employee', 'First version of this scenario.', '', 'High', 'Ready'],
            ['PAYROLL-001', 'Ghost Employee Duplicate', 'Second version.', '', 'Medium', 'Draft'],
        ]);

        $service = app(FraudWorkbookImportService::class);
        $import = $service->importFromPath($path);

        $this->assertEquals(1, $import->successful_rows);
        $this->assertEquals(1, $import->failed_rows);

        $count = \Illuminate\Support\Facades\DB::table('fraud_scenario_submissions')
            ->where('external_scenario_id', 'PAYROLL-001')
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_import_dry_run_does_not_save_records(): void
    {
        $path = $this->buildWorkbook([
            ['PAYROLL-001', 'Ghost Employee', 'A payroll manager created a fictitious employee.', '', 'High', 'Ready'],
        ]);

        $service = app(FraudWorkbookImportService::class);
        $import = $service->importFromPath($path, null, true);

        $this->assertEquals(1, $import->successful_rows);
        $count = \Illuminate\Support\Facades\DB::table('fraud_scenario_submissions')->count();
        $this->assertEquals(0, $count);
    }

    public function test_import_records_validation_errors_for_invalid_severity(): void
    {
        $path = $this->buildWorkbook([
            ['PAYROLL-001', 'Ghost Employee', 'A payroll manager created a fictitious employee.', '', 'Extreme', 'Ready'],
        ]);

        $service = app(FraudWorkbookImportService::class);
        $import = $service->importFromPath($path);

        $this->assertEquals(0, $import->successful_rows);
        $this->assertNotEmpty($import->validation_errors);
    }

    public function test_import_upload_endpoint_rejects_missing_file(): void
    {
        $this->withToken('test-fraud-token')
            ->postJson('/api/internal/fraud-testing/imports')
            ->assertUnprocessable();
    }

    public function test_import_upload_endpoint_requires_auth(): void
    {
        $this->postJson('/api/internal/fraud-testing/imports')
            ->assertUnauthorized();
    }

    public function test_import_completed_with_errors_status_when_some_fail(): void
    {
        $path = $this->buildWorkbook([
            ['PAYROLL-001', 'Ghost Employee', 'A valid narrative.', '', 'High', 'Ready'],
            ['PAYROLL-002', '', 'Another narrative.', '', 'Low', 'Draft'],
        ]);

        $service = app(FraudWorkbookImportService::class);
        $import = $service->importFromPath($path);

        $this->assertEquals('completed_with_errors', $import->status);
        $this->assertEquals(1, $import->successful_rows);
        $this->assertEquals(1, $import->failed_rows);
    }

    private function buildWorkbook(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = ['Scenario ID', 'Title', 'Narrative', 'Source', 'Severity', 'Status'];
        $sheet->fromArray([$headers], null, 'A1');

        foreach ($rows as $i => $row) {
            $sheet->fromArray([$row], null, 'A' . ($i + 2));
        }

        $tmpPath = sys_get_temp_dir() . '/' . Str::uuid() . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tmpPath);

        return $tmpPath;
    }

    private function createImportTables(): void
    {
        Schema::create('fraud_scenario_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('original_filename')->nullable();
            $table->string('storage_path')->nullable();
            $table->uuid('uploaded_by_id')->nullable();
            $table->string('status')->default('uploaded');
            $table->integer('total_rows')->default(0);
            $table->integer('successful_rows')->default(0);
            $table->integer('failed_rows')->default(0);
            $table->text('validation_errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('fraud_scenario_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('import_id')->nullable();
            $table->string('external_scenario_id')->nullable();
            $table->string('title');
            $table->text('narrative');
            $table->string('source')->nullable();
            $table->string('severity')->nullable();
            $table->string('status')->default('imported');
            $table->string('extraction_status')->default('pending');
            $table->string('mock_data_status')->default('pending');
            $table->string('review_status')->default('unreviewed');
            $table->integer('row_number')->nullable();
            $table->text('raw_row')->nullable();
            $table->timestamps();
        });
    }
}
