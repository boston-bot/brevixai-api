<?php

namespace App\Services;

use App\Models\CasePackage;
use App\Models\Investigation;
use App\Models\ReviewEvent;
use App\Models\User;
use App\Support\ProfessionalServicesDisclaimer;
use Exception;
use Illuminate\Support\Facades\DB;

class CasePackageService
{
    public function __construct(private readonly InvestigationPlatformService $investigations) {}

    /** @return array<string, mixed> */
    public function list(BusinessProfileContext $context, string $investigationId): array
    {
        $this->findInvestigation($context, $investigationId);

        $packages = CasePackage::where('company_id', $context->companyId)
            ->whereProfile($context->businessProfileId)
            ->where('investigation_id', $investigationId)
            ->orderByDesc('generated_at')
            ->get()
            ->map(fn (CasePackage $package): array => $this->packagePayload($package))
            ->values()
            ->all();

        return ['packages' => $packages, 'case_packages' => $packages];
    }

    /** @param array<string, mixed> $options */
    public function generate(BusinessProfileContext $context, User $actor, string $investigationId, array $options = []): CasePackage
    {
        $format = (string) ($options['format'] ?? CasePackage::FORMAT_JSON);
        if ($format !== CasePackage::FORMAT_JSON) {
            throw new Exception('Only JSON case packages are currently supported by the normalized package endpoint', 422);
        }

        return DB::transaction(function () use ($context, $actor, $investigationId, $format): CasePackage {
            $investigation = $this->findInvestigation($context, $investigationId);
            $detail = $this->investigations->detail($context, $investigationId);
            if (! $detail) {
                throw new Exception('Investigation not found', 404);
            }

            $manifest = $this->manifest($detail['investigation'], $actor);
            $hash = hash('sha256', json_encode($this->canonicalize($manifest), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $filename = 'investigation-package-'.$investigationId.'-'.now()->format('Y-m-d').'.json';

            $package = CasePackage::create([
                'company_id' => $context->companyId,
                'business_profile_id' => $context->businessProfileId,
                'investigation_id' => $investigationId,
                'format' => $format,
                'status' => CasePackage::STATUS_COMPLETED,
                'title' => 'Case Package: '.$investigation->title,
                'generated_at' => now(),
                'generated_by' => $actor->id,
                'included_sections' => $manifest['included_sections'],
                'included_counts' => $manifest['included_counts'],
                'package_hash' => $hash,
                'filename' => $filename,
                'manifest' => $manifest,
            ]);

            $this->investigations->recordEvent(
                investigation: $investigation,
                eventType: 'case_package_generated',
                actorType: ReviewEvent::ACTOR_USER,
                actorId: (string) $actor->id,
                note: 'Case package generated',
                metadata: [
                    'case_package_id' => $package->id,
                    'format' => $format,
                    'package_hash' => $hash,
                ],
            );

            return $package->fresh();
        });
    }

    private function findInvestigation(BusinessProfileContext $context, string $investigationId): Investigation
    {
        $investigation = Investigation::where('company_id', $context->companyId)
            ->whereProfile($context->businessProfileId)
            ->where('id', $investigationId)
            ->first();

        if (! $investigation) {
            throw new Exception('Investigation not found', 404);
        }

        return $investigation;
    }

    /** @param array<string, mixed> $investigation */
    private function manifest(array $investigation, User $actor): array
    {
        $findings = $investigation['findings'] ?? [];
        $evidenceItems = $investigation['evidence_items'] ?? [];
        $suggestedRecords = $investigation['suggested_records'] ?? [];
        $notes = $investigation['notes'] ?? [];
        $activity = $investigation['activity_timeline'] ?? [];

        return [
            'investigation_id' => $investigation['id'] ?? null,
            'generated_at' => now()->toIso8601String(),
            'generated_by_user_id' => $actor->id,
            'included_sections' => [
                'scope_statement',
                'limitations',
                'findings',
                'supporting_evidence',
                'source_citations',
                'suggested_records',
                'reviewer_notes',
                'activity_timeline',
                'disclaimers',
                'package_manifest',
            ],
            'included_counts' => [
                'findings' => count($findings),
                'evidence_items' => count($evidenceItems),
                'suggested_records' => count($suggestedRecords),
                'reviewer_notes' => count($notes),
                'activity_events' => count($activity),
            ],
            'scope_statement' => $investigation['scopeStatement'] ?? null,
            'limitations' => $investigation['scopeLimitations'] ?? [],
            'findings' => $findings,
            'supporting_evidence' => $evidenceItems,
            'suggested_records' => $suggestedRecords,
            'reviewer_notes' => $notes,
            'activity_timeline' => $activity,
            'disclaimer' => ProfessionalServicesDisclaimer::TEXT,
        ];
    }

    /** @return array<string, mixed> */
    public function packagePayload(CasePackage $package): array
    {
        return [
            'id' => (string) $package->id,
            'investigation_id' => $package->investigation_id,
            'investigationId' => $package->investigation_id,
            'format' => (string) $package->format,
            'status' => (string) $package->status,
            'title' => (string) $package->title,
            'generated_at' => $package->generated_at?->toIso8601String(),
            'generatedAt' => $package->generated_at?->toIso8601String(),
            'generated_by' => $package->generated_by,
            'generatedBy' => $package->generated_by,
            'included_sections' => $package->included_sections ?? [],
            'includedSections' => $package->included_sections ?? [],
            'included_counts' => $package->included_counts ?? [],
            'includedCounts' => $package->included_counts ?? [],
            'package_hash' => $package->package_hash,
            'packageHash' => $package->package_hash,
            'filename' => $package->filename,
            'storage_key' => $package->storage_key,
            'storageKey' => $package->storage_key,
            'manifest' => $package->manifest,
        ];
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
