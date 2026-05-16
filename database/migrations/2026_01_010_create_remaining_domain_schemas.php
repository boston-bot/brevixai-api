<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // =============================================================================
        // AR Aging: Invoices Table
        // =============================================================================
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('upload_id')->nullable()->constrained('uploads')->nullOnDelete();
            
            $table->string('customer_name');
            $table->string('invoice_number')->nullable();
            $table->date('invoice_date');
            $table->date('due_date');
            
            // Decimal types for amounts
            $table->decimal('amount', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);
            
            $table->string('status')->default('open');
            
            // Collection
            $table->text('collection_notes')->nullable();
            $table->date('last_contact_date')->nullable();
            
            // Write-off
            $table->date('write_off_date')->nullable();
            $table->text('write_off_reason')->nullable();
            
            $table->string('source')->default('manual');
            $table->jsonb('raw_row')->nullable();
            
            $table->timestampsTz();
        });

        // Computed column and constraints
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_amount_check CHECK (amount >= 0)');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_paid_amount_check CHECK (paid_amount >= 0)');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status IN ('open', 'partial', 'paid', 'written_off'))");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_source_check CHECK (source IN ('csv', 'qbo', 'manual'))");
        DB::statement('ALTER TABLE invoices ADD COLUMN balance_due NUMERIC(15, 2) GENERATED ALWAYS AS (amount - paid_amount) STORED');

        // Indexes for invoices
        DB::statement('CREATE INDEX invoices_company_id_idx ON invoices (company_id)');
        DB::statement('CREATE INDEX invoices_company_status_idx ON invoices (company_id, status)');
        DB::statement('CREATE INDEX invoices_due_date_idx ON invoices (company_id, due_date)');
        DB::statement('CREATE INDEX invoices_customer_idx ON invoices (company_id, customer_name)');
        DB::statement('CREATE INDEX invoices_invoice_number_idx ON invoices (company_id, invoice_number) WHERE invoice_number IS NOT NULL');

        // Note: The `invoices` table was modified in the uploads migration to add `import_batch_id`, 
        // `source_sheet_name`, `source_row_number`, and `row_content_hash`. Since it didn't exist 
        // there (Laravel silently ignored it or it wasn't added), we add them here:
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignUuid('import_batch_id')->nullable()->constrained('import_batches')->nullOnDelete();
            $table->string('source_sheet_name')->nullable();
            $table->integer('source_row_number')->nullable();
            $table->string('row_content_hash')->nullable();
        });

        // =============================================================================
        // Entity Graph
        // =============================================================================
        Schema::create('entity_graph_runs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->timestampTz('run_at')->useCurrent();
            $table->integer('node_count')->default(0);
            $table->integer('edge_count')->default(0);
            $table->integer('pattern_count')->default(0);
            $table->string('status')->default('completed');
            $table->jsonb('results')->default('{}');
        });
        
        DB::statement("ALTER TABLE entity_graph_runs ADD CONSTRAINT entity_graph_runs_status_check CHECK (status IN ('running', 'completed', 'failed'))");
        DB::statement('CREATE INDEX idx_entity_graph_runs_company ON entity_graph_runs(company_id, run_at DESC)');

        Schema::create('entity_graph_patterns', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('run_id')->constrained('entity_graph_runs')->cascadeOnDelete();
            $table->string('pattern_type');
            $table->string('severity')->default('warning');
            $table->string('title');
            $table->text('description');
            $table->jsonb('involved_entities')->default('[]');
            $table->jsonb('evidence')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("ALTER TABLE entity_graph_patterns ADD CONSTRAINT entity_graph_patterns_type_check CHECK (pattern_type IN ('circular_payment', 'shared_bank_account', 'ghost_vendor', 'single_source_concentration', 'velocity_anomaly'))");
        DB::statement("ALTER TABLE entity_graph_patterns ADD CONSTRAINT entity_graph_patterns_severity_check CHECK (severity IN ('critical', 'warning', 'info'))");
        DB::statement('CREATE INDEX idx_entity_graph_patterns_company ON entity_graph_patterns(company_id)');
        DB::statement('CREATE INDEX idx_entity_graph_patterns_run ON entity_graph_patterns(run_id)');

        // =============================================================================
        // Controls Framework
        // =============================================================================
        Schema::create('control_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('control_key');
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->string('category');
            $table->boolean('enabled')->default(true);
            $table->jsonb('config')->default('{}');
            $table->timestampsTz();
            
            $table->unique(['company_id', 'control_key']);
        });

        DB::statement("ALTER TABLE control_definitions ADD CONSTRAINT control_definitions_category_check CHECK (category IN ('approval', 'segregation', 'documentation', 'access', 'financial'))");

        Schema::create('control_evaluations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('control_id')->constrained('control_definitions')->cascadeOnDelete();
            $table->string('status');
            $table->integer('score');
            $table->jsonb('evidence')->default('{}');
            $table->timestampTz('evaluated_at')->useCurrent();
        });

        DB::statement("ALTER TABLE control_evaluations ADD CONSTRAINT control_evaluations_status_check CHECK (status IN ('passing', 'failing', 'warning', 'not_evaluated'))");
        DB::statement('ALTER TABLE control_evaluations ADD CONSTRAINT control_evaluations_score_check CHECK (score BETWEEN 0 AND 100)');
        DB::statement('CREATE INDEX idx_control_evals_company ON control_evaluations(company_id, evaluated_at DESC)');

        Schema::create('control_violations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('control_id')->constrained('control_definitions')->cascadeOnDelete();
            $table->string('violation_type');
            $table->text('description');
            $table->string('severity')->default('warning');
            $table->boolean('resolved')->default(false);
            $table->foreignUuid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        // Use raw statement to add UUID[] array column for transaction_ids
        DB::statement("ALTER TABLE control_violations ADD COLUMN transaction_ids UUID[] NOT NULL DEFAULT '{}'");
        DB::statement("ALTER TABLE control_violations ADD CONSTRAINT control_violations_severity_check CHECK (severity IN ('critical', 'warning', 'info'))");
        DB::statement('CREATE INDEX idx_control_violations_company ON control_violations(company_id, resolved)');
    }

    public function down(): void
    {
        Schema::dropIfExists('control_violations');
        Schema::dropIfExists('control_evaluations');
        Schema::dropIfExists('control_definitions');
        Schema::dropIfExists('entity_graph_patterns');
        Schema::dropIfExists('entity_graph_runs');
        Schema::dropIfExists('invoices');
    }
};
