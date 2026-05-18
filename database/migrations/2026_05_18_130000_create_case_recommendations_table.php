<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_recommendations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->text('case_type');
            $table->text('severity');
            $table->text('title');
            $table->text('summary');
            $table->jsonb('source_risk_domains')->default('[]');
            $table->jsonb('related_alert_recommendation_ids')->default('[]');
            $table->jsonb('evidence')->default('{}');
            $table->decimal('confidence_score', 5, 4)->default(0);
            $table->boolean('requires_human_review')->default(true);
            $table->boolean('can_auto_create')->default(false);
            $table->text('status')->default('pending_review');
            $table->foreignUuid('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'status', 'created_at']);
            $table->index(['company_id', 'case_type', 'status']);
        });

        DB::statement(
            "ALTER TABLE case_recommendations ADD CONSTRAINT case_recommendations_status_check CHECK (status IN ('pending_review', 'approved', 'dismissed', 'expired'))"
        );
        DB::statement(
            'ALTER TABLE case_recommendations ADD CONSTRAINT case_recommendations_requires_human_review_check CHECK (requires_human_review = true)'
        );
        DB::statement(
            'ALTER TABLE case_recommendations ADD CONSTRAINT case_recommendations_can_auto_create_check CHECK (can_auto_create = false)'
        );

        Schema::table('audit_cases', function (Blueprint $table): void {
            $table->foreignUuid('case_recommendation_id')
                ->nullable()
                ->after('created_by')
                ->unique()
                ->constrained('case_recommendations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('audit_cases', 'case_recommendation_id')) {
            Schema::table('audit_cases', function (Blueprint $table): void {
                $table->dropForeign(['case_recommendation_id']);
                $table->dropUnique('audit_cases_case_recommendation_id_unique');
                $table->dropColumn('case_recommendation_id');
            });
        }

        Schema::dropIfExists('case_recommendations');
    }
};
