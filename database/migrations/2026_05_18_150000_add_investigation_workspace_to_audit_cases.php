<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_cases', function (Blueprint $table): void {
            $table->text('investigation_status')->default('open')->after('status');
            $table->foreignUuid('investigation_assigned_user_id')
                ->nullable()
                ->after('investigation_status')
                ->constrained('users')
                ->nullOnDelete();
            $table->text('investigation_priority')->default('medium')->after('investigation_assigned_user_id');
            $table->text('investigation_summary')->nullable()->after('investigation_priority');
            $table->text('investigation_notes')->nullable()->after('investigation_summary');
            $table->timestampTz('last_activity_at')->nullable()->after('investigation_notes');
            $table->jsonb('investigation_metadata')->nullable()->after('last_activity_at');
        });

        DB::statement(
            "ALTER TABLE audit_cases ADD CONSTRAINT audit_cases_investigation_status_check
             CHECK (investigation_status IN ('open', 'in_review', 'escalated', 'resolved', 'archived'))"
        );

        DB::statement(
            "ALTER TABLE audit_cases ADD CONSTRAINT audit_cases_investigation_priority_check
             CHECK (investigation_priority IN ('critical', 'high', 'medium', 'low'))"
        );

        Schema::create('investigation_activity_events', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('audit_case_id')->constrained('audit_cases')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->text('event_type');
            $table->text('actor_type'); // user|system|agent
            $table->uuid('actor_id')->nullable();
            $table->text('event_summary');
            $table->jsonb('event_metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement(
            "ALTER TABLE investigation_activity_events ADD CONSTRAINT investigation_activity_events_actor_type_check
             CHECK (actor_type IN ('user', 'system', 'agent'))"
        );

        DB::statement('CREATE INDEX idx_investigation_activity_events_case ON investigation_activity_events (audit_case_id, created_at ASC)');
        DB::statement('CREATE INDEX idx_investigation_activity_events_company ON investigation_activity_events (company_id, created_at DESC)');
        DB::statement('CREATE INDEX idx_audit_cases_investigation_status ON audit_cases (company_id, investigation_status)');
        DB::statement('CREATE INDEX idx_audit_cases_investigation_priority ON audit_cases (company_id, investigation_priority)');
        DB::statement('CREATE INDEX idx_audit_cases_investigation_assigned ON audit_cases (company_id, investigation_assigned_user_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_activity_events');

        Schema::table('audit_cases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('investigation_assigned_user_id');
            $table->dropColumn([
                'investigation_status',
                'investigation_priority',
                'investigation_summary',
                'investigation_notes',
                'last_activity_at',
                'investigation_metadata',
            ]);
        });
    }
};
