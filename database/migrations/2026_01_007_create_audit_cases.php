<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // -- Audit Cases --
        Schema::create('audit_cases', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->text('title');
            $table->text('description')->nullable();
            $table->text('status')->default('open'); // open, investigating, resolved, archived
            $table->text('severity')->default('warning'); // critical, warning, info
            $table->foreignUuid('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            // Store UUID arrays using JSONB or native array type. For pgsql native array:
            $table->text('resolution_notes')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
        });

        // Add native PostgreSQL UUID array columns directly
        DB::statement("ALTER TABLE audit_cases ADD COLUMN alert_ids UUID[] NOT NULL DEFAULT '{}'");
        DB::statement("ALTER TABLE audit_cases ADD COLUMN transaction_ids UUID[] NOT NULL DEFAULT '{}'");

        // -- Case Events --
        Schema::create('audit_case_events', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('case_id')->constrained('audit_cases')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('event_type');
            $table->jsonb('payload')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
        });

        // -- Case PDFs --
        Schema::create('audit_case_pdfs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('case_id')->constrained('audit_cases')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->text('payload_hash');
            $table->text('storage_key');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement('CREATE INDEX idx_audit_cases_company_status ON audit_cases (company_id, status)');
        DB::statement('CREATE INDEX idx_audit_cases_company_assigned ON audit_cases (company_id, assigned_to)');
        DB::statement('CREATE INDEX idx_audit_cases_company_created ON audit_cases (company_id, created_at DESC)');
        DB::statement('CREATE INDEX idx_audit_case_events_case ON audit_case_events (case_id, created_at ASC)');
        DB::statement('CREATE INDEX idx_audit_case_events_company ON audit_case_events (company_id)');
        DB::statement('CREATE INDEX idx_audit_case_pdfs_case ON audit_case_pdfs (case_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_case_pdfs');
        Schema::dropIfExists('audit_case_events');
        Schema::dropIfExists('audit_cases');
    }
};
