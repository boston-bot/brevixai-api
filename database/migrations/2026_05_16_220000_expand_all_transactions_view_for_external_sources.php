<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
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

    public function down(): void
    {
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
};
