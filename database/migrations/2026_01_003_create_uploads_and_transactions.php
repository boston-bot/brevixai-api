<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // -- Uploads table --
        Schema::create('uploads', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies');
            $table->foreignUuid('uploaded_by')->constrained('users');
            $table->text('filename');
            $table->integer('file_size')->nullable();
            $table->text('status')->default('processing');
            $table->jsonb('sheets_parsed')->nullable();
            $table->integer('row_count')->default(0);
            // Extended columns from migration 023
            $table->text('import_type')->nullable();
            $table->text('original_filename')->nullable();
            $table->text('storage_filename')->nullable();
            $table->text('quarantine_bucket')->nullable();
            $table->text('quarantine_key')->nullable();
            $table->text('claimed_content_type')->nullable();
            $table->text('detected_content_type')->nullable();
            $table->text('file_extension')->nullable();
            $table->bigInteger('file_size_bytes')->nullable();
            $table->text('sha256')->nullable();
            $table->text('status_detail')->nullable();
            $table->text('failure_code')->nullable();
            $table->text('scan_status')->nullable();
            $table->jsonb('scan_result')->nullable();
            $table->jsonb('inspection_summary')->nullable();
            $table->uuid('latest_mapping_version_id')->nullable();
            $table->uuid('latest_validation_run_id')->nullable();
            $table->timestampTz('uploaded_at')->nullable();
            $table->timestampTz('scanned_at')->nullable();
            $table->timestampTz('inspected_at')->nullable();
            $table->timestampTz('validated_at')->nullable();
            $table->timestampTz('promoted_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("ALTER TABLE uploads ADD CONSTRAINT uploads_import_type_check CHECK (import_type IS NULL OR import_type IN ('transaction_ledger', 'ap_invoice_register', 'ar_aging'))");

        // -- Transactions table --
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('upload_id')->constrained('uploads')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies');
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
            $table->boolean('anomaly_flag')->default(false);
            $table->text('anomaly_reason')->nullable();
            $table->jsonb('raw_row')->nullable();
            // Extended columns from migration 023
            $table->uuid('import_batch_id')->nullable();
            $table->text('source_sheet_name')->nullable();
            $table->integer('source_row_number')->nullable();
            $table->text('validation_status')->nullable();
            $table->jsonb('parse_warnings')->nullable();
            $table->text('row_content_hash')->nullable();
        });

        // -- Budget lines table --
        Schema::create('budget_lines', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('upload_id')->constrained('uploads')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies');
            $table->text('sheet_name')->nullable();
            $table->integer('account_code')->nullable();
            $table->text('account_name')->nullable();
            $table->text('department')->nullable();
            $table->jsonb('month_data')->nullable();
        });

        // -- Indexes --
        DB::statement('CREATE INDEX idx_uploads_company ON uploads(company_id)');
        DB::statement('CREATE INDEX idx_transactions_company ON transactions(company_id)');
        DB::statement('CREATE INDEX idx_transactions_upload ON transactions(upload_id)');
        DB::statement('CREATE INDEX idx_transactions_date ON transactions(date)');
        DB::statement('CREATE INDEX idx_transactions_anomaly ON transactions(anomaly_flag) WHERE anomaly_flag = TRUE');
        DB::statement('CREATE INDEX idx_budget_lines_company ON budget_lines(company_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('uploads');
    }
};
