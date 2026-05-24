<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Company;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Agents\EntityRelationshipRiskScoringService;

class EntityGraphService
{
    public function __construct(
        private EntityRelationshipRiskScoringService $scoringService,
    ) {}

    public function getGraph(string $companyId): array
    {
        $scoring = $this->scoringService->scoreEntityRelationships($companyId);

        $nodes = [];
        $edges = [];
        $nodeIndex = [];

        $company = Company::where('id', $companyId)->first(['id', 'name']);
        $companyNodeId = 'company_'.$companyId;
        $companyStats = $this->companyTransactionStats($companyId);

        $nodes[] = [
            'id' => $companyNodeId,
            'type' => 'company',
            'label' => $company?->name ?? 'Company',
            'totalVolume' => $companyStats['totalVolume'],
            'transactionCount' => $companyStats['transactionCount'],
            'firstSeen' => $companyStats['firstSeen'],
            'lastSeen' => $companyStats['lastSeen'],
            'metadata' => [
                'company_id' => $companyId,
            ],
        ];
        $nodeIndex['company:'.$companyId] = $companyNodeId;

        $users = User::where('company_id', $companyId)->get(['id', 'first_name', 'last_name', 'email', 'role']);
        foreach ($users as $user) {
            $label = trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: $user->email;
            $stats = $this->employeeTransactionStats($companyId, $label, (string) $user->email);

            $nodes[] = [
                'id' => $user->id,
                'type' => 'employee',
                'label' => $label,
                'totalVolume' => $stats['totalVolume'],
                'transactionCount' => $stats['transactionCount'],
                'firstSeen' => $stats['firstSeen'],
                'lastSeen' => $stats['lastSeen'],
                'metadata' => [
                    'email' => $user->email,
                    'role' => $user->role,
                    'legacy_type' => 'user',
                ],
            ];
            $nodeIndex['employee:'.strtolower($label)] = $user->id;
            $nodeIndex['user:'.strtolower($label)] = $user->id;
            $nodeIndex['user:email:'.strtolower((string) $user->email)] = $user->id;
        }

        $vendorStats = $this->vendorStats($companyId);
        foreach ($vendorStats as $vendor) {
            $vendorName = (string) $vendor['vendor'];
            $nodeId = $this->vendorNodeId($vendorName);
            $nodes[] = [
                'id' => $nodeId,
                'type' => 'vendor',
                'label' => $vendorName,
                'totalVolume' => $vendor['totalVolume'],
                'transactionCount' => $vendor['transactionCount'],
                'firstSeen' => $vendor['firstSeen'],
                'lastSeen' => $vendor['lastSeen'],
                'metadata' => [],
            ];
            $nodeIndex['vendor:'.strtolower($vendorName)] = $nodeId;
        }

        $relatedEntities = is_array($scoring['related_entities'] ?? null) ? $scoring['related_entities'] : [];
        $patterns = $this->patterns($scoring, $relatedEntities, $nodeIndex);
        $suspiciousNodeIds = $this->suspiciousNodeIds($patterns);

        $edgeSeq = 0;
        foreach ($vendorStats as $vendor) {
            $vendorId = $this->vendorNodeId((string) $vendor['vendor']);
            $edgeSeq++;
            $edges[] = [
                'id' => 'edge_'.$edgeSeq,
                'source' => $companyNodeId,
                'target' => $vendorId,
                'type' => 'transaction_activity',
                'label' => 'Transaction activity',
                'totalAmount' => $vendor['totalVolume'],
                'transactionCount' => $vendor['transactionCount'],
                'isSuspicious' => in_array($vendorId, $suspiciousNodeIds, true),
            ];
        }

        foreach ($relatedEntities as $relationship) {
            $entityIds = $this->resolveEntityIds($relationship['entities'] ?? [], $nodeIndex);
            if (count($entityIds) < 2) {
                continue;
            }

            for ($i = 0; $i < count($entityIds) - 1; $i++) {
                $edgeSeq++;
                $edges[] = [
                    'id' => 'edge_'.$edgeSeq,
                    'source' => $entityIds[$i],
                    'target' => $entityIds[$i + 1],
                    'type' => (string) ($relationship['type'] ?? 'relationship_risk'),
                    'label' => (string) ($relationship['description'] ?? 'Relationship risk indicator'),
                    'totalAmount' => 0.0,
                    'transactionCount' => 0,
                    'isSuspicious' => true,
                ];
            }
        }

        $nodesByType = $this->nodesByType($nodes);
        $criticalPatterns = count(array_filter($patterns, fn (array $pattern): bool => $pattern['severity'] === 'critical'));
        $warningPatterns = count(array_filter($patterns, fn (array $pattern): bool => $pattern['severity'] === 'warning'));

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'patterns' => $patterns,
            'summary' => [
                'risk_score' => $scoring['entity_relationship_risk_score'],
                'risk_level' => $scoring['risk_level'],
                'node_count' => count($nodes),
                'edge_count' => count($edges),
                'recommended_next_action' => $scoring['recommended_next_action'],
                'totalNodes' => count($nodes),
                'totalEdges' => count($edges),
                'totalPatterns' => count($patterns),
                'nodesByType' => $nodesByType,
                'criticalPatterns' => $criticalPatterns,
                'warningPatterns' => $warningPatterns,
            ],
        ];
    }

    public function getNode(string $companyId, string $nodeId): ?array
    {
        $graph = $this->getGraph($companyId);
        $node = collect($graph['nodes'])->firstWhere('id', $nodeId);
        if (! $node) {
            return null;
        }

        $connectedEdges = array_values(array_filter(
            $graph['edges'],
            fn (array $edge): bool => $edge['source'] === $nodeId || $edge['target'] === $nodeId,
        ));
        $connectedNodeIds = array_values(array_unique(array_map(
            fn (array $edge): string => $edge['source'] === $nodeId ? $edge['target'] : $edge['source'],
            $connectedEdges,
        )));
        $connectedNodes = array_values(array_filter(
            $graph['nodes'],
            fn (array $candidate): bool => in_array($candidate['id'], $connectedNodeIds, true),
        ));
        $patterns = array_values(array_filter(
            $graph['patterns'],
            fn (array $pattern): bool => in_array($nodeId, $pattern['involvedEntities'] ?? [], true),
        ));

        return [
            'node' => $node,
            'connectedEdges' => $connectedEdges,
            'connectedNodes' => $connectedNodes,
            'patterns' => $patterns,
            'transactions' => $this->transactionsForNode($companyId, $node),
            'alerts' => $this->alertsForNode($companyId, $node),
        ];
    }

    private function vendorNodeId(string $vendorName): string
    {
        return 'v_'.substr(hash('sha256', $vendorName), 0, 16);
    }

    /**
     * @return array{totalVolume: float, transactionCount: int, firstSeen: string|null, lastSeen: string|null}
     */
    private function companyTransactionStats(string $companyId): array
    {
        $row = Transaction::where('company_id', $companyId)
            ->selectRaw('COUNT(*) as transaction_count, COALESCE(SUM(ABS(amount)), 0) as total_volume, MIN(date) as first_seen, MAX(date) as last_seen')
            ->first();

        return $this->statsFromRow($row);
    }

    /**
     * @return array<int, array{vendor: string, totalVolume: float, transactionCount: int, firstSeen: string|null, lastSeen: string|null}>
     */
    private function vendorStats(string $companyId): array
    {
        return Transaction::where('company_id', $companyId)
            ->whereNotNull('vendor_customer')
            ->selectRaw('vendor_customer as vendor, COUNT(*) as transaction_count, COALESCE(SUM(ABS(amount)), 0) as total_volume, MIN(date) as first_seen, MAX(date) as last_seen')
            ->groupBy('vendor_customer')
            ->orderBy('vendor_customer')
            ->get()
            ->map(fn (object $row): array => array_merge(
                ['vendor' => (string) $row->vendor],
                $this->statsFromRow($row),
            ))
            ->values()
            ->all();
    }

    /**
     * @return array{totalVolume: float, transactionCount: int, firstSeen: string|null, lastSeen: string|null}
     */
    private function employeeTransactionStats(string $companyId, string $name, string $email): array
    {
        $row = Transaction::where('company_id', $companyId)
            ->where(function ($query) use ($name, $email): void {
                if ($name !== '') {
                    $query->where('vendor_customer', 'like', '%'.$name.'%');
                }
                if ($email !== '') {
                    $query->orWhere('vendor_customer', 'like', '%'.$email.'%');
                }
            })
            ->selectRaw('COUNT(*) as transaction_count, COALESCE(SUM(ABS(amount)), 0) as total_volume, MIN(date) as first_seen, MAX(date) as last_seen')
            ->first();

        return $this->statsFromRow($row);
    }

    /**
     * @return array{totalVolume: float, transactionCount: int, firstSeen: string|null, lastSeen: string|null}
     */
    private function statsFromRow(?object $row): array
    {
        return [
            'totalVolume' => round((float) ($row->total_volume ?? 0), 2),
            'transactionCount' => (int) ($row->transaction_count ?? 0),
            'firstSeen' => $row->first_seen ?? null,
            'lastSeen' => $row->last_seen ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $scoring
     * @param array<int, array<string, mixed>> $relatedEntities
     * @param array<string, string> $nodeIndex
     * @return array<int, array<string, mixed>>
     */
    private function patterns(array $scoring, array $relatedEntities, array $nodeIndex): array
    {
        $rules = is_array($scoring['triggered_rules'] ?? null) ? $scoring['triggered_rules'] : [];
        $supportingEvidence = is_array($scoring['supporting_evidence'] ?? null) ? $scoring['supporting_evidence'] : [];

        return array_values(array_map(function (array $rule) use ($scoring, $supportingEvidence, $relatedEntities, $nodeIndex): array {
            $ruleKey = (string) ($rule['rule_key'] ?? 'relationship_risk');

            return [
                'type' => $ruleKey,
                'severity' => $this->patternSeverity((int) ($rule['weight'] ?? 0), (string) ($scoring['risk_level'] ?? 'low')),
                'title' => (string) ($rule['name'] ?? 'Relationship risk indicator'),
                'description' => (string) ($rule['explanation'] ?? 'Deterministic relationship scoring found a pattern that requires review.'),
                'involvedEntities' => $this->patternEntityIds($ruleKey, $relatedEntities, $nodeIndex),
                'evidence' => $supportingEvidence[$ruleKey] ?? [],
            ];
        }, $rules));
    }

    private function patternSeverity(int $weight, string $overallRiskLevel): string
    {
        if ($overallRiskLevel === 'critical') {
            return 'critical';
        }

        return $weight >= 15 ? 'warning' : 'info';
    }

    /**
     * @param array<int, array<string, mixed>> $relatedEntities
     * @param array<string, string> $nodeIndex
     * @return array<int, string>
     */
    private function patternEntityIds(string $ruleKey, array $relatedEntities, array $nodeIndex): array
    {
        $ids = [];
        foreach ($relatedEntities as $relationship) {
            $type = (string) ($relationship['type'] ?? '');
            if (! $this->relationshipMatchesRule($type, $ruleKey)) {
                continue;
            }

            $ids = array_merge($ids, $this->resolveEntityIds($relationship['entities'] ?? [], $nodeIndex));
        }

        if ($ids === []) {
            foreach ($relatedEntities as $relationship) {
                $ids = array_merge($ids, $this->resolveEntityIds($relationship['entities'] ?? [], $nodeIndex));
            }
        }

        return array_values(array_unique($ids));
    }

    private function relationshipMatchesRule(string $type, string $ruleKey): bool
    {
        return match ($ruleKey) {
            'employee_vendor_overlap' => $type === 'employee_vendor_relationship',
            'shared_bank_account' => $type === 'shared_banking',
            'shared_address' => $type === 'shared_address',
            'shared_phone_email' => $type === 'shared_contact',
            'duplicate_vendor_cluster', 'unusual_concentration' => $type === 'duplicate_vendor_identity',
            'vendor_vendor_payment' => $type === 'vendor_vendor_relationship',
            default => str_contains($type, $ruleKey),
        };
    }

    /**
     * @param array<int, array<string, mixed>> $patterns
     * @return array<int, string>
     */
    private function suspiciousNodeIds(array $patterns): array
    {
        $ids = [];
        foreach ($patterns as $pattern) {
            foreach ($pattern['involvedEntities'] ?? [] as $id) {
                $ids[] = (string) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @return array<string, int>
     */
    private function nodesByType(array $nodes): array
    {
        $counts = [
            'vendor' => 0,
            'employee' => 0,
            'bank_account' => 0,
            'company' => 0,
        ];

        foreach ($nodes as $node) {
            $type = (string) ($node['type'] ?? '');
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param array<int, mixed> $entityNames
     * @param array<string, string> $nodeIndex
     * @return array<int, string>
     */
    private function resolveEntityIds(array $entityNames, array $nodeIndex): array
    {
        $ids = [];
        foreach ($entityNames as $name) {
            $lower = strtolower(trim((string) $name));
            $id = $nodeIndex['vendor:'.$lower]
                ?? $nodeIndex['employee:'.$lower]
                ?? $nodeIndex['user:'.$lower]
                ?? $nodeIndex['user:email:'.$lower]
                ?? null;

            if ($id) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array<string, mixed>>
     */
    private function transactionsForNode(string $companyId, array $node): array
    {
        $query = Transaction::where('company_id', $companyId);

        if ($node['type'] === 'vendor') {
            $query->where('vendor_customer', $node['label']);
        } elseif ($node['type'] === 'employee') {
            $email = (string) ($node['metadata']['email'] ?? '');
            $label = (string) ($node['label'] ?? '');
            $query->where(function ($q) use ($label, $email): void {
                if ($label !== '') {
                    $q->where('vendor_customer', 'like', '%'.$label.'%');
                }
                if ($email !== '') {
                    $q->orWhere('vendor_customer', 'like', '%'.$email.'%');
                }
            });
        }

        return $query
            ->orderByDesc('date')
            ->limit(25)
            ->get(['id', 'date', 'vendor_customer', 'amount', 'category', 'type', 'anomaly_flag'])
            ->toArray();
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, array<string, mixed>>
     */
    private function alertsForNode(string $companyId, array $node): array
    {
        if ($node['type'] !== 'vendor') {
            return [];
        }

        $vendorName = (string) $node['label'];

        return Alert::where('company_id', $companyId)
            ->where(function ($query) use ($vendorName): void {
                $query->where('title', 'like', '%'.$vendorName.'%')
                    ->orWhere('detail', 'like', '%'.$vendorName.'%');
            })
            ->limit(10)
            ->get(['id', 'rule_key', 'severity', 'title', 'status'])
            ->toArray();
    }
}
