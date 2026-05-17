<?php

namespace Database\Seeders\FraudScenarioSeeders;

use App\Models\Alert;
use App\Models\Company;
use App\Models\ReconciliationDiscrepancy;
use App\Models\ReconciliationResult;
use App\Models\Transaction;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class Phase1AgentFraudScenarioSeeder extends Seeder
{
    public const COMPANY_ID = '11111111-1111-4111-8111-111111111111';
    public const USER_ID = '22222222-2222-4222-8222-222222222222';
    public const UPLOAD_ID = '33333333-3333-4333-8333-333333333333';
    public const RECON_RUN_ID = '44444444-4444-4444-8444-444444444444';
    public const RECON_DISCREPANCY_ID = '55555555-5555-4555-8555-555555555555';

    public function run(): void
    {
        $company = Company::updateOrCreate(
            ['id' => self::COMPANY_ID],
            [
                'name' => 'Brevix Phase 1 Fraud Fixture Co',
                'industry' => 'Retail',
                'size' => '11-50',
                'has_completed_onboarding' => true,
            ]
        );

        $user = User::updateOrCreate(
            ['id' => self::USER_ID],
            [
                'company_id' => $company->id,
                'email' => 'phase1-agent-fixture@example.com',
                'password_hash' => Hash::make('password'),
                'first_name' => 'Phase',
                'last_name' => 'Fixture',
                'role' => 'owner',
                'is_verified' => true,
            ]
        );

        $upload = Upload::updateOrCreate(
            ['id' => self::UPLOAD_ID],
            [
                'company_id' => $company->id,
                'uploaded_by' => $user->id,
                'filename' => 'phase-1-agent-fraud-scenarios.csv',
                'original_filename' => 'phase-1-agent-fraud-scenarios.csv',
                'status' => 'completed',
                'row_count' => 12,
                'import_type' => 'transaction_ledger',
                'uploaded_at' => now(),
            ]
        );

        $transactions = $this->seedTransactions($company->id, $upload->id);
        $discrepancy = $this->seedReconciliationMismatch($company->id, $transactions);
        $this->seedAlerts($company->id, $transactions, $discrepancy->id);
    }

    private function seedTransactions(string $companyId, string $uploadId): array
    {
        $rows = [
            'duplicate_a' => ['66666666-0001-4666-8666-666666666666', '2026-05-03', 'Acme Supplies', 1820.00, 'INV-1001', 'Office fixtures'],
            'duplicate_b' => ['66666666-0002-4666-8666-666666666666', '2026-05-04', 'Acme Supplies', 1820.00, 'INV-1001', 'Duplicate invoice candidate'],
            'split_a' => ['66666666-0003-4666-8666-666666666666', '2026-05-06', 'Northstar Consulting', 4900.00, 'NS-201', 'Phase 1 payment'],
            'split_b' => ['66666666-0004-4666-8666-666666666666', '2026-05-07', 'Northstar Consulting', 4850.00, 'NS-202', 'Phase 1 follow-up payment'],
            'split_c' => ['66666666-0005-4666-8666-666666666666', '2026-05-08', 'Northstar Consulting', 4950.00, 'NS-203', 'Phase 1 final payment'],
            'new_vendor' => ['66666666-0006-4666-8666-666666666666', '2026-05-02', 'Brightline Labs', 3750.00, 'BL-001', 'New vendor immediate payment'],
            'round_a' => ['66666666-0007-4666-8666-666666666666', '2026-05-09', 'Roundhouse Services', 3000.00, 'RH-10', 'Round-dollar services'],
            'round_b' => ['66666666-0008-4666-8666-666666666666', '2026-05-10', 'Roundhouse Services', 5000.00, 'RH-11', 'Round-dollar services'],
            'concentration_a' => ['66666666-0009-4666-8666-666666666666', '2026-05-11', 'Mega Vendor LLC', 24000.00, 'MV-1', 'Large monthly payment'],
            'concentration_b' => ['66666666-0010-4666-8666-666666666666', '2026-05-12', 'Mega Vendor LLC', 21000.00, 'MV-2', 'Large monthly payment'],
            'recon_bank' => ['66666666-0011-4666-8666-666666666666', '2026-05-14', 'Bank Only Vendor', 1320.44, 'BANK-1', 'Bank-side item without ledger match'],
            'clean' => ['66666666-0012-4666-8666-666666666666', '2026-05-15', 'Clean Vendor', 750.00, 'CV-1', 'Routine payment'],
        ];

        $transactions = [];
        foreach ($rows as $key => [$id, $date, $vendor, $amount, $invoiceRef, $memo]) {
            $transactions[$key] = Transaction::updateOrCreate(
                ['id' => $id],
                [
                    'upload_id' => $uploadId,
                    'company_id' => $companyId,
                    'txn_id' => strtoupper($key),
                    'date' => $date,
                    'vendor_customer' => $vendor,
                    'type' => 'expense',
                    'category' => 'Operations',
                    'payment_method' => 'ach',
                    'amount' => $amount,
                    'invoice_ref' => $invoiceRef,
                    'memo' => $memo,
                    'anomaly_flag' => $key !== 'clean',
                    'anomaly_reason' => $key === 'clean' ? null : 'Seeded Phase 1 fraud scenario',
                    'raw_row' => ['fixture_key' => $key],
                ]
            );
        }

        return $transactions;
    }

    private function seedReconciliationMismatch(string $companyId, array $transactions): ReconciliationDiscrepancy
    {
        ReconciliationResult::updateOrCreate(
            ['id' => self::RECON_RUN_ID],
            [
                'company_id' => $companyId,
                'period_start' => '2026-05-01',
                'period_end' => '2026-05-31',
                'total_mismatches' => 1,
                'total_impact' => 1320.44,
                'status' => 'completed',
                'results' => [
                    'bankTransactionCount' => 12,
                    'ledgerTransactionCount' => 11,
                    'matchedPercentage' => 91,
                    'unresolvedCount' => 1,
                    'unresolvedImpact' => 1320.44,
                    'highRiskCount' => 1,
                ],
            ]
        );

        return ReconciliationDiscrepancy::updateOrCreate(
            ['id' => self::RECON_DISCREPANCY_ID],
            [
                'company_id' => $companyId,
                'run_id' => self::RECON_RUN_ID,
                'bank_txn_id' => $transactions['recon_bank']->id,
                'ledger_txn_id' => null,
                'amount' => 1320.44,
                'category' => 'missing_from_books',
                'reason_code' => 'bank_transaction_without_ledger_match',
                'confidence_score' => 0.91,
                'risk_level' => 'high',
                'recommended_action' => 'escalate_for_review',
                'recommendation_explanation' => 'Bank-side transaction has no matching ledger entry.',
                'status' => 'new',
                'metadata' => ['fixture_key' => 'reconciliation_mismatch'],
            ]
        );
    }

    private function seedAlerts(string $companyId, array $transactions, string $discrepancyId): void
    {
        $alerts = [
            ['77777777-0001-4777-8777-777777777777', 'duplicate_invoice', 'critical', 'Possible duplicate invoice', 'Two payments share invoice INV-1001 for Acme Supplies.', 95, ['duplicate_a', 'duplicate_b'], []],
            ['77777777-0002-4777-8777-777777777777', 'split_payment_threshold', 'critical', 'Possible split payments under threshold', 'Three payments to Northstar Consulting are just below the approval threshold.', 92, ['split_a', 'split_b', 'split_c'], []],
            ['77777777-0003-4777-8777-777777777777', 'new_vendor_immediate_payment', 'warning', 'New vendor paid immediately', 'Brightline Labs was paid shortly after first appearing in the ledger.', 82, ['new_vendor'], []],
            ['77777777-0004-4777-8777-777777777777', 'round_dollar_payments', 'warning', 'Round-dollar payment pattern', 'Roundhouse Services has repeated round-dollar payments.', 74, ['round_a', 'round_b'], []],
            ['77777777-0005-4777-8777-777777777777', 'vendor_concentration', 'warning', 'Vendor concentration risk', 'Mega Vendor LLC represents a large share of seeded monthly spend.', 70, ['concentration_a', 'concentration_b'], []],
            ['77777777-0006-4777-8777-777777777777', 'reconciliation_mismatch', 'warning', 'Reconciliation mismatch', 'Bank-side transaction has no matching ledger entry.', 68, ['recon_bank'], [$discrepancyId]],
        ];

        foreach ($alerts as [$id, $ruleKey, $severity, $title, $detail, $priority, $transactionKeys, $discrepancyIds]) {
            Alert::updateOrCreate(
                ['id' => $id],
                [
                    'company_id' => $companyId,
                    'rule_key' => $ruleKey,
                    'severity' => $severity,
                    'title' => $title,
                    'detail' => $detail,
                    'evidence' => [
                        'transactionIds' => array_map(fn (string $key): string => $transactions[$key]->id, $transactionKeys),
                        'reconciliationDiscrepancyIds' => $discrepancyIds ?? [],
                        'fixture' => 'phase_1_agent_fraud_scenarios',
                    ],
                    'status' => 'open',
                    'priority_score' => $priority,
                ]
            );
        }
    }
}
