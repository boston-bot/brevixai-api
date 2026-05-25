<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('business_profile_id')->nullable()->constrained('business_profiles')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 50)->default('in_progress');
            $table->string('primary_intent', 100)->nullable();
            $table->string('current_step', 100)->default('intent');
            $table->date('review_period_start')->nullable();
            $table->date('review_period_end')->nullable();
            $table->string('scope_mode', 50)->default('standard');
            $table->jsonb('business_context')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('scope_acknowledged_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['company_id', 'business_profile_id', 'status']);
            $table->index(['company_id', 'primary_intent']);
        });

        Schema::create('onboarding_answers', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('onboarding_session_id')->constrained('onboarding_sessions')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('business_profile_id')->nullable()->constrained('business_profiles')->nullOnDelete();
            $table->foreignUuid('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('answer_key', 100);
            $table->jsonb('answer_value');
            $table->timestampsTz();

            $table->unique(['onboarding_session_id', 'answer_key']);
            $table->index(['company_id', 'business_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_answers');
        Schema::dropIfExists('onboarding_sessions');
    }
};
