<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('business_profile_id')->nullable()->constrained('business_profiles')->nullOnDelete();
            $table->foreignUuid('legacy_audit_case_id')->nullable()->unique()->constrained('audit_cases')->nullOnDelete();
            $table->text('title');
            $table->text('category')->default('unsure');
            $table->text('subcategory')->nullable();
            $table->text('status')->default('open');
            $table->text('priority')->default('medium');
            $table->date('review_period_start')->nullable();
            $table->date('review_period_end')->nullable();
            $table->text('scope_statement')->nullable();
            $table->jsonb('scope_limitations')->default('[]');
            $table->foreignUuid('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('last_activity_at')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->index(['company_id', 'business_profile_id', 'status']);
            $table->index(['company_id', 'business_profile_id', 'priority']);
            $table->index(['company_id', 'category', 'status']);
            $table->index(['assigned_to', 'status']);
            $table->index(['last_activity_at']);
        });

        Schema::create('findings', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('business_profile_id')->nullable()->constrained('business_profiles')->nullOnDelete();
            $table->foreignUuid('investigation_id')->nullable()->constrained('investigations')->nullOnDelete();
            $table->text('category')->default('unsure');
            $table->text('source_module');
            $table->text('source_record_type');
            $table->text('source_record_id');
            $table->text('title');
            $table->text('summary')->nullable();
            $table->text('detail')->nullable();
            $table->text('severity')->default('warning');
            $table->text('confidence')->nullable();
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->text('reason_code')->nullable();
            $table->text('status')->default('new');
            $table->jsonb('evidence_refs')->default('[]');
            $table->jsonb('recommended_action')->nullable();
            $table->text('reviewer_status')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->index(['company_id', 'business_profile_id', 'status']);
            $table->index(['company_id', 'category', 'status']);
            $table->index(['investigation_id', 'status']);
            $table->index(['source_module', 'source_record_type', 'source_record_id'], 'idx_findings_source_lookup');
            $table->unique(
                ['company_id', 'business_profile_id', 'source_module', 'source_record_type', 'source_record_id'],
                'findings_company_profile_source_unique'
            );
        });

        Schema::create('evidence_items', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('business_profile_id')->nullable()->constrained('business_profiles')->nullOnDelete();
            $table->foreignUuid('investigation_id')->constrained('investigations')->cascadeOnDelete();
            $table->foreignUuid('finding_id')->nullable()->constrained('findings')->nullOnDelete();
            $table->foreignUuid('legacy_evidence_item_id')->nullable()->unique()->constrained('investigation_evidence_items')->nullOnDelete();
            $table->text('evidence_type');
            $table->text('source_type')->nullable();
            $table->text('source_id')->nullable();
            $table->text('source_record_id')->nullable();
            $table->text('title');
            $table->text('summary')->nullable();
            $table->text('citation_label')->nullable();
            $table->text('source_row_range')->nullable();
            $table->text('file_name')->nullable();
            $table->text('storage_key')->nullable();
            $table->text('hash')->nullable();
            $table->text('added_by_actor_type')->default('user');
            $table->uuid('added_by_actor_id')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->nullable();

            $table->index(['investigation_id', 'created_at']);
            $table->index(['finding_id', 'created_at']);
            $table->index(['company_id', 'business_profile_id', 'created_at']);
            $table->index(['source_type', 'source_id', 'source_record_id'], 'idx_evidence_source_lookup');
        });

        Schema::create('evidence_item_finding', function (Blueprint $table): void {
            $table->foreignUuid('evidence_item_id')->constrained('evidence_items')->cascadeOnDelete();
            $table->foreignUuid('finding_id')->constrained('findings')->cascadeOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['evidence_item_id', 'finding_id']);
        });

        Schema::create('suggested_records', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('business_profile_id')->nullable()->constrained('business_profiles')->nullOnDelete();
            $table->foreignUuid('investigation_id')->constrained('investigations')->cascadeOnDelete();
            $table->foreignUuid('finding_id')->nullable()->constrained('findings')->nullOnDelete();
            $table->text('record_type');
            $table->text('label');
            $table->text('reason')->nullable();
            $table->text('priority')->default('recommended');
            $table->text('status')->default('requested');
            $table->foreignUuid('satisfying_evidence_item_id')->nullable()->constrained('evidence_items')->nullOnDelete();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->index(['investigation_id', 'status']);
            $table->index(['finding_id', 'status']);
        });

        Schema::create('reviewer_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('business_profile_id')->nullable()->constrained('business_profiles')->nullOnDelete();
            $table->foreignUuid('investigation_id')->constrained('investigations')->cascadeOnDelete();
            $table->foreignUuid('finding_id')->nullable()->constrained('findings')->nullOnDelete();
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('author_name')->nullable();
            $table->text('body');
            $table->text('visibility')->default('internal');
            $table->timestampsTz();

            $table->index(['investigation_id', 'created_at']);
            $table->index(['finding_id', 'created_at']);
        });

        Schema::create('review_events', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('business_profile_id')->nullable()->constrained('business_profiles')->nullOnDelete();
            $table->foreignUuid('investigation_id')->constrained('investigations')->cascadeOnDelete();
            $table->foreignUuid('finding_id')->nullable()->constrained('findings')->nullOnDelete();
            $table->text('event_type');
            $table->text('actor_type');
            $table->uuid('actor_id')->nullable();
            $table->text('previous_status')->nullable();
            $table->text('next_status')->nullable();
            $table->text('note')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['investigation_id', 'created_at']);
            $table->index(['finding_id', 'created_at']);
            $table->index(['company_id', 'event_type', 'created_at']);
        });

        Schema::create('case_packages', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('business_profile_id')->nullable()->constrained('business_profiles')->nullOnDelete();
            $table->foreignUuid('investigation_id')->constrained('investigations')->cascadeOnDelete();
            $table->text('format');
            $table->text('status')->default('completed');
            $table->text('title');
            $table->timestampTz('generated_at')->nullable();
            $table->foreignUuid('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('included_sections')->default('[]');
            $table->jsonb('included_counts')->default('{}');
            $table->text('package_hash')->nullable();
            $table->text('filename')->nullable();
            $table->text('storage_key')->nullable();
            $table->jsonb('manifest')->default('{}');
            $table->text('error_message')->nullable();
            $table->timestampsTz();

            $table->index(['investigation_id', 'generated_at']);
            $table->index(['company_id', 'business_profile_id', 'generated_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('investigation_source_records', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('business_profile_id')->nullable()->constrained('business_profiles')->nullOnDelete();
            $table->foreignUuid('investigation_id')->constrained('investigations')->cascadeOnDelete();
            $table->text('source_module');
            $table->text('source_record_type');
            $table->text('source_record_id');
            $table->text('relationship_type')->default('related');
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->unique(
                ['investigation_id', 'source_module', 'source_record_type', 'source_record_id', 'relationship_type'],
                'investigation_source_records_unique'
            );
            $table->index(['company_id', 'business_profile_id', 'source_module'], 'idx_investigation_source_records_tenant');
        });

        Schema::create('tax_notice_interpretations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('business_profile_id')->nullable()->constrained('business_profiles')->nullOnDelete();
            $table->foreignUuid('investigation_id')->nullable()->constrained('investigations')->nullOnDelete();
            $table->foreignUuid('finding_id')->nullable()->constrained('findings')->nullOnDelete();
            $table->foreignUuid('source_upload_id')->nullable()->constrained('uploads')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notice_text_hash')->nullable();
            $table->text('notice_text_encrypted')->nullable();
            $table->text('notice_type')->nullable();
            $table->integer('deadline_days')->nullable();
            $table->text('deadline_description')->nullable();
            $table->text('required_action')->nullable();
            $table->text('risk_level')->nullable();
            $table->decimal('key_amount', 15, 2)->nullable();
            $table->text('summary')->nullable();
            $table->jsonb('extraction')->default('{}');
            $table->timestampsTz();

            $table->index(['company_id', 'business_profile_id', 'notice_type']);
            $table->index(['investigation_id', 'created_at']);
        });

        $this->addCheckConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_notice_interpretations');
        Schema::dropIfExists('investigation_source_records');
        Schema::dropIfExists('case_packages');
        Schema::dropIfExists('review_events');
        Schema::dropIfExists('reviewer_notes');
        Schema::dropIfExists('suggested_records');
        Schema::dropIfExists('evidence_item_finding');
        Schema::dropIfExists('evidence_items');
        Schema::dropIfExists('findings');
        Schema::dropIfExists('investigations');
    }

    private function addCheckConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE investigations ADD CONSTRAINT investigations_category_check CHECK (category IN ('revenue','expense','payroll','tax','fraud','reconciliation','controls','vendor_payments','cash_flow','unsure'))");
        DB::statement("ALTER TABLE investigations ADD CONSTRAINT investigations_status_check CHECK (status IN ('open','in_review','waiting_on_records','pending_reviewer_approval','ready_for_package','closed','archived'))");
        DB::statement("ALTER TABLE investigations ADD CONSTRAINT investigations_priority_check CHECK (priority IN ('critical','high','medium','low'))");
        DB::statement("ALTER TABLE findings ADD CONSTRAINT findings_severity_check CHECK (severity IN ('info','warning','critical'))");
        DB::statement("ALTER TABLE findings ADD CONSTRAINT findings_confidence_check CHECK (confidence IS NULL OR confidence IN ('low','medium','high'))");
        DB::statement("ALTER TABLE findings ADD CONSTRAINT findings_status_check CHECK (status IN ('new','in_review','needs_more_evidence','reviewed','dismissed','escalated','included_in_package'))");
        DB::statement("ALTER TABLE findings ADD CONSTRAINT findings_reviewer_status_check CHECK (reviewer_status IS NULL OR reviewer_status IN ('pending','reviewed','dismissed'))");
        DB::statement("ALTER TABLE evidence_items ADD CONSTRAINT evidence_items_actor_type_check CHECK (added_by_actor_type IN ('user','system','agent'))");
        DB::statement("ALTER TABLE suggested_records ADD CONSTRAINT suggested_records_priority_check CHECK (priority IN ('required','recommended','optional'))");
        DB::statement("ALTER TABLE suggested_records ADD CONSTRAINT suggested_records_status_check CHECK (status IN ('requested','received','waived','not_available'))");
        DB::statement("ALTER TABLE reviewer_notes ADD CONSTRAINT reviewer_notes_visibility_check CHECK (visibility IN ('internal','client','package'))");
        DB::statement("ALTER TABLE review_events ADD CONSTRAINT review_events_actor_type_check CHECK (actor_type IN ('user','system','agent'))");
        DB::statement("ALTER TABLE case_packages ADD CONSTRAINT case_packages_format_check CHECK (format IN ('json','pdf','zip'))");
        DB::statement("ALTER TABLE case_packages ADD CONSTRAINT case_packages_status_check CHECK (status IN ('queued','processing','completed','failed'))");
    }
};
