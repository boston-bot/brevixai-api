<?php

namespace App\Services\Agents;

/**
 * Single source of truth for tool key → internal route URI segment.
 * Used by BrevixAgentRunner to build tool advertisements and by the
 * parity test to verify every tool key has a registered Laravel route.
 */
class AgentToolRegistry
{
    /**
     * Map of tool key → URI path suffix under /api/internal/agent-tools/.
     * The suffix is appended to the appropriate base path (companies/{id}/ or company/{id}/).
     * Suffixes without {companyId} are global (e.g. process-registry).
     *
     * @return array<string, string>
     */
    public static function routeSuffixes(): array
    {
        return [
            'company_context'          => 'companies/{companyId}/context',
            'risk_summary'             => 'companies/{companyId}/risk-summary',
            'vendor_risk'              => 'company/{companyId}/vendor-risk',
            'reconciliation_risk'      => 'company/{companyId}/reconciliation-risk',
            'entity_relationship_risk' => 'company/{companyId}/entity-relationship-risk',
            'aggregate_risk_summary'   => 'company/{companyId}/aggregate-risk-summary',
            'alert_recommendations'    => 'company/{companyId}/alert-recommendations',
            'case_recommendations'     => 'company/{companyId}/case-recommendations',
            'transaction_lookup'       => 'company/{companyId}/transactions',
            'dashboard_health'         => 'company/{companyId}/dashboard',
            'transaction_detail'       => 'company/{companyId}/transaction-detail',
            'pending_recommendations'  => 'company/{companyId}/pending-recommendations',
            'behavioral_baseline'      => 'company/{companyId}/behavioral-baseline',
            'process_registry'         => 'process-registry',
        ];
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
}
