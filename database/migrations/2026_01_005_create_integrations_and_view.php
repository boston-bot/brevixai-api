<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // -- QBO Integrations --
        Schema::create('qbo_integrations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('realm_id', 255)->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestampTz('token_expires_at')->nullable();
            $table->text('status')->default('active');
            $table->timestampTz('last_sync_at')->nullable();
            $table->text('sync_status')->nullable();
            $table->text('sync_error')->nullable();
            $table->timestampsTz();
        });

        // -- QBO Transactions --
        Schema::create('qbo_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('qbo_id', 255)->nullable();
            $table->string('realm_id', 255)->nullable();
            $table->date('transaction_date')->nullable();
            $table->text('vendor_name')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->text('type')->nullable();
            $table->text('status')->default('active');
            $table->jsonb('raw_payload')->nullable();
            $table->timestampTz('synced_at')->useCurrent();
        });

        // -- GnuCash Transactions --
        Schema::create('gnucash_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('gnucash_guid', 255)->nullable();
            $table->date('transaction_date')->nullable();
            $table->text('vendor_name')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->text('type')->nullable();
            $table->text('account_type')->nullable();
            $table->text('memo')->nullable();
            $table->text('source_file')->nullable();
            $table->jsonb('raw_payload')->nullable();
            $table->timestampTz('synced_at')->useCurrent();
        });

        // -- The all_transactions unified view --
        DB::statement("
            CREATE OR REPLACE VIEW all_transactions AS
            SELECT
                t.id,
                t.company_id,
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
                COALESCE(u.filename, 'File Upload') AS source_name
            FROM transactions t
            LEFT JOIN uploads u ON t.upload_id = u.id

            UNION ALL

            SELECT
                q.id,
                q.company_id,
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
                'QBO Sync'::TEXT AS source_name
            FROM qbo_transactions q

            UNION ALL

            SELECT
                g.id,
                g.company_id,
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
                COALESCE(g.source_file, 'GnuCash Import')::TEXT AS source_name
            FROM gnucash_transactions g;
        ");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS all_transactions');
        Schema::dropIfExists('gnucash_transactions');
        Schema::dropIfExists('qbo_transactions');
        Schema::dropIfExists('qbo_integrations');
    }
};
