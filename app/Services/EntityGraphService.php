<?php

namespace App\Services;

use App\Models\Alert;
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

        // User nodes
        $users = User::where('company_id', $companyId)->get(['id', 'first_name', 'last_name', 'email', 'role']);
        foreach ($users as $user) {
            $nodeId = $user->id;
            $nodes[] = [
                'id' => $nodeId,
                'type' => 'user',
                'label' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email,
                'metadata' => [
                    'email' => $user->email,
                    'role' => $user->role,
                ],
            ];
            $nodeIndex['user:' . strtolower(trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')))] = $nodeId;
            $nodeIndex['user:email:' . strtolower($user->email)] = $nodeId;
        }

        // Vendor nodes (unique vendor names from transactions)
        $vendors = Transaction::where('company_id', $companyId)
            ->whereNotNull('vendor_customer')
            ->distinct()
            ->pluck('vendor_customer');

        foreach ($vendors as $vendor) {
            $nodeId = $this->vendorNodeId($vendor);
            $nodes[] = [
                'id' => $nodeId,
                'type' => 'vendor',
                'label' => $vendor,
                'metadata' => [],
            ];
            $nodeIndex['vendor:' . strtolower($vendor)] = $nodeId;
        }

        // Risk finding nodes
        foreach ($scoring['triggered_rules'] as $rule) {
            $nodeId = 'finding_' . $rule['rule_key'];
            $nodes[] = [
                'id' => $nodeId,
                'type' => 'finding',
                'label' => $rule['name'],
                'metadata' => [
                    'rule_key' => $rule['rule_key'],
                    'weight' => $rule['weight'],
                    'explanation' => $rule['explanation'],
                ],
            ];
        }

        // Edges from related_entities
        $edgeSeq = 0;
        foreach ($scoring['related_entities'] as $rel) {
            $entityIds = $this->resolveEntityIds($rel['entities'], $nodeIndex);
            if (count($entityIds) < 2) {
                continue;
            }
            $ruleKey = $rel['type'];
            $findingNodeId = 'finding_' . $ruleKey;
            $findingExists = collect($nodes)->contains('id', $findingNodeId);

            for ($i = 0; $i < count($entityIds) - 1; $i++) {
                $edgeSeq++;
                $edges[] = [
                    'id' => 'edge_' . $edgeSeq,
                    'source' => $entityIds[$i],
                    'target' => $entityIds[$i + 1],
                    'type' => $ruleKey,
                    'label' => $rel['description'],
                ];
            }

            // Link the entity pair to the finding node if present
            if ($findingExists && $entityIds[0] !== $findingNodeId) {
                $edgeSeq++;
                $edges[] = [
                    'id' => 'edge_' . $edgeSeq,
                    'source' => $entityIds[0],
                    'target' => $findingNodeId,
                    'type' => $ruleKey,
                    'label' => $rel['description'],
                ];
            }
        }

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'summary' => [
                'risk_score' => $scoring['entity_relationship_risk_score'],
                'risk_level' => $scoring['risk_level'],
                'node_count' => count($nodes),
                'edge_count' => count($edges),
                'recommended_next_action' => $scoring['recommended_next_action'],
            ],
        ];
    }

    public function getNode(string $companyId, string $nodeId): ?array
    {
        // User node
        $user = User::where('id', $nodeId)->where('company_id', $companyId)->first();
        if ($user) {
            $txns = Transaction::where('company_id', $companyId)
                ->where(function ($q) use ($user) {
                    $fullName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
                    if ($fullName) {
                        $q->where('vendor_customer', 'like', '%' . $fullName . '%');
                    }
                    $q->orWhere('vendor_customer', 'like', '%' . $user->email . '%');
                })
                ->limit(25)
                ->get(['id', 'date', 'vendor_customer', 'amount', 'category', 'type'])
                ->toArray();

            return [
                'node' => [
                    'id' => $user->id,
                    'type' => 'user',
                    'label' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email,
                    'metadata' => ['email' => $user->email, 'role' => $user->role],
                ],
                'transactions' => $txns,
            ];
        }

        // Vendor node (look up by vendor name hash)
        $vendor = Transaction::where('company_id', $companyId)
            ->whereNotNull('vendor_customer')
            ->get(['vendor_customer'])
            ->first(fn ($t) => $this->vendorNodeId($t->vendor_customer) === $nodeId);

        if ($vendor) {
            $vendorName = $vendor->vendor_customer;
            $txns = Transaction::where('company_id', $companyId)
                ->where('vendor_customer', $vendorName)
                ->limit(25)
                ->get(['id', 'date', 'vendor_customer', 'amount', 'category', 'type'])
                ->toArray();

            $alerts = Alert::where('company_id', $companyId)
                ->where(function ($q) use ($vendorName) {
                    $q->where('title', 'like', '%' . $vendorName . '%')
                      ->orWhere('detail', 'like', '%' . $vendorName . '%');
                })
                ->limit(10)
                ->get(['id', 'rule_key', 'severity', 'title', 'status'])
                ->toArray();

            return [
                'node' => [
                    'id' => $nodeId,
                    'type' => 'vendor',
                    'label' => $vendorName,
                    'metadata' => [],
                ],
                'transactions' => $txns,
                'alerts' => $alerts,
            ];
        }

        // Finding node
        if (str_starts_with($nodeId, 'finding_')) {
            $ruleKey = substr($nodeId, strlen('finding_'));
            $scoring = $this->scoringService->scoreEntityRelationships($companyId);
            $rule = collect($scoring['triggered_rules'])->firstWhere('rule_key', $ruleKey);
            if (! $rule) {
                return null;
            }
            $evidence = $scoring['supporting_evidence'][$ruleKey] ?? [];

            return [
                'node' => [
                    'id' => $nodeId,
                    'type' => 'finding',
                    'label' => $rule['name'],
                    'metadata' => [
                        'rule_key' => $ruleKey,
                        'weight' => $rule['weight'],
                        'explanation' => $rule['explanation'],
                    ],
                ],
                'evidence' => $evidence,
            ];
        }

        return null;
    }

    private function vendorNodeId(string $vendorName): string
    {
        return 'v_' . substr(hash('sha256', $vendorName), 0, 16);
    }

    private function resolveEntityIds(array $entityNames, array $nodeIndex): array
    {
        $ids = [];
        foreach ($entityNames as $name) {
            $lower = strtolower(trim($name));
            $id = $nodeIndex['vendor:' . $lower]
                ?? $nodeIndex['user:' . $lower]
                ?? $nodeIndex['user:email:' . $lower]
                ?? null;
            if ($id) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }
}
