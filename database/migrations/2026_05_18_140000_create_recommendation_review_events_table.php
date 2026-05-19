<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendation_review_events', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->text('recommendation_type');
            $table->uuid('recommendation_id');
            $table->text('event_type');
            $table->text('actor_type');
            $table->uuid('actor_id')->nullable();
            $table->jsonb('event_metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['company_id', 'recommendation_type', 'recommendation_id', 'created_at'], 'idx_recommendation_review_events_lookup');
            $table->index(['company_id', 'event_type', 'created_at'], 'idx_recommendation_review_events_event');
        });

        DB::statement(
            "ALTER TABLE recommendation_review_events ADD CONSTRAINT recommendation_review_events_recommendation_type_check CHECK (recommendation_type IN ('alert', 'case'))"
        );
        DB::statement(
            "ALTER TABLE recommendation_review_events ADD CONSTRAINT recommendation_review_events_event_type_check CHECK (event_type IN ('created', 'viewed', 'approved', 'dismissed', 'expired'))"
        );
        DB::statement(
            "ALTER TABLE recommendation_review_events ADD CONSTRAINT recommendation_review_events_actor_type_check CHECK (actor_type IN ('user', 'system', 'agent'))"
        );
        DB::statement(
            "ALTER TABLE recommendation_review_events ADD CONSTRAINT recommendation_review_events_review_actor_check CHECK (event_type NOT IN ('approved', 'dismissed') OR actor_type = 'user')"
        );
        DB::statement(
            "ALTER TABLE recommendation_review_events ADD CONSTRAINT recommendation_review_events_agent_actor_check CHECK (actor_type <> 'agent' OR event_type IN ('created', 'viewed'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_review_events');
    }
};
