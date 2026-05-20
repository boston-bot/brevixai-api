<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_finance_statement_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('imported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('source_filename');
            $table->text('source_path')->nullable();
            $table->string('sha256', 64);
            $table->date('statement_date')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('account_last4', 8)->nullable();
            $table->string('status')->default('pending');
            $table->unsignedInteger('transaction_count')->default(0);
            $table->json('warnings')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'sha256'], 'pf_statement_company_sha_unique');
            $table->index(['company_id', 'period_end'], 'pf_statement_company_period_idx');
        });

        Schema::create('personal_finance_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('statement_import_id')
                ->constrained('personal_finance_statement_imports')
                ->cascadeOnDelete();
            $table->date('posted_date');
            $table->text('description');
            $table->text('normalized_merchant')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('direction', 16);
            $table->string('category', 80)->default('uncategorized');
            $table->string('person_scope', 32)->default('unknown');
            $table->string('recurring_key', 160)->nullable();
            $table->string('source_section', 120)->nullable();
            $table->unsignedTinyInteger('confidence')->default(50);
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'posted_date'], 'pf_transactions_company_date_idx');
            $table->index(['company_id', 'category'], 'pf_transactions_company_category_idx');
            $table->index(['company_id', 'person_scope'], 'pf_transactions_company_person_idx');
            $table->index(['company_id', 'recurring_key'], 'pf_transactions_company_recurring_idx');
        });

        Schema::create('personal_finance_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('rule_type', 32);
            $table->string('name', 160);
            $table->string('match_field', 40)->default('description');
            $table->text('pattern');
            $table->string('target_value', 160)->nullable();
            $table->integer('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'rule_type', 'is_active', 'priority'], 'pf_rules_lookup_idx');
        });

        Schema::create('personal_finance_budget_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 120)->default('Default');
            $table->string('person_a_label', 120)->default('Person A');
            $table->string('person_b_label', 120)->default('Person B');
            $table->decimal('person_a_monthly_allowance', 14, 2)->default(0);
            $table->decimal('person_b_monthly_allowance', 14, 2)->default(0);
            $table->decimal('shared_monthly_cap', 14, 2)->nullable();
            $table->decimal('opaque_card_payment_cap', 14, 2)->nullable();
            $table->decimal('catch_up_target_amount', 14, 2)->nullable();
            $table->json('category_caps')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('company_id', 'pf_budget_profile_company_unique');
        });

        Schema::create('personal_finance_analysis_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->json('summary');
            $table->json('warnings')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['company_id', 'generated_at'], 'pf_analysis_company_generated_idx');
        });

        Schema::create('personal_finance_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('analysis_run_id')
                ->nullable()
                ->constrained('personal_finance_analysis_runs')
                ->nullOnDelete();
            $table->foreignUuid('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('format', 16);
            $table->text('filename');
            $table->string('report_hash', 64);
            $table->json('filters')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['company_id', 'generated_at'], 'pf_exports_company_generated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_finance_exports');
        Schema::dropIfExists('personal_finance_analysis_runs');
        Schema::dropIfExists('personal_finance_budget_profiles');
        Schema::dropIfExists('personal_finance_rules');
        Schema::dropIfExists('personal_finance_transactions');
        Schema::dropIfExists('personal_finance_statement_imports');
    }
};
