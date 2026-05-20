<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigation_report_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('audit_case_id')->constrained('audit_cases')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('generated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('format');
            $table->text('filename')->nullable();
            $table->text('report_hash');
            $table->timestampTz('generated_at')->useCurrent();
            $table->jsonb('metadata')->nullable();
        });

        DB::statement(
            "ALTER TABLE investigation_report_exports ADD CONSTRAINT investigation_report_exports_format_check
             CHECK (format IN ('json', 'pdf'))"
        );

        DB::statement('CREATE INDEX idx_investigation_report_exports_case_generated ON investigation_report_exports (audit_case_id, generated_at DESC)');
        DB::statement('CREATE INDEX idx_investigation_report_exports_company_generated ON investigation_report_exports (company_id, generated_at DESC)');
        DB::statement('CREATE INDEX idx_investigation_report_exports_user ON investigation_report_exports (generated_by_user_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_report_exports');
    }
};
