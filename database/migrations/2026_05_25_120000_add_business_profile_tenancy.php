<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('legal_name', 255)->nullable();
            $table->string('industry', 100)->nullable();
            $table->string('entity_type', 100)->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status', 50)->default('active');
            $table->timestampsTz();

            $table->index(['company_id', 'status']);
            $table->unique(['company_id', 'name']);
        });

        Schema::create('workspace_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 50);
            $table->string('scope', 50)->default('workspace');
            $table->foreignUuid('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['company_id', 'user_id']);
            $table->index(['user_id', 'role']);
        });

        Schema::create('business_profile_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('business_profile_id')->constrained('business_profiles')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 50);
            $table->foreignUuid('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['business_profile_id', 'user_id']);
            $table->index(['user_id', 'role']);
        });

        $this->addBusinessProfileColumns();
        $this->backfillProfilesAndMemberships();
        $this->updateSubscriptionTierConstraint();
        $this->replaceAllTransactionsView();
    }

    public function down(): void
    {
        $this->restoreSubscriptionTierConstraint();
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP VIEW IF EXISTS all_transactions');
        }
        $this->dropBusinessProfileColumns();

        Schema::dropIfExists('business_profile_memberships');
        Schema::dropIfExists('workspace_memberships');
        Schema::dropIfExists('business_profiles');
    }

    private function addBusinessProfileColumns(): void
    {
        foreach ($this->businessContextTables() as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'business_profile_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->uuid('business_profile_id')->nullable()->index();
            });
        }
    }

    private function dropBusinessProfileColumns(): void
    {
        foreach (array_reverse($this->businessContextTables()) as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'business_profile_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('business_profile_id');
            });
        }
    }

    /** @return list<string> */
    private function businessContextTables(): array
    {
        return [
            'integrations',
            'qbo_integrations',
            'qbo_transactions',
            'gnucash_transactions',
            'uploads',
            'transactions',
            'budget_lines',
            'upload_inspections',
            'upload_mapping_versions',
            'upload_validation_runs',
            'upload_row_errors',
            'import_batches',
            'invoices',
            'alerts',
            'alert_groups',
            'audit_cases',
            'audit_case_events',
            'case_recommendations',
            'alert_recommendations',
            'recommendation_review_events',
            'investigation_evidence_items',
            'investigation_activity_events',
            'investigation_report_exports',
            'chat_sessions',
            'chat_messages',
            'chat_usage_daily',
            'rex_pending_actions',
            'agent_runs',
            'agent_action_approvals',
            'entity_graph_runs',
            'entity_graph_patterns',
            'control_definitions',
            'control_evaluations',
            'control_violations',
            'notification_configs',
        ];
    }

    private function backfillProfilesAndMemberships(): void
    {
        $now = now();

        $companies = DB::table('companies')->get(['id', 'name', 'industry', 'entity_type']);

        foreach ($companies as $company) {
            $profileId = (string) Str::uuid();

            DB::table('business_profiles')->insert([
                'id' => $profileId,
                'company_id' => $company->id,
                'name' => $company->name,
                'industry' => $company->industry ?? null,
                'entity_type' => $company->entity_type ?? null,
                'is_default' => true,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('users')
                ->where('company_id', $company->id)
                ->orderBy('id')
                ->each(function (object $user) use ($company, $now): void {
                    DB::table('workspace_memberships')->updateOrInsert(
                        ['company_id' => $company->id, 'user_id' => $user->id],
                        [
                            'id' => (string) Str::uuid(),
                            'role' => $user->role ?? 'owner',
                            'scope' => 'workspace',
                            'updated_at' => $now,
                            'created_at' => $now,
                        ],
                    );
                });

            foreach ($this->businessContextTables() as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'business_profile_id') || ! Schema::hasColumn($table, 'company_id')) {
                    continue;
                }

                DB::table($table)
                    ->where('company_id', $company->id)
                    ->whereNull('business_profile_id')
                    ->update(['business_profile_id' => $profileId]);
            }
        }
    }

    private function updateSubscriptionTierConstraint(): void
    {
        if (! Schema::hasTable('subscriptions') || DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE subscriptions DROP CONSTRAINT IF EXISTS subscriptions_tier_check');
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_tier_check CHECK (tier IN ('free', 'starter', 'growth', 'accounting', 'risk-advisory'))");
    }

    private function restoreSubscriptionTierConstraint(): void
    {
        if (! Schema::hasTable('subscriptions') || DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE subscriptions DROP CONSTRAINT IF EXISTS subscriptions_tier_check');
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_tier_check CHECK (tier IN ('starter', 'growth', 'accounting', 'risk-advisory'))");
    }

    private function replaceAllTransactionsView(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('transactions')) {
            return;
        }

        DB::statement('DROP VIEW IF EXISTS all_transactions');
        DB::statement("
            CREATE OR REPLACE VIEW all_transactions AS
            SELECT
                t.id,
                t.company_id,
                t.business_profile_id,
                t.date,
                t.vendor_customer,
                t.amount,
                t.type,
                t.anomaly_flag,
                t.anomaly_reason,
                t.department,
                t.category,
                t.payment_method,
                t.invoice_ref,
                t.memo,
                'csv'::TEXT AS source,
                'file_upload'::TEXT AS source_type,
                COALESCE(u.original_filename, u.filename, 'File Upload') AS source_name,
                t.upload_id,
                t.txn_id,
                t.raw_row
            FROM transactions t
            LEFT JOIN uploads u ON t.upload_id = u.id

            UNION ALL

            SELECT
                q.id,
                q.company_id,
                q.business_profile_id,
                q.transaction_date AS date,
                q.vendor_name AS vendor_customer,
                q.amount,
                q.type,
                FALSE AS anomaly_flag,
                NULL::TEXT AS anomaly_reason,
                NULL::TEXT AS department,
                NULL::TEXT AS category,
                NULL::TEXT AS payment_method,
                NULL::TEXT AS invoice_ref,
                NULL::TEXT AS memo,
                'qbo'::TEXT AS source,
                'quickbooks'::TEXT AS source_type,
                'QBO Sync'::TEXT AS source_name,
                NULL::UUID AS upload_id,
                q.qbo_id AS txn_id,
                q.raw_payload AS raw_row
            FROM qbo_transactions q

            UNION ALL

            SELECT
                g.id,
                g.company_id,
                g.business_profile_id,
                g.transaction_date AS date,
                g.vendor_name AS vendor_customer,
                g.amount,
                g.type,
                FALSE AS anomaly_flag,
                NULL::TEXT AS anomaly_reason,
                NULL::TEXT AS department,
                g.account_type AS category,
                NULL::TEXT AS payment_method,
                NULL::TEXT AS invoice_ref,
                g.memo,
                'gnucash'::TEXT AS source,
                'gnucash'::TEXT AS source_type,
                COALESCE(g.source_file, 'GnuCash Import')::TEXT AS source_name,
                NULL::UUID AS upload_id,
                g.gnucash_guid AS txn_id,
                g.raw_payload AS raw_row
            FROM gnucash_transactions g;
        ");
    }
};
