<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxpayer_transparency_items', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('business_profile_id')->nullable()->constrained('business_profiles')->nullOnDelete();
            $table->foreignUuid('audit_case_id')->constrained('audit_cases')->cascadeOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('category');
            $table->text('status_key')->nullable();
            $table->text('tax_period')->nullable();
            $table->text('label');
            $table->text('detail')->nullable();
            $table->text('source_type')->nullable();
            $table->text('source_label')->nullable();
            $table->text('source_reference')->nullable();
            $table->date('source_date')->nullable();
            $table->timestampTz('captured_at')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->index(['company_id', 'business_profile_id', 'audit_case_id'], 'idx_taxpayer_transparency_case');
            $table->index(['audit_case_id', 'category', 'created_at'], 'idx_taxpayer_transparency_category');
        });

        $this->addCheckConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists('taxpayer_transparency_items');
    }

    private function addCheckConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE taxpayer_transparency_items ADD CONSTRAINT taxpayer_transparency_items_category_check CHECK (category IN ('verified_fact','unverified_claim','assumption','unknown'))");
        DB::statement("ALTER TABLE taxpayer_transparency_items ADD CONSTRAINT taxpayer_transparency_items_source_type_check CHECK (source_type IS NULL OR source_type IN ('irs_transcript','irs_notice','payment_record','agency_record','court_record','public_record','filed_return_copy','bank_record','representative_statement','taxpayer_statement','internal_note','other'))");
    }
};
