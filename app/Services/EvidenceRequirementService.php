<?php

namespace App\Services;

use App\Models\OnboardingSession;

class EvidenceRequirementService
{
    public const INTENT_SUSPECTED_FRAUD = 'suspected_fraud_or_missing_funds';

    public const INTENT_TAX_IRS = 'tax_or_irs_issue';

    public const INTENT_ROUTINE_REVIEW = 'routine_books_review';

    public const INTENT_RECONCILIATION = 'reconciliation_cleanup';

    public const INTENT_VENDOR_CONTROLS = 'vendor_payment_controls';

    public const INTENT_ADVISOR_REVIEW = 'advisor_client_review';

    public const INTENT_UNSURE = 'unsure';

    /** @return list<string> */
    public function allowedIntents(): array
    {
        return [
            self::INTENT_SUSPECTED_FRAUD,
            self::INTENT_TAX_IRS,
            self::INTENT_ROUTINE_REVIEW,
            self::INTENT_RECONCILIATION,
            self::INTENT_VENDOR_CONTROLS,
            self::INTENT_ADVISOR_REVIEW,
            self::INTENT_UNSURE,
        ];
    }

    public static function normalizeIntent(?string $intent): ?string
    {
        if ($intent === null || trim($intent) === '') {
            return null;
        }

        $normalized = strtolower(trim($intent));
        $normalized = str_replace([' ', '-'], '_', $normalized);

        return match ($normalized) {
            'suspected_fraud',
            'fraud',
            'missing_funds',
            'misuse_of_money',
            'suspected_loss',
            'suspected_fraud_or_missing_funds' => self::INTENT_SUSPECTED_FRAUD,
            'tax_notice',
            'irs_notice',
            'tax_issue',
            'compliance_notice',
            'tax_or_irs_issue' => self::INTENT_TAX_IRS,
            'books_review',
            'routine_review',
            'unusual_activity',
            'routine_books_review' => self::INTENT_ROUTINE_REVIEW,
            'reconciliation',
            'reconciliation_cleanup' => self::INTENT_RECONCILIATION,
            'vendor_controls',
            'payment_controls',
            'vendor_payment_controls' => self::INTENT_VENDOR_CONTROLS,
            'advisor_review',
            'client_review',
            'advisor_client_review' => self::INTENT_ADVISOR_REVIEW,
            default => in_array($normalized, [
                self::INTENT_SUSPECTED_FRAUD,
                self::INTENT_TAX_IRS,
                self::INTENT_ROUTINE_REVIEW,
                self::INTENT_RECONCILIATION,
                self::INTENT_VENDOR_CONTROLS,
                self::INTENT_ADVISOR_REVIEW,
                self::INTENT_UNSURE,
            ], true) ? $normalized : self::INTENT_UNSURE,
        };
    }

    /**
     * @param  array{summary?: array<string, mixed>, sources?: list<array<string, mixed>>}  $dataSources
     * @return array{
     *     sessionId: string,
     *     primaryIntent: string,
     *     requirements: list<array<string, mixed>>,
     *     readiness: array<string, mixed>
     * }
     */
    public function requirementsForSession(OnboardingSession $session, array $dataSources): array
    {
        $primaryIntent = self::normalizeIntent($session->primary_intent) ?: self::INTENT_UNSURE;
        $templates = $this->templatesFor($primaryIntent, $session->business_context ?: []);
        $requirements = [];

        foreach ($templates as $template) {
            $requirements[] = $this->materializeRequirement($template, $session, $dataSources['sources'] ?? []);
        }

        return [
            'sessionId' => (string) $session->id,
            'primaryIntent' => $primaryIntent,
            'requirements' => $requirements,
            'readiness' => $this->readiness($requirements),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $requirements
     * @return array<string, mixed>
     */
    public function readiness(array $requirements): array
    {
        $weights = [
            'required' => 3,
            'recommended' => 2,
            'optional' => 1,
        ];

        $possible = 0.0;
        $earned = 0.0;
        $requiredTotal = 0;
        $requiredReceived = 0;
        $missingRequired = [];
        $processingRequired = [];

        foreach ($requirements as $requirement) {
            $priority = (string) ($requirement['priority'] ?? 'optional');
            $weight = (float) ($weights[$priority] ?? 1);
            $possible += $weight;

            $status = (string) ($requirement['status'] ?? 'missing');
            if (in_array($status, ['received', 'validated', 'waived'], true)) {
                $earned += $weight;
            } elseif ($status === 'processing') {
                $earned += $weight * 0.5;
            }

            if ($priority === 'required') {
                $requiredTotal++;
                if (in_array($status, ['received', 'validated', 'waived'], true)) {
                    $requiredReceived++;
                } elseif ($status === 'processing') {
                    $processingRequired[] = $requirement['requirementKey'];
                } else {
                    $missingRequired[] = $requirement['requirementKey'];
                }
            }
        }

        $score = $possible > 0 ? (int) round(($earned / $possible) * 100) : 0;
        $hasRequiredEvidence = $requiredReceived > 0;
        $status = match (true) {
            $requiredTotal > 0 && $requiredReceived === $requiredTotal => 'ready_for_snapshot',
            $hasRequiredEvidence => 'scope_limited',
            $processingRequired !== [] => 'processing',
            default => 'not_ready',
        };

        return [
            'score' => min(100, max(0, $score)),
            'status' => $status,
            'requiredReceived' => $requiredReceived,
            'requiredTotal' => $requiredTotal,
            'missingRequiredKeys' => array_values($missingRequired),
            'processingRequiredKeys' => array_values($processingRequired),
            'canRunScopeLimitedSnapshot' => $hasRequiredEvidence && $missingRequired !== [],
        ];
    }

    public function intentLabel(?string $intent): string
    {
        return match (self::normalizeIntent($intent) ?: self::INTENT_UNSURE) {
            self::INTENT_SUSPECTED_FRAUD => 'Suspected fraud, missing funds, or misuse of money',
            self::INTENT_TAX_IRS => 'IRS, tax, payroll, or compliance notice',
            self::INTENT_ROUTINE_REVIEW => 'Books review for unusual activity',
            self::INTENT_RECONCILIATION => 'Bank, card, or accounting reconciliation cleanup',
            self::INTENT_VENDOR_CONTROLS => 'Vendor, employee, or payment controls review',
            self::INTENT_ADVISOR_REVIEW => 'Client or organization review',
            default => 'Guided evidence readiness review',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $requirements
     * @return list<array<string, mixed>>
     */
    public function missingEvidence(array $requirements, bool $includeRecommended = true): array
    {
        return array_values(array_filter(
            $requirements,
            fn (array $requirement): bool => ($requirement['status'] ?? null) === 'missing'
                && in_array(
                    $requirement['priority'] ?? null,
                    $includeRecommended ? ['required', 'recommended'] : ['required'],
                    true,
                ),
        ));
    }

    /**
     * @param  array<string, mixed>  $businessContext
     * @return list<array<string, mixed>>
     */
    private function templatesFor(string $primaryIntent, array $businessContext): array
    {
        return match ($primaryIntent) {
            self::INTENT_SUSPECTED_FRAUD => $this->suspectedFraudTemplates($businessContext),
            self::INTENT_TAX_IRS => $this->taxTemplates(),
            self::INTENT_ROUTINE_REVIEW => $this->routineReviewTemplates(),
            self::INTENT_RECONCILIATION => $this->reconciliationTemplates(),
            self::INTENT_VENDOR_CONTROLS => $this->vendorControlTemplates(),
            self::INTENT_ADVISOR_REVIEW => $this->advisorReviewTemplates(),
            default => $this->unsureTemplates(),
        };
    }

    /**
     * @param  array<string, mixed>  $businessContext
     * @return list<array<string, mixed>>
     */
    private function suspectedFraudTemplates(array $businessContext): array
    {
        $templates = [
            $this->financialDataTemplate('transaction_ledger', 'Transaction ledger or bank/card export', 'Brevix needs transaction rows for the review period before it can identify unusual expenditure patterns.', 'required'),
            $this->documentTemplate('bank_statements', 'Bank statements for the review period', 'Statements are needed to compare ledger activity against independent bank records.', 'required', ['bank_statement']),
            $this->financialDataTemplate('vendor_list', 'Vendor list and payment register', 'Vendor and payment context helps separate normal operating spend from activity that needs review.', 'recommended', ['quickbooks', 'file_upload'], ['ap_invoice_register', 'transaction_ledger']),
            $this->manualTemplate('access_authority_list', 'People with book access and bank-signing authority', 'Access and signer context helps evaluate control weaknesses without implying that any person caused a loss.', 'recommended', ['bookAccessCount', 'authorizedSignerCount']),
            $this->documentTemplate('approval_records', 'Budgets, policies, board minutes, approvals, receipts, or invoices', 'Supporting records improve confidence when a transaction appears inconsistent with expected activity.', 'optional', ['approval_record', 'receipt', 'invoice', 'policy']),
        ];

        if (($businessContext['checksUsed'] ?? null) === true) {
            $templates[] = $this->documentTemplate('check_images', 'Check images or front/back check copies', 'Ledger rows alone do not show signatures, endorsements, alterations, or check sequence issues.', 'recommended', ['check_image']);
        }

        return $templates;
    }

    /** @return list<array<string, mixed>> */
    private function taxTemplates(): array
    {
        return [
            $this->documentTemplate('tax_notice', 'Notice or letter', 'The notice anchors the workflow to the agency, period, deadline, and requested response.', 'required', ['tax_notice', 'irs_notice']),
            $this->manualTemplate('tax_period', 'Tax year or period involved', 'The period keeps review scope bounded and prevents unrelated records from being treated as evidence.', 'required', ['taxPeriod']),
            $this->financialDataTemplate('ledger_or_payroll_records', 'Relevant ledger, bank statements, or payroll records', 'Records are needed to organize the facts around the notice. Brevix does not provide tax advice.', 'required'),
            $this->documentTemplate('prior_returns_and_payments', 'Prior returns, payment confirmations, correspondence, and deadlines', 'Prior filings and correspondence help identify what has already been attempted.', 'recommended', ['prior_return', 'payment_confirmation', 'correspondence']),
            $this->documentTemplate('accountant_notes', 'Accountant notes or prior resolution attempts', 'Prior professional notes can reduce duplicate work and clarify open questions.', 'optional', ['accountant_note']),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function routineReviewTemplates(): array
    {
        return [
            $this->financialDataTemplate('transaction_ledger', 'Transaction ledger or accounting data', 'A routine review starts with financial activity for the selected period.', 'required'),
            $this->documentTemplate('bank_statements', 'Bank statements for the review period', 'Statements improve confidence that the ledger reflects actual bank activity.', 'recommended', ['bank_statement']),
            $this->financialDataTemplate('vendor_list', 'Vendor list and payment register', 'Vendor history helps identify duplicate vendors, new vendors, and payment-pattern changes.', 'recommended', ['quickbooks', 'file_upload'], ['ap_invoice_register', 'transaction_ledger']),
            $this->manualTemplate('access_authority_list', 'People with book access and bank-signing authority', 'Access context helps prioritize control-health findings.', 'recommended', ['bookAccessCount', 'authorizedSignerCount']),
            $this->documentTemplate('controls_policy', 'Approval policy, budgets, or written controls', 'Written controls provide context for whether activity matched expected approvals.', 'optional', ['policy', 'approval_record']),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function reconciliationTemplates(): array
    {
        return [
            $this->financialDataTemplate('transaction_ledger', 'Transaction ledger or accounting export', 'Reconciliation cleanup needs ledger rows for the review period.', 'required'),
            $this->documentTemplate('bank_statements', 'Bank and card statements', 'Statements provide the independent record for matching cleared and outstanding items.', 'required', ['bank_statement']),
            $this->documentTemplate('reconciliation_reports', 'Prior reconciliation reports or outstanding-item list', 'Prior reconciliation work helps identify stale differences and duplicate effort.', 'recommended', ['reconciliation_report']),
            $this->documentTemplate('supporting_documents', 'Receipts, invoices, and adjustment support', 'Support documents help resolve items that remain unmatched after data import.', 'optional', ['receipt', 'invoice', 'adjustment_support']),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function vendorControlTemplates(): array
    {
        return [
            $this->financialDataTemplate('vendor_list', 'Vendor list', 'Vendor controls require the population of vendors before duplicate or related-party indicators can be reviewed.', 'required', ['quickbooks', 'file_upload'], ['ap_invoice_register', 'transaction_ledger']),
            $this->financialDataTemplate('payment_register', 'Payment register or transaction ledger', 'Payment history shows timing, amount, frequency, and approval-risk indicators.', 'required', ['quickbooks', 'gnucash', 'file_upload'], ['ap_invoice_register', 'transaction_ledger']),
            $this->documentTemplate('approval_policy', 'Approval policy and vendor onboarding records', 'Policies and onboarding files show expected approvals and vendor validation steps.', 'recommended', ['policy', 'vendor_onboarding', 'approval_record']),
            $this->manualTemplate('access_authority_list', 'People with book access and payment authority', 'Access context helps prioritize payment-control review.', 'recommended', ['bookAccessCount', 'authorizedSignerCount']),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function advisorReviewTemplates(): array
    {
        return [
            $this->manualTemplate('client_business_context', 'Client or organization context', 'Advisor workflows need the client type, activity, accounting system, and review period before evidence can be scoped.', 'required', ['organizationType', 'industryOrActivity', 'accountingSystem']),
            $this->financialDataTemplate('transaction_ledger', 'Transaction ledger or accounting export', 'A client review needs financial activity before findings can be generated.', 'required'),
            $this->documentTemplate('bank_statements', 'Bank statements for the review period', 'Independent statements improve confidence and support client-ready packets.', 'recommended', ['bank_statement']),
            $this->documentTemplate('prior_findings', 'Prior findings, notes, or client concern summary', 'Prior context helps avoid repeating already resolved issues.', 'optional', ['prior_finding', 'client_note']),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function unsureTemplates(): array
    {
        return [
            $this->manualTemplate('business_context', 'Business context', 'A small amount of context lets Brevix choose the right guided review path.', 'required', ['organizationType', 'industryOrActivity', 'accountingSystem']),
            $this->financialDataTemplate('transaction_ledger', 'Transaction ledger or accounting data', 'Financial activity is the fastest way to create a useful first snapshot.', 'recommended'),
            $this->documentTemplate('bank_statements', 'Bank statements for the review period', 'Statements improve confidence once a review objective is selected.', 'recommended', ['bank_statement']),
        ];
    }

    /**
     * @param  list<string>  $sourceTypes
     * @param  list<string>  $importTypes
     * @return array<string, mixed>
     */
    private function financialDataTemplate(
        string $key,
        string $label,
        string $reason,
        string $priority,
        array $sourceTypes = ['quickbooks', 'gnucash', 'file_upload'],
        array $importTypes = ['transaction_ledger'],
    ): array {
        return [
            'requirementKey' => $key,
            'label' => $label,
            'reason' => $reason,
            'priority' => $priority,
            'acceptedSourceTypes' => $sourceTypes,
            'acceptedImportTypes' => $importTypes,
            'category' => 'financial_data',
        ];
    }

    /**
     * @param  list<string>  $evidenceTypes
     * @return array<string, mixed>
     */
    private function documentTemplate(string $key, string $label, string $reason, string $priority, array $evidenceTypes): array
    {
        return [
            'requirementKey' => $key,
            'label' => $label,
            'reason' => $reason,
            'priority' => $priority,
            'acceptedSourceTypes' => ['document_upload'],
            'acceptedEvidenceTypes' => $evidenceTypes,
            'category' => 'document',
        ];
    }

    /**
     * @param  list<string>  $contextKeys
     * @return array<string, mixed>
     */
    private function manualTemplate(string $key, string $label, string $reason, string $priority, array $contextKeys): array
    {
        return [
            'requirementKey' => $key,
            'label' => $label,
            'reason' => $reason,
            'priority' => $priority,
            'acceptedSourceTypes' => ['manual_answer'],
            'businessContextKeys' => $contextKeys,
            'category' => 'manual_context',
        ];
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  list<array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    private function materializeRequirement(array $template, OnboardingSession $session, array $sources): array
    {
        $matches = $this->matchingSources($template, $session, $sources);
        $status = $this->statusFromMatches($matches);
        $primarySource = $matches[0] ?? null;

        return [
            'id' => (string) $template['requirementKey'],
            'requirementKey' => (string) $template['requirementKey'],
            'label' => (string) $template['label'],
            'reason' => (string) $template['reason'],
            'priority' => (string) $template['priority'],
            'status' => $status,
            'sourceType' => $primarySource['sourceType'] ?? null,
            'sourceId' => $primarySource['sourceId'] ?? null,
            'category' => (string) ($template['category'] ?? 'evidence'),
            'acceptedSourceTypes' => $template['acceptedSourceTypes'] ?? [],
            'satisfiedBy' => $matches,
        ];
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  list<array<string, mixed>>  $sources
     * @return list<array<string, mixed>>
     */
    private function matchingSources(array $template, OnboardingSession $session, array $sources): array
    {
        $matches = [];

        if (in_array('manual_answer', $template['acceptedSourceTypes'] ?? [], true)) {
            $contextMatches = [];
            foreach ($template['businessContextKeys'] ?? [] as $key) {
                if ($this->hasContextValue($session->business_context ?: [], (string) $key)) {
                    $contextMatches[] = [
                        'sourceType' => 'manual_answer',
                        'sourceId' => (string) $key,
                        'label' => $this->humanizeKey((string) $key),
                        'statusCategory' => 'received',
                    ];
                }
            }

            if ($contextMatches !== [] && count($contextMatches) === count($template['businessContextKeys'] ?? [])) {
                array_push($matches, ...$contextMatches);
            }
        }

        foreach ($sources as $source) {
            if (! in_array($source['sourceType'] ?? null, $template['acceptedSourceTypes'] ?? [], true)) {
                continue;
            }

            if (($source['sourceType'] ?? null) === 'file_upload') {
                $acceptedImportTypes = $template['acceptedImportTypes'] ?? [];
                $importType = $source['metadata']['importType'] ?? null;
                if ($acceptedImportTypes !== [] && ! in_array($importType, $acceptedImportTypes, true)) {
                    continue;
                }
            }

            if (($source['sourceType'] ?? null) === 'document_upload') {
                $acceptedEvidenceTypes = $template['acceptedEvidenceTypes'] ?? [];
                $evidenceType = $source['metadata']['evidenceType'] ?? null;
                if ($acceptedEvidenceTypes !== [] && ! in_array($evidenceType, $acceptedEvidenceTypes, true)) {
                    continue;
                }
            }

            if (($source['statusCategory'] ?? null) === 'failed') {
                continue;
            }

            $matches[] = [
                'sourceType' => $source['sourceType'] ?? null,
                'sourceId' => $source['sourceId'] ?? null,
                'label' => $source['label'] ?? null,
                'statusCategory' => $source['statusCategory'] ?? 'received',
            ];
        }

        usort($matches, fn (array $a, array $b): int => $this->sourceRank($a) <=> $this->sourceRank($b));

        return $matches;
    }

    /**
     * @param  list<array<string, mixed>>  $matches
     */
    private function statusFromMatches(array $matches): string
    {
        foreach ($matches as $match) {
            if (($match['statusCategory'] ?? null) === 'received') {
                return 'received';
            }
        }

        foreach ($matches as $match) {
            if (($match['statusCategory'] ?? null) === 'processing') {
                return 'processing';
            }
        }

        return 'missing';
    }

    /**
     * @param  array<string, mixed>  $businessContext
     */
    private function hasContextValue(array $businessContext, string $key): bool
    {
        if (! array_key_exists($key, $businessContext)) {
            return false;
        }

        $value = $businessContext[$key];

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return $value !== null;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function sourceRank(array $source): int
    {
        if (($source['statusCategory'] ?? null) === 'received') {
            return 0;
        }

        if (($source['statusCategory'] ?? null) === 'processing') {
            return 1;
        }

        return 2;
    }

    private function humanizeKey(string $key): string
    {
        return ucwords((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $key));
    }
}
