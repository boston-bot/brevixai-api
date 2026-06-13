<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\UploadRowError;
use App\Services\Agents\ReconciliationRiskScoringService;
use App\Services\Agents\VendorRiskScoringService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SourceFindingAdapterService
{
    private const DEFAULT_LIMIT = 50;

    /** @var list<string> */
    public const SOURCE_MODULES = [
        'reconciliation',
        'transactions',
        'vendor_risk',
        'tax_notices',
        'upload_validation',
    ];

    public function __construct(
        private readonly ReconciliationRiskScoringService $reconciliationRiskService,
        private readonly VendorRiskScoringService $vendorRiskService,
    ) {}

    /**
     * Adapt current source analyzers and source tables into the canonical
     * investigation Finding/EvidenceItem contract without persisting new rows.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function list(string $companyId, array $filters = [], ?string $businessProfileId = null): array
    {
        $sourceModule = isset($filters['source_module']) ? (string) $filters['source_module'] : null;
        $limit = min(max((int) ($filters['limit'] ?? self::DEFAULT_LIMIT), 1), 200);

        $sources = $sourceModule && in_array($sourceModule, self::SOURCE_MODULES, true)
            ? [$sourceModule]
            : ['reconciliation', 'transactions', 'vendor_risk', 'upload_validation'];

        $findings = [];
        $evidenceItems = [];
        $suggestedRecords = [];

        foreach ($sources as $source) {
            $result = match ($source) {
                'reconciliation' => $this->reconciliationFindings($companyId, $businessProfileId),
                'transactions' => $this->transactionFindings($companyId, $businessProfileId, $limit),
                'vendor_risk' => $this->vendorRiskFindings($companyId, $businessProfileId),
                'upload_validation' => $this->uploadValidationFindings($companyId, $businessProfileId, $limit),
                default => ['findings' => [], 'evidenceItems' => [], 'suggestedRecords' => []],
            };

            $findings = array_merge($findings, $result['findings']);
            $evidenceItems = array_merge($evidenceItems, $result['evidenceItems']);
            $suggestedRecords = array_merge($suggestedRecords, $result['suggestedRecords']);
        }

        $findings = $this->applyFindingFilters($findings, $filters);
        $findings = array_slice($findings, 0, $limit);
        $findingIds = array_flip(array_column($findings, 'id'));

        $evidenceItems = array_values(array_filter(
            $evidenceItems,
            fn (array $item): bool => isset($findingIds[(string) ($item['findingId'] ?? '')])
        ));
        $suggestedRecords = array_values(array_filter(
            $suggestedRecords,
            fn (array $item): bool => isset($findingIds[(string) ($item['findingId'] ?? '')])
        ));

        return [
            'contractVersion' => InvestigationPlatformContractService::CONTRACT_VERSION,
            'filters' => [
                'sourceModule' => $sourceModule,
                'category' => $filters['category'] ?? null,
                'status' => $filters['status'] ?? null,
                'limit' => $limit,
            ],
            'findings' => $findings,
            'evidenceItems' => $evidenceItems,
            'suggestedRecords' => $suggestedRecords,
        ];
    }

    /**
     * @return array{finding: array<string, mixed>, evidenceItems: list<array<string, mixed>>, suggestedRecords: list<array<string, mixed>>}
     */
    public function taxNoticeFinding(array $interpretation, string $noticeText, ?string $investigationId = null): array
    {
        $noticeType = (string) ($interpretation['notice_type'] ?? 'Unknown');
        $noticeHash = hash('sha256', trim($noticeText));
        $findingId = "finding:tax_notice:{$noticeHash}";
        $evidenceId = "evidence:tax_notice_text:{$noticeHash}";

        $evidenceItems = [[
            'id' => $evidenceId,
            'investigationId' => $investigationId,
            'findingId' => $findingId,
            'evidenceType' => 'tax_notice',
            'sourceType' => 'tax_notice_text',
            'sourceId' => null,
            'sourceRecordId' => $noticeHash,
            'title' => "{$noticeType} notice text",
            'summary' => 'Source notice text supplied for procedural interpretation.',
            'citationLabel' => "tax_notice_text:{$noticeHash}",
            'sourceRowRange' => null,
            'fileName' => null,
            'storageKey' => null,
            'hash' => $noticeHash,
            'addedByActorType' => 'system',
            'addedByActorId' => null,
            'createdAt' => null,
            'metadata' => [
                'notice_type' => $noticeType,
                'deadline_days' => $interpretation['deadline_days'] ?? null,
                'raw_notice_text_returned' => false,
            ],
        ]];

        $suggestedRecords = [[
            'id' => "suggested-record:tax_notice:{$noticeHash}:supporting_records",
            'investigationId' => $investigationId,
            'findingId' => $findingId,
            'recordType' => 'notice_supporting_records',
            'label' => 'Records for the notice period',
            'reason' => 'Ledger, return, payment, payroll, and correspondence records can clarify what the notice is asking about.',
            'priority' => 'recommended',
            'status' => 'requested',
            'satisfyingEvidenceItemId' => null,
        ]];

        $finding = $this->finding([
            'id' => $findingId,
            'investigationId' => $investigationId,
            'category' => 'tax',
            'sourceModule' => 'tax_notices',
            'sourceRecordType' => 'tax_notice_interpretation',
            'sourceRecordId' => $noticeHash,
            'title' => $noticeType === 'Unknown'
                ? 'Tax notice needs review'
                : "Review {$noticeType} tax notice",
            'summary' => (string) ($interpretation['summary'] ?? ''),
            'detail' => (string) ($interpretation['deadline_description'] ?? ''),
            'severity' => $this->severityFromRiskLevel((string) ($interpretation['risk_level'] ?? 'medium')),
            'confidence' => $noticeType === 'Unknown' ? 'low' : 'medium',
            'reasonCode' => $noticeType,
            'evidenceRefs' => $evidenceItems,
            'suggestedRecords' => $suggestedRecords,
            'recommendedAction' => [
                'key' => 'review_tax_notice_with_professional',
                'label' => 'Review notice and records',
                'explanation' => 'Brevix can organize notice facts and supporting records, but a qualified professional should advise on the response.',
                'requiresConfirmation' => true,
            ],
            'limitations' => [
                'This is a procedural interpretation, not legal, tax, accounting, or CPA advice.',
                'The source notice text is represented by hash and summary; raw notice text is not embedded in the normalized finding.',
            ],
        ]);

        return [
            'finding' => $finding,
            'evidenceItems' => $evidenceItems,
            'suggestedRecords' => $suggestedRecords,
        ];
    }

    /**
     * @return array{findings: list<array<string, mixed>>, evidenceItems: list<array<string, mixed>>, suggestedRecords: list<array<string, mixed>>}
     */
    private function reconciliationFindings(string $companyId, ?string $businessProfileId): array
    {
        if (! Schema::hasTable('reconciliation_discrepancies')) {
            return ['findings' => [], 'evidenceItems' => [], 'suggestedRecords' => []];
        }

        $result = $this->reconciliationRiskService->scoreReconciliation($companyId, $businessProfileId);
        $findings = [];
        $evidenceItems = [];
        $suggestedRecords = [];

        foreach (($result['triggered_rules'] ?? []) as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $ruleKey = (string) ($rule['rule_key'] ?? 'reconciliation_issue');
            $findingId = "finding:reconciliation:{$ruleKey}";
            $evidenceForRule = $this->reconciliationEvidenceForRule(
                $companyId,
                $businessProfileId,
                $findingId,
                $ruleKey,
                $result['supporting_evidence'][$ruleKey] ?? [],
            );
            $suggested = $this->suggestedRecord(
                id: "suggested-record:reconciliation:{$ruleKey}:supporting_docs",
                findingId: $findingId,
                recordType: 'reconciliation_support',
                label: 'Reconciliation support for affected rows',
                reason: 'Bank, ledger, or reconciliation reports can clarify the discrepancy before reviewer approval.',
                priority: 'recommended',
            );

            $evidenceItems = array_merge($evidenceItems, $evidenceForRule);
            $suggestedRecords[] = $suggested;
            $findings[] = $this->finding([
                'id' => $findingId,
                'category' => 'reconciliation',
                'sourceModule' => 'reconciliation',
                'sourceRecordType' => 'reconciliation_rule',
                'sourceRecordId' => $ruleKey,
                'title' => (string) ($rule['name'] ?? 'Reconciliation finding'),
                'summary' => (string) ($rule['explanation'] ?? ''),
                'detail' => (string) ($rule['explanation'] ?? ''),
                'severity' => $this->severityFromRiskLevel((string) ($result['risk_level'] ?? 'medium')),
                'confidence' => $this->confidenceFromCount(count($evidenceForRule)),
                'reasonCode' => $ruleKey,
                'evidenceRefs' => $evidenceForRule,
                'suggestedRecords' => [$suggested],
                'recommendedAction' => [
                    'key' => 'review_reconciliation_discrepancies',
                    'label' => 'Review discrepancies',
                    'explanation' => (string) ($result['recommended_next_action'] ?? 'Review reconciliation evidence.'),
                    'requiresConfirmation' => true,
                ],
                'limitations' => $evidenceForRule === []
                    ? ['The rule triggered, but no row-level evidence was available in the current response.']
                    : [],
            ]);
        }

        return [
            'findings' => $findings,
            'evidenceItems' => $evidenceItems,
            'suggestedRecords' => $suggestedRecords,
        ];
    }

    /**
     * @return array{findings: list<array<string, mixed>>, evidenceItems: list<array<string, mixed>>, suggestedRecords: list<array<string, mixed>>}
     */
    private function transactionFindings(string $companyId, ?string $businessProfileId, int $limit): array
    {
        if (! Schema::hasTable('transactions')) {
            return ['findings' => [], 'evidenceItems' => [], 'suggestedRecords' => []];
        }

        $transactions = $this->transactionQuery($companyId, $businessProfileId)
            ->where(function (Builder $query): void {
                $query->where('anomaly_flag', true)
                    ->orWhereNotNull('anomaly_reason');
            })
            ->orderByDesc('date')
            ->limit($limit)
            ->get();

        $findings = [];
        $evidenceItems = [];
        $suggestedRecords = [];

        foreach ($transactions as $transaction) {
            $findingId = "finding:transaction_anomaly:{$transaction->id}";
            $evidence = $this->transactionEvidence($transaction, $findingId);
            $suggested = $this->suggestedRecord(
                id: "suggested-record:transaction:{$transaction->id}:support",
                findingId: $findingId,
                recordType: 'transaction_support',
                label: 'Transaction support',
                reason: 'Receipt, invoice, approval, or bank detail can help a reviewer resolve this transaction anomaly.',
                priority: 'recommended',
            );

            $evidenceItems[] = $evidence;
            $suggestedRecords[] = $suggested;
            $findings[] = $this->finding([
                'id' => $findingId,
                'category' => $this->categoryFromTransaction($transaction),
                'sourceModule' => 'transactions',
                'sourceRecordType' => 'transaction',
                'sourceRecordId' => (string) $transaction->id,
                'title' => 'Review transaction anomaly',
                'summary' => (string) ($transaction->anomaly_reason ?: 'Transaction was flagged for review.'),
                'detail' => (string) ($transaction->anomaly_reason ?: 'Transaction was flagged for review.'),
                'severity' => 'warning',
                'confidence' => $transaction->anomaly_flag ? 'medium' : 'low',
                'reasonCode' => 'transaction_anomaly',
                'evidenceRefs' => [$evidence],
                'suggestedRecords' => [$suggested],
                'recommendedAction' => [
                    'key' => 'review_transaction_support',
                    'label' => 'Review support',
                    'explanation' => 'Confirm the transaction against source records before marking it reviewed.',
                    'requiresConfirmation' => true,
                ],
                'limitations' => $transaction->upload_id ? [] : ['No upload provenance is linked to this transaction.'],
                'createdAt' => $this->dateString($transaction->date),
                'updatedAt' => null,
            ]);
        }

        return [
            'findings' => $findings,
            'evidenceItems' => $evidenceItems,
            'suggestedRecords' => $suggestedRecords,
        ];
    }

    /**
     * @return array{findings: list<array<string, mixed>>, evidenceItems: list<array<string, mixed>>, suggestedRecords: list<array<string, mixed>>}
     */
    private function vendorRiskFindings(string $companyId, ?string $businessProfileId): array
    {
        if (! Schema::hasTable('transactions')) {
            return ['findings' => [], 'evidenceItems' => [], 'suggestedRecords' => []];
        }

        $vendorScores = $this->vendorRiskService->scoreAllVendors($companyId, $businessProfileId);
        $findings = [];
        $evidenceItems = [];
        $suggestedRecords = [];

        foreach ($vendorScores as $vendorScore) {
            if (! is_array($vendorScore) || (int) ($vendorScore['vendor_risk_score'] ?? 0) < 40) {
                continue;
            }

            $vendorName = (string) ($vendorScore['vendor_name'] ?? 'Unknown vendor');

            foreach (($vendorScore['triggered_rules'] ?? []) as $rule) {
                if (! is_array($rule)) {
                    continue;
                }

                $ruleKey = (string) ($rule['rule_key'] ?? 'vendor_risk');
                $vendorKey = substr(hash('sha256', strtolower($vendorName)), 0, 16);
                $findingId = "finding:vendor_risk:{$vendorKey}:{$ruleKey}";
                $evidenceForRule = $this->vendorEvidenceForRule(
                    $companyId,
                    $businessProfileId,
                    $findingId,
                    $vendorName,
                    $ruleKey,
                    $vendorScore['supporting_evidence'][$ruleKey] ?? [],
                );
                $suggested = $this->suggestedRecord(
                    id: "suggested-record:vendor:{$vendorKey}:{$ruleKey}:onboarding",
                    findingId: $findingId,
                    recordType: 'vendor_support',
                    label: 'Vendor support records',
                    reason: 'Vendor onboarding, approval, invoice, and payment support can help a reviewer resolve this signal.',
                    priority: 'recommended',
                );

                $evidenceItems = array_merge($evidenceItems, $evidenceForRule);
                $suggestedRecords[] = $suggested;
                $findings[] = $this->finding([
                    'id' => $findingId,
                    'category' => 'vendor_payments',
                    'sourceModule' => 'vendor_risk',
                    'sourceRecordType' => 'vendor_risk_rule',
                    'sourceRecordId' => "{$vendorName}:{$ruleKey}",
                    'title' => (string) ($rule['name'] ?? 'Vendor payment finding'),
                    'summary' => (string) ($rule['explanation'] ?? ''),
                    'detail' => (string) ($rule['explanation'] ?? ''),
                    'severity' => $this->severityFromRiskLevel((string) ($vendorScore['risk_level'] ?? 'medium')),
                    'confidence' => $this->confidenceFromCount(count($evidenceForRule)),
                    'reasonCode' => $ruleKey,
                    'evidenceRefs' => $evidenceForRule,
                    'suggestedRecords' => [$suggested],
                    'recommendedAction' => [
                        'key' => 'review_vendor_payment_signal',
                        'label' => 'Review vendor signal',
                        'explanation' => (string) ($vendorScore['recommended_next_action'] ?? 'Review vendor evidence.'),
                        'requiresConfirmation' => true,
                    ],
                    'limitations' => $evidenceForRule === []
                        ? ['Vendor risk rule has aggregate support but no transaction-level citation in the current data.']
                        : [],
                ]);
            }
        }

        return [
            'findings' => $findings,
            'evidenceItems' => $evidenceItems,
            'suggestedRecords' => $suggestedRecords,
        ];
    }

    /**
     * @return array{findings: list<array<string, mixed>>, evidenceItems: list<array<string, mixed>>, suggestedRecords: list<array<string, mixed>>}
     */
    private function uploadValidationFindings(string $companyId, ?string $businessProfileId, int $limit): array
    {
        if (! Schema::hasTable('upload_row_errors')) {
            return ['findings' => [], 'evidenceItems' => [], 'suggestedRecords' => []];
        }

        $errors = UploadRowError::query()
            ->where('company_id', $companyId)
            ->when(
                $businessProfileId && Schema::hasColumn('upload_row_errors', 'business_profile_id'),
                fn (Builder $query) => $query->where('business_profile_id', $businessProfileId),
            )
            ->orderBy('source_row_number')
            ->limit($limit)
            ->get();

        $findings = [];
        $evidenceItems = [];
        $suggestedRecords = [];

        foreach ($errors as $error) {
            $findingId = "finding:upload_validation:{$error->id}";
            $evidence = [
                'id' => "evidence:upload_row_error:{$error->id}",
                'investigationId' => null,
                'findingId' => $findingId,
                'evidenceType' => 'source_row',
                'sourceType' => 'upload_row_error',
                'sourceId' => (string) $error->upload_id,
                'sourceRecordId' => (string) $error->id,
                'title' => "Upload row {$error->source_row_number}",
                'summary' => (string) $error->message,
                'citationLabel' => $this->rowCitationLabel($error->upload_id, $error->source_sheet_name, $error->source_row_number),
                'sourceRowRange' => $error->source_row_number ? (string) $error->source_row_number : null,
                'fileName' => null,
                'storageKey' => null,
                'hash' => null,
                'addedByActorType' => 'system',
                'addedByActorId' => null,
                'createdAt' => $this->dateString($error->created_at),
                'metadata' => [
                    'canonical_field_key' => $error->canonical_field_key,
                    'error_code' => $error->error_code,
                    'raw_value_returned' => false,
                ],
            ];
            $suggested = $this->suggestedRecord(
                id: "suggested-record:upload_validation:{$error->id}:corrected_row",
                findingId: $findingId,
                recordType: 'corrected_source_row',
                label: 'Corrected source row or mapping',
                reason: 'A corrected value or confirmed mapping is needed before this row can be trusted as evidence.',
                priority: $error->severity === 'blocking' ? 'required' : 'recommended',
            );

            $evidenceItems[] = $evidence;
            $suggestedRecords[] = $suggested;
            $findings[] = $this->finding([
                'id' => $findingId,
                'category' => 'unsure',
                'sourceModule' => 'upload_validation',
                'sourceRecordType' => 'upload_row_error',
                'sourceRecordId' => (string) $error->id,
                'title' => 'Review upload row issue',
                'summary' => (string) $error->message,
                'detail' => (string) $error->message,
                'severity' => $error->severity === 'blocking' ? 'critical' : 'warning',
                'confidence' => 'high',
                'reasonCode' => (string) $error->error_code,
                'evidenceRefs' => [$evidence],
                'suggestedRecords' => [$suggested],
                'recommendedAction' => [
                    'key' => 'correct_upload_row',
                    'label' => 'Correct source row',
                    'explanation' => 'Resolve the upload validation issue before relying on this row in an investigation package.',
                    'requiresConfirmation' => true,
                ],
                'limitations' => ['Raw source-row values are not embedded in normalized findings.'],
                'createdAt' => $this->dateString($error->created_at),
                'updatedAt' => null,
            ]);
        }

        return [
            'findings' => $findings,
            'evidenceItems' => $evidenceItems,
            'suggestedRecords' => $suggestedRecords,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function finding(array $source): array
    {
        return [
            'id' => (string) $source['id'],
            'investigationId' => $source['investigationId'] ?? null,
            'category' => $this->category((string) ($source['category'] ?? 'unsure')),
            'sourceModule' => (string) ($source['sourceModule'] ?? 'unknown'),
            'sourceRecordType' => (string) ($source['sourceRecordType'] ?? 'unknown'),
            'sourceRecordId' => (string) ($source['sourceRecordId'] ?? ''),
            'title' => (string) ($source['title'] ?? 'Review finding'),
            'summary' => (string) ($source['summary'] ?? ''),
            'detail' => (string) ($source['detail'] ?? ''),
            'severity' => $this->severity((string) ($source['severity'] ?? 'warning')),
            'confidence' => $this->confidence($source['confidence'] ?? null),
            'reasonCode' => $source['reasonCode'] ?? null,
            'status' => (string) ($source['status'] ?? 'new'),
            'evidenceRefs' => is_array($source['evidenceRefs'] ?? null) ? $source['evidenceRefs'] : [],
            'suggestedRecords' => is_array($source['suggestedRecords'] ?? null) ? $source['suggestedRecords'] : [],
            'recommendedAction' => $source['recommendedAction'] ?? null,
            'reviewerStatus' => $source['reviewerStatus'] ?? 'pending',
            'createdAt' => $source['createdAt'] ?? null,
            'updatedAt' => $source['updatedAt'] ?? null,
            'limitations' => is_array($source['limitations'] ?? null) ? $source['limitations'] : [],
        ];
    }

    private function suggestedRecord(
        string $id,
        string $findingId,
        string $recordType,
        string $label,
        string $reason,
        string $priority,
    ): array {
        return [
            'id' => $id,
            'investigationId' => null,
            'findingId' => $findingId,
            'recordType' => $recordType,
            'label' => $label,
            'reason' => $reason,
            'priority' => in_array($priority, ['required', 'recommended', 'optional'], true) ? $priority : 'recommended',
            'status' => 'requested',
            'satisfyingEvidenceItemId' => null,
        ];
    }

    /**
     * @param array<string, mixed> $support
     * @return list<array<string, mixed>>
     */
    private function reconciliationEvidenceForRule(
        string $companyId,
        ?string $businessProfileId,
        string $findingId,
        string $ruleKey,
        mixed $support,
    ): array {
        $items = [];
        $support = is_array($support) ? $support : [];

        foreach (['discrepancies', 'items'] as $key) {
            foreach (($support[$key] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $discrepancyId = (string) ($row['id'] ?? '');
                if ($discrepancyId === '') {
                    continue;
                }

                $items[] = [
                    'id' => "evidence:reconciliation_discrepancy:{$discrepancyId}",
                    'investigationId' => null,
                    'findingId' => $findingId,
                    'evidenceType' => 'reconciliation_discrepancy',
                    'sourceType' => 'reconciliation_discrepancy',
                    'sourceId' => $row['run_id'] ?? null,
                    'sourceRecordId' => $discrepancyId,
                    'title' => (string) ($row['reason_code'] ?? $ruleKey),
                    'summary' => $this->amountSummary($row['amount'] ?? null, $row['category'] ?? null),
                    'citationLabel' => "reconciliation_discrepancy:{$discrepancyId}",
                    'sourceRowRange' => null,
                    'fileName' => null,
                    'storageKey' => null,
                    'hash' => null,
                    'addedByActorType' => 'system',
                    'addedByActorId' => null,
                    'createdAt' => $row['created_at'] ?? null,
                    'metadata' => [
                        'risk_level' => $row['risk_level'] ?? null,
                        'status' => $row['status'] ?? null,
                        'bank_txn_id' => $row['bank_txn_id'] ?? null,
                        'ledger_txn_id' => $row['ledger_txn_id'] ?? null,
                    ],
                ];

                foreach (['bank_txn_id', 'ledger_txn_id'] as $transactionKey) {
                    $transactionId = $row[$transactionKey] ?? null;
                    if (! is_string($transactionId) || $transactionId === '') {
                        continue;
                    }

                    $transaction = $this->findTransaction($companyId, $businessProfileId, $transactionId);
                    if ($transaction) {
                        $items[] = $this->transactionEvidence($transaction, $findingId, "reconciliation_{$transactionKey}");
                    }
                }
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $support
     * @return list<array<string, mixed>>
     */
    private function vendorEvidenceForRule(
        string $companyId,
        ?string $businessProfileId,
        string $findingId,
        string $vendorName,
        string $ruleKey,
        mixed $support,
    ): array {
        $support = is_array($support) ? $support : [];
        $items = [];
        $transactionIds = $this->extractTransactionIds($support);

        foreach ($transactionIds as $transactionId) {
            $transaction = $this->findTransaction($companyId, $businessProfileId, $transactionId);
            if ($transaction) {
                $items[] = $this->transactionEvidence($transaction, $findingId, 'vendor_risk_transaction');
            }
        }

        if ($items === [] && $support !== []) {
            $supportHash = substr(hash('sha256', json_encode($support)), 0, 16);
            $items[] = [
                'id' => "evidence:vendor_risk_summary:{$supportHash}",
                'investigationId' => null,
                'findingId' => $findingId,
                'evidenceType' => 'system_summary',
                'sourceType' => 'vendor_risk_support',
                'sourceId' => null,
                'sourceRecordId' => "{$vendorName}:{$ruleKey}",
                'title' => "{$vendorName} risk support",
                'summary' => 'Aggregate vendor-risk support is available, but no transaction-level citation was present.',
                'citationLabel' => "vendor_risk:{$vendorName}:{$ruleKey}",
                'sourceRowRange' => null,
                'fileName' => null,
                'storageKey' => null,
                'hash' => $supportHash,
                'addedByActorType' => 'system',
                'addedByActorId' => null,
                'createdAt' => null,
                'metadata' => [
                    'support_summary_only' => true,
                    'raw_support_returned' => false,
                ],
            ];
        }

        return $items;
    }

    private function transactionEvidence(Transaction $transaction, string $findingId, string $sourceType = 'transaction'): array
    {
        return [
            'id' => "evidence:transaction:{$transaction->id}",
            'investigationId' => null,
            'findingId' => $findingId,
            'evidenceType' => 'transaction',
            'sourceType' => $sourceType,
            'sourceId' => $transaction->upload_id ? (string) $transaction->upload_id : null,
            'sourceRecordId' => (string) $transaction->id,
            'title' => $transaction->vendor_customer ?: 'Transaction',
            'summary' => trim(sprintf(
                '%s transaction%s%s',
                $this->dateString($transaction->date) ?? 'Undated',
                $transaction->amount !== null ? ' for $'.number_format((float) $transaction->amount, 2) : '',
                $transaction->anomaly_reason ? ': '.$transaction->anomaly_reason : '',
            )),
            'citationLabel' => $this->transactionCitationLabel($transaction),
            'sourceRowRange' => $transaction->source_row_number ? (string) $transaction->source_row_number : null,
            'fileName' => null,
            'storageKey' => null,
            'hash' => $transaction->row_content_hash,
            'addedByActorType' => 'system',
            'addedByActorId' => null,
            'createdAt' => $this->dateString($transaction->date),
            'metadata' => [
                'upload_id' => $transaction->upload_id,
                'import_batch_id' => $transaction->import_batch_id,
                'source_sheet_name' => $transaction->source_sheet_name,
                'source_row_number' => $transaction->source_row_number,
                'row_content_hash' => $transaction->row_content_hash,
                'raw_row_returned' => false,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function extractTransactionIds(array $value): array
    {
        $ids = [];
        array_walk_recursive($value, function (mixed $item, mixed $key) use (&$ids): void {
            if ($key === 'id' && is_string($item) && Str::isUuid($item)) {
                $ids[] = $item;
            }
        });

        return array_values(array_unique($ids));
    }

    private function findTransaction(string $companyId, ?string $businessProfileId, string $transactionId): ?Transaction
    {
        if (! Schema::hasTable('transactions')) {
            return null;
        }

        return $this->transactionQuery($companyId, $businessProfileId)
            ->where('id', $transactionId)
            ->first();
    }

    private function transactionQuery(string $companyId, ?string $businessProfileId): Builder
    {
        return Transaction::where('company_id', $companyId)
            ->when(
                $businessProfileId && Schema::hasColumn('transactions', 'business_profile_id'),
                fn (Builder $query) => $query->where('business_profile_id', $businessProfileId),
            );
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private function applyFindingFilters(array $findings, array $filters): array
    {
        return array_values(array_filter($findings, function (array $finding) use ($filters): bool {
            if (isset($filters['category']) && $filters['category'] !== '' && $finding['category'] !== $filters['category']) {
                return false;
            }

            if (isset($filters['status']) && $filters['status'] !== '' && $finding['status'] !== $filters['status']) {
                return false;
            }

            return true;
        }));
    }

    private function category(string $category): string
    {
        return in_array($category, InvestigationPlatformContractService::INVESTIGATION_CATEGORIES, true) ? $category : 'unsure';
    }

    private function severity(string $severity): string
    {
        return in_array($severity, ['info', 'warning', 'critical'], true) ? $severity : 'warning';
    }

    private function confidence(mixed $confidence): ?string
    {
        return in_array($confidence, ['low', 'medium', 'high'], true) ? $confidence : null;
    }

    private function confidenceFromCount(int $count): string
    {
        return match (true) {
            $count >= 2 => 'high',
            $count === 1 => 'medium',
            default => 'low',
        };
    }

    private function severityFromRiskLevel(string $riskLevel): string
    {
        return match ($riskLevel) {
            'critical', 'high' => 'critical',
            'medium', 'warning' => 'warning',
            default => 'info',
        };
    }

    private function categoryFromTransaction(Transaction $transaction): string
    {
        $text = strtolower(($transaction->anomaly_reason ?? '').' '.($transaction->category ?? '').' '.($transaction->type ?? ''));

        return match (true) {
            str_contains($text, 'payroll') => 'payroll',
            str_contains($text, 'deposit') || str_contains($text, 'revenue') => 'revenue',
            str_contains($text, 'vendor') || str_contains($text, 'payment') => 'vendor_payments',
            str_contains($text, 'reconcil') || str_contains($text, 'adjust') => 'reconciliation',
            str_contains($text, 'fraud') || str_contains($text, 'suspicious') => 'fraud',
            default => 'expense',
        };
    }

    private function amountSummary(mixed $amount, mixed $category): string
    {
        $summary = is_numeric($amount) ? '$'.number_format((float) $amount, 2) : 'Amount unavailable';

        return $category ? "{$summary} {$category}" : $summary;
    }

    private function transactionCitationLabel(Transaction $transaction): string
    {
        if ($transaction->upload_id && $transaction->source_row_number) {
            return $this->rowCitationLabel($transaction->upload_id, $transaction->source_sheet_name, $transaction->source_row_number);
        }

        return "transaction:{$transaction->id}";
    }

    private function rowCitationLabel(?string $uploadId, ?string $sheetName, mixed $rowNumber): string
    {
        $parts = ['upload', (string) ($uploadId ?? 'unknown')];
        if ($sheetName) {
            $parts[] = (string) $sheetName;
        }
        if ($rowNumber) {
            $parts[] = 'row '.(string) $rowNumber;
        }

        return implode(':', $parts);
    }

    private function dateString(mixed $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        if (is_object($date) && method_exists($date, 'format')) {
            return $date->format('Y-m-d');
        }

        return (string) $date;
    }
}
