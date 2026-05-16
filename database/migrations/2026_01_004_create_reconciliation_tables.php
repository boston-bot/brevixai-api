<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // -- Reconciliation Results (run header) --
        Schema::create('reconciliation_results', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->timestampTz('run_at')->useCurrent();
            $table->date('period_start');
            $table->date('period_end');
            $table->integer('total_mismatches')->default(0);
            $table->decimal('total_impact', 15, 2)->default(0);
            $table->text('status')->default('completed');
            $table->jsonb('results')->default('[]');
        });

        DB::statement("ALTER TABLE reconciliation_results ADD CONSTRAINT reconciliation_results_status_check CHECK (status IN ('running','completed','failed'))");
        DB::statement('CREATE INDEX idx_recon_results_company ON reconciliation_results(company_id, run_at DESC)');

        // -- Legacy Reconciliation Mismatches --
        Schema::create('reconciliation_mismatches', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('run_id')->constrained('reconciliation_results')->cascadeOnDelete();
            $table->text('mismatch_type');
            $table->decimal('amount', 15, 2);
            $table->uuid('bank_txn_id')->nullable();
            $table->uuid('ledger_txn_id')->nullable();
            $table->text('suggested_cause')->nullable();
            $table->boolean('resolved')->default(false);
            $table->foreignUuid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("ALTER TABLE reconciliation_mismatches ADD CONSTRAINT reconciliation_mismatches_type_check CHECK (mismatch_type IN ('timing','duplication','omission','amount_difference','unclassified'))");
        DB::statement('CREATE INDEX idx_recon_mismatches_company ON reconciliation_mismatches(company_id, resolved)');
        DB::statement('CREATE INDEX idx_recon_mismatches_run ON reconciliation_mismatches(run_id)');

        // -- Reconciliation Discrepancies (rich workflow table) --
        Schema::create('reconciliation_discrepancies', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('run_id')->constrained('reconciliation_results')->cascadeOnDelete();
            $table->uuid('bank_txn_id')->nullable();
            $table->uuid('ledger_txn_id')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->text('category');
            $table->text('reason_code');
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->text('risk_level');
            $table->text('recommended_action');
            $table->text('recommendation_explanation');
            $table->text('status')->default('new');
            $table->text('resolution_notes')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE reconciliation_discrepancies ADD CONSTRAINT recon_discrepancies_category_check CHECK (category IN ('probable_match','missing_from_books','missing_from_bank','mismatch','duplicate_suspected','needs_investigation'))");
        DB::statement("ALTER TABLE reconciliation_discrepancies ADD CONSTRAINT recon_discrepancies_risk_check CHECK (risk_level IN ('low','medium','high'))");
        DB::statement("ALTER TABLE reconciliation_discrepancies ADD CONSTRAINT recon_discrepancies_action_check CHECK (recommended_action IN ('confirm_match','create_missing_ledger_entry','mark_timing_difference','merge_duplicate_entries','adjust_amount_to_match','confirm_split_mapping','move_to_correct_account','review_vendor_coding','exclude_bank_fee_and_reconcile_net','request_supporting_documentation','investigate_unauthorized_charge','escalate_for_review'))");
        DB::statement("ALTER TABLE reconciliation_discrepancies ADD CONSTRAINT recon_discrepancies_status_check CHECK (status IN ('new','in_review','confirmed_action','ignored','escalated','resolved'))");
        DB::statement('CREATE INDEX idx_recon_discrepancies_company ON reconciliation_discrepancies(company_id, run_id, status, risk_level)');
        DB::statement('CREATE INDEX idx_recon_discrepancies_lookup ON reconciliation_discrepancies(company_id, bank_txn_id, ledger_txn_id)');

        DB::statement("
            CREATE TRIGGER trigger_reconciliation_discrepancies_updated_at
                BEFORE UPDATE ON reconciliation_discrepancies
                FOR EACH ROW EXECUTE FUNCTION update_updated_at_column()
        ");

        // -- Reconciliation Discrepancy Events (audit trail) --
        Schema::create('reconciliation_discrepancy_events', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('discrepancy_id')->constrained('reconciliation_discrepancies')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('event_type');
            $table->text('previous_status')->nullable();
            $table->text('next_status')->nullable();
            $table->text('selected_action')->nullable();
            $table->text('note')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("ALTER TABLE reconciliation_discrepancy_events ADD CONSTRAINT recon_discrepancy_events_type_check CHECK (event_type IN ('created','status_changed','action_confirmed','note_added'))");
        DB::statement('CREATE INDEX idx_recon_discrepancy_events_company ON reconciliation_discrepancy_events(company_id, discrepancy_id, created_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_discrepancy_events');
        Schema::dropIfExists('reconciliation_discrepancies');
        Schema::dropIfExists('reconciliation_mismatches');
        Schema::dropIfExists('reconciliation_results');
    }
};
