<?php

namespace App\Services\Agents;

use App\Enums\ProcessReadiness;

/**
 * Single source of truth for internal agent-tool contracts.
 *
 * The catalog is intentionally method-aware because not every deterministic
 * tool is a GET lookup. Smoke checks, agent advertisements, and parity tests
 * should all derive from these definitions.
 */
class AgentToolRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            'company_context' => self::companyTool(
                method: 'GET',
                path: 'companies/{companyId}/context',
                optional: false,
                purpose: 'Load company, data-source, user-role, dashboard, and bounded transaction context through Laravel tenant checks.',
                extra: ['data_authority' => 'laravel'],
                requestSchema: ['query' => ['include_transactions', 'include_dashboard', 'date_from', 'date_to', 'limit']],
                responseSchema: ['company', 'data_sources', 'dashboard', 'transactions'],
            ),
            'risk_summary' => self::companyTool(
                method: 'GET',
                path: 'companies/{companyId}/risk-summary',
                optional: false,
                purpose: 'Use as the primary deterministic risk score and top-driver source for risk review responses.',
                extra: ['score_authority' => 'laravel'],
                requestSchema: ['query' => ['period']],
                responseSchema: ['risk_score', 'drivers', 'alerts'],
            ),
            'vendor_risk' => self::companyTool(
                method: 'GET',
                path: 'company/{companyId}/vendor-risk',
                optional: true,
                purpose: 'Use for vendor concentration, vendor onboarding, payment-pattern, and named-vendor risk analysis.',
                extra: ['score_authority' => 'laravel'],
                requestSchema: ['query' => ['vendor', 'period']],
                responseSchema: ['vendors', 'risk_score', 'evidence'],
            ),
            'reconciliation_risk' => self::companyTool(
                method: 'GET',
                path: 'company/{companyId}/reconciliation-risk',
                optional: true,
                purpose: 'Use for bank-to-ledger mismatch, stale discrepancy, and reconciliation-drift analysis.',
                extra: ['score_authority' => 'laravel'],
                requestSchema: ['query' => ['period']],
                responseSchema: ['risk_score', 'discrepancies', 'evidence'],
            ),
            'entity_relationship_risk' => self::companyTool(
                method: 'GET',
                path: 'company/{companyId}/entity-relationship-risk',
                optional: true,
                purpose: 'Use for employee/vendor overlap, shared contact data, duplicate entity, and related-party risk analysis.',
                extra: ['score_authority' => 'laravel'],
                requestSchema: ['query' => ['entity_id']],
                responseSchema: ['risk_score', 'patterns', 'evidence'],
            ),
            'aggregate_risk_summary' => self::companyTool(
                method: 'GET',
                path: 'company/{companyId}/aggregate-risk-summary',
                optional: true,
                purpose: 'Use during fraud or risk analysis when a cross-domain deterministic score and evidence summary would improve the response.',
                extra: ['score_authority' => 'laravel'],
                requestSchema: ['query' => ['period']],
                responseSchema: ['aggregate_score', 'domains', 'evidence'],
            ),
            'alert_recommendations' => self::companyTool(
                method: 'GET',
                path: 'company/{companyId}/alert-recommendations',
                optional: true,
                purpose: 'Use during fraud or risk analysis when deterministic alert recommendation drafts would improve the response.',
                extra: ['recommendation_authority' => 'laravel'],
                requestSchema: ['query' => []],
                responseSchema: ['recommended_alerts'],
            ),
            'case_recommendations' => self::companyTool(
                method: 'GET',
                path: 'company/{companyId}/case-recommendations',
                optional: true,
                purpose: 'Use during risk analysis when deterministic case recommendation drafts would improve the response.',
                extra: ['recommendation_authority' => 'laravel'],
                requestSchema: ['query' => []],
                responseSchema: ['case_recommendations'],
            ),
            'transaction_lookup' => self::companyTool(
                method: 'GET',
                path: 'company/{companyId}/transactions',
                optional: true,
                purpose: 'Use to inspect bounded company transactions through Laravel tenant checks.',
                extra: ['data_authority' => 'laravel'],
                requestSchema: ['query' => ['period', 'limit', 'status']],
                responseSchema: ['transactions'],
                advertiseByDefault: false,
            ),
            'dashboard_health' => self::companyTool(
                method: 'GET',
                path: 'company/{companyId}/dashboard',
                optional: true,
                purpose: 'Use to inspect the deterministic dashboard health snapshot.',
                extra: ['data_authority' => 'laravel'],
                requestSchema: ['query' => []],
                responseSchema: ['risk_score', 'stats', 'alerts'],
                advertiseByDefault: false,
            ),
            'transaction_detail' => self::companyTool(
                method: 'GET',
                path: 'company/{companyId}/transaction-detail',
                optional: true,
                purpose: 'Use to fetch specific transaction records by UUID when the user references a known transaction ID. Returns amount, date, vendor, type, and anomaly data.',
                extra: ['data_authority' => 'laravel'],
                requestSchema: ['query' => ['ids[]']],
                responseSchema: ['transactions'],
            ),
            'pending_recommendations' => self::companyTool(
                method: 'GET',
                path: 'company/{companyId}/pending-recommendations',
                optional: true,
                purpose: 'Use to surface pending alert and case recommendations so the response can acknowledge open items awaiting user action.',
                extra: ['recommendation_authority' => 'laravel'],
                requestSchema: ['query' => []],
                responseSchema: ['alert_recommendations', 'case_recommendations'],
            ),
            'behavioral_baseline' => self::companyTool(
                method: 'GET',
                path: 'company/{companyId}/behavioral-baseline',
                optional: true,
                purpose: 'Use for behavior-baseline comparison when behavioral analysis is enabled.',
                extra: ['score_authority' => 'laravel'],
                requestSchema: ['query' => ['period']],
                responseSchema: ['baseline', 'deviations'],
                advertiseByDefault: false,
            ),
            'process_registry' => self::globalTool(
                method: 'GET',
                path: 'process-registry',
                optional: false,
                purpose: 'Expose supported action types and approval requirements for the agent service.',
                requestSchema: ['query' => []],
                responseSchema: ['action_types'],
                advertiseByDefault: false,
            ),
            'irm_search' => self::globalTool(
                method: 'GET',
                path: 'irs/irm/search',
                optional: true,
                purpose: 'Search source-backed IRM procedural sections by topic.',
                requestSchema: ['query' => ['topic', 'limit']],
                responseSchema: ['status', 'query', 'results', 'disclaimer'],
                advertiseByDefault: false,
            ),
            'irm_section' => self::globalTool(
                method: 'GET',
                path: 'irs/irm/section',
                optional: true,
                purpose: 'Fetch source-backed IRM procedural sections by exact reference.',
                requestSchema: ['query' => ['reference']],
                responseSchema: ['status', 'reference', 'results', 'disclaimer'],
                advertiseByDefault: false,
            ),
            'irs_notice_type' => self::globalTool(
                method: 'GET',
                path: 'irs/notice-type',
                optional: true,
                purpose: 'Explain a known IRS notice code with source-backed IRM sections.',
                requestSchema: ['query' => ['code', 'limit']],
                responseSchema: ['status', 'notice_code', 'results', 'disclaimer'],
                advertiseByDefault: false,
            ),
            'irs_records_checklist' => self::globalTool(
                method: 'GET',
                path: 'irs/records-checklist',
                optional: true,
                purpose: 'Recommend records to gather for an IRS issue type or notice code.',
                requestSchema: ['query' => ['issue_type', 'limit']],
                responseSchema: ['status', 'recommended_records', 'results', 'disclaimer'],
                advertiseByDefault: false,
            ),
            'irs_collection_risk' => self::globalTool(
                method: 'GET',
                path: 'irs/collection-risk',
                optional: true,
                purpose: 'Summarize collection-risk posture for an IRS issue type or notice code.',
                requestSchema: ['query' => ['issue_type', 'limit']],
                responseSchema: ['status', 'risk_level', 'results', 'disclaimer'],
                advertiseByDefault: false,
            ),
            'irs_notice_extract' => self::globalTool(
                method: 'POST',
                path: 'irs/notice/extract',
                optional: true,
                purpose: 'Extract structured notice facts from raw IRS notice text and enrich them with source-backed IRM sections.',
                requestSchema: ['json' => ['text', 'limit']],
                responseSchema: ['status', 'notice_type', 'deadline_days', 'required_action', 'risk_level', 'results', 'disclaimer'],
                advertiseByDefault: false,
            ),
        ];
    }

    /**
     * Map of tool key -> URI path suffix under /api/internal/agent-tools/.
     * Kept for compatibility with existing tests and callers.
     *
     * @return array<string, string>
     */
    public static function routeSuffixes(): array
    {
        return array_map(
            fn (array $definition): string => (string) $definition['path_suffix'],
            self::definitions()
        );
    }

    /** @return array<string, string> */
    public static function routeMethods(): array
    {
        return array_map(
            fn (array $definition): string => (string) $definition['method'],
            self::definitions()
        );
    }

    /** @return list<string> */
    public static function defaultAdvertisedToolKeys(): array
    {
        return array_keys(array_filter(
            self::definitions(),
            fn (array $definition): bool => (bool) ($definition['advertise_by_default'] ?? false)
        ));
    }

    /** @return array<string, mixed>|null */
    public static function definition(string $toolKey): ?array
    {
        return self::definitions()[$toolKey] ?? null;
    }

    /** @return array<string, mixed>|null */
    public static function advertisement(string $toolKey, string $companyId): ?array
    {
        $definition = self::definition($toolKey);
        if ($definition === null) {
            return null;
        }

        $advertisement = $definition;
        $advertisement['path'] = self::path($toolKey, $companyId);
        unset($advertisement['path_suffix'], $advertisement['advertise_by_default']);

        return $advertisement;
    }

    /**
     * Build the full path for a tool given a concrete company ID.
     */
    public static function path(string $toolKey, string $companyId): ?string
    {
        $suffix = self::routeSuffixes()[$toolKey] ?? null;
        if ($suffix === null) {
            return null;
        }
        return '/api/internal/agent-tools/' . str_replace('{companyId}', $companyId, $suffix);
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, list<string>> $requestSchema
     * @param list<string> $responseSchema
     * @return array<string, mixed>
     */
    private static function companyTool(
        string $method,
        string $path,
        bool $optional,
        string $purpose,
        array $extra,
        array $requestSchema,
        array $responseSchema,
        bool $advertiseByDefault = true,
    ): array {
        return array_merge([
            'method' => $method,
            'path_suffix' => $path,
            'scope' => 'company',
            'readiness' => ProcessReadiness::Available->value,
            'optional' => $optional,
            'purpose' => $purpose,
            'deterministic' => true,
            'requires_user_context_header' => true,
            'requires_business_profile_context' => true,
            'business_profile_header' => 'X-Brevix-Business-Profile-Id',
            'request_schema' => $requestSchema,
            'response_schema' => $responseSchema,
            'advertise_by_default' => $advertiseByDefault,
        ], $extra);
    }

    /**
     * @param array<string, list<string>> $requestSchema
     * @param list<string> $responseSchema
     * @return array<string, mixed>
     */
    private static function globalTool(
        string $method,
        string $path,
        bool $optional,
        string $purpose,
        array $requestSchema,
        array $responseSchema,
        bool $advertiseByDefault = true,
    ): array {
        return [
            'method' => $method,
            'path_suffix' => $path,
            'scope' => 'global',
            'readiness' => ProcessReadiness::Available->value,
            'optional' => $optional,
            'purpose' => $purpose,
            'deterministic' => true,
            'requires_user_context_header' => false,
            'requires_business_profile_context' => false,
            'request_schema' => $requestSchema,
            'response_schema' => $responseSchema,
            'advertise_by_default' => $advertiseByDefault,
        ];
    }
}
