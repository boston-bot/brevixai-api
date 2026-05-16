<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add alert_preferences to companies
        Schema::table('companies', function (Blueprint $table) {
            $table->jsonb('alert_preferences')->default('{}');
        });

        // -- Rule definitions (configurable per company) --
        Schema::create('rule_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->text('rule_key');
            $table->text('display_name');
            $table->text('description')->nullable();
            $table->text('severity')->default('warning'); // critical, warning, info
            $table->boolean('enabled')->default(true);
            $table->jsonb('config')->default('{}');
            $table->timestampsTz();

            $table->unique(['company_id', 'rule_key']);
        });

        // -- Alert Groups (Clusters) --
        Schema::create('alert_groups', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->text('title');
            $table->integer('alert_count')->default(0);
            $table->text('max_severity')->default('info');
            $table->decimal('total_impact', 15, 2)->default(0);
            $table->timestampTz('created_at')->useCurrent();
        });

        // -- Generated alerts --
        Schema::create('alerts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('group_id')->nullable()->constrained('alert_groups')->nullOnDelete();
            $table->text('rule_key');
            $table->text('severity');
            $table->text('title');
            $table->text('detail')->nullable();
            $table->jsonb('evidence')->nullable(); // { transactionIds: [], metadata: {} }
            $table->text('status')->default('open'); // open, reviewed, dismissed
            $table->integer('priority_score')->default(50);
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();
        });

        // -- Indexes --
        DB::statement('CREATE INDEX idx_rule_defs_company ON rule_definitions(company_id)');
        DB::statement('CREATE INDEX idx_alert_groups_company ON alert_groups(company_id)');
        DB::statement('CREATE INDEX idx_alerts_company ON alerts(company_id)');
        DB::statement('CREATE INDEX idx_alerts_status ON alerts(company_id, status)');
        DB::statement('CREATE INDEX idx_alerts_rule ON alerts(company_id, rule_key)');
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('alert_groups');
        Schema::dropIfExists('rule_definitions');
        
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('alert_preferences');
        });
    }
};
