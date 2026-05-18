<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_recommendations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->text('source_risk_domain');
            $table->text('alert_type');
            $table->text('severity');
            $table->text('title');
            $table->text('summary');
            $table->jsonb('evidence')->default('{}');
            $table->jsonb('source_rule_ids')->default('[]');
            $table->decimal('confidence_score', 5, 4)->default(0);
            $table->text('status')->default('pending_review');
            $table->foreignUuid('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'status', 'created_at']);
            $table->index(['company_id', 'source_risk_domain', 'alert_type']);
        });

        DB::statement(
            "ALTER TABLE alert_recommendations ADD CONSTRAINT alert_recommendations_status_check CHECK (status IN ('pending_review', 'approved', 'dismissed', 'expired'))"
        );

        Schema::table('alerts', function (Blueprint $table): void {
            $table->foreignUuid('alert_recommendation_id')
                ->nullable()
                ->after('group_id')
                ->unique()
                ->constrained('alert_recommendations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('alerts', 'alert_recommendation_id')) {
            Schema::table('alerts', function (Blueprint $table): void {
                $table->dropForeign(['alert_recommendation_id']);
                $table->dropUnique('alerts_alert_recommendation_id_unique');
                $table->dropColumn('alert_recommendation_id');
            });
        }

        Schema::dropIfExists('alert_recommendations');
    }
};
