<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_inspections', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('upload_id')->constrained('uploads')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->jsonb('workbook_metadata')->default('{}');
            $table->jsonb('sheet_inventory')->default('[]');
            $table->jsonb('parser_warnings')->default('[]');
            $table->jsonb('sample_preview')->default('[]');
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('upload_mapping_versions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('upload_id')->constrained('uploads')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->integer('version_number');
            $table->string('import_type');
            $table->string('source_sheet_name')->nullable();
            $table->integer('header_row_index')->default(1);
            $table->jsonb('field_mappings')->default('{}');
            $table->jsonb('confidence_hints')->default('{}');
            $table->string('mapping_source')->default('user');
            $table->foreignUuid('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['upload_id', 'version_number']);
        });

        Schema::create('upload_validation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('upload_id')->constrained('uploads')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('mapping_version_id')->nullable()->constrained('upload_mapping_versions')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->integer('total_row_count')->default(0);
            $table->integer('valid_row_count')->default(0);
            $table->integer('invalid_row_count')->default(0);
            $table->integer('blocking_error_count')->default(0);
            $table->integer('warning_count')->default(0);
            $table->jsonb('summary')->default('{}');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('upload_row_errors', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('upload_id')->constrained('uploads')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('validation_run_id')->constrained('upload_validation_runs')->cascadeOnDelete();
            $table->string('source_sheet_name')->nullable();
            $table->integer('source_row_number')->nullable();
            $table->string('canonical_field_key')->nullable();
            $table->string('severity'); // blocking, warning
            $table->string('error_code');
            $table->text('message');
            $table->text('raw_value')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('upload_id')->constrained('uploads')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('mapping_version_id')->nullable()->constrained('upload_mapping_versions')->nullOnDelete();
            $table->foreignUuid('validation_run_id')->nullable()->constrained('upload_validation_runs')->nullOnDelete();
            $table->string('trusted_target_domain');
            $table->integer('imported_row_count')->default(0);
            $table->integer('skipped_row_count')->default(0);
            $table->integer('failed_row_count')->default(0);
            $table->foreignUuid('promoted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('promoted_at')->useCurrent();
        });

        // Add foreign keys to uploads table
        Schema::table('uploads', function (Blueprint $table) {
            $table->foreign('latest_mapping_version_id')->references('id')->on('upload_mapping_versions')->nullOnDelete();
            $table->foreign('latest_validation_run_id')->references('id')->on('upload_validation_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->dropForeign(['latest_mapping_version_id']);
            $table->dropForeign(['latest_validation_run_id']);
        });

        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('upload_row_errors');
        Schema::dropIfExists('upload_validation_runs');
        Schema::dropIfExists('upload_mapping_versions');
        Schema::dropIfExists('upload_inspections');
    }
};
