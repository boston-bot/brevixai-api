<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InvestigationActivityEvent;
use App\Models\InvestigationEvidenceItem;
use App\Services\CasePackageService;
use App\Services\InvestigationPlatformService;
use App\Services\InvestigationPlatformContractService;
use App\Services\InvestigationEvidenceService;
use App\Services\InvestigationPackageManifestService;
use App\Services\InvestigationReportService;
use App\Services\InvestigationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Throwable;

class InvestigationController extends Controller
{
    public function __construct(
        private readonly InvestigationService $investigationService,
        private readonly InvestigationEvidenceService $evidenceService,
        private readonly InvestigationReportService $reportService,
        private readonly InvestigationPackageManifestService $packageManifestService,
        private readonly InvestigationPlatformService $platformService,
        private readonly InvestigationPlatformContractService $contractService,
        private readonly CasePackageService $casePackageService,
    ) {}

    /**
     * GET /api/investigations
     */
    public function index(Request $request): JsonResponse
    {
        if ($this->canonicalAvailable()) {
            $context = $this->resolveBusinessProfileContext($request);
            if ($context instanceof JsonResponse) {
                return $context;
            }

            $validated = $request->validate([
                'status' => ['sometimes', 'string', 'max:80'],
                'investigation_status' => ['sometimes', 'string', 'max:80'],
                'priority' => ['sometimes', 'string', 'max:80'],
                'investigation_priority' => ['sometimes', 'string', 'max:80'],
                'category' => ['sometimes', 'string', 'max:80'],
                'assigned_to' => ['sometimes', 'uuid'],
                'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
                'offset' => ['sometimes', 'integer', 'min:0'],
            ]);

            return response()->json($this->platformService->list($context, $validated));
        }

        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $validated = $request->validate([
            'investigation_status' => ['sometimes', 'string', Rule::in(['all', 'open', 'in_review', 'escalated', 'resolved', 'archived'])],
            'investigation_priority' => ['sometimes', 'string', Rule::in(['critical', 'high', 'medium', 'low'])],
            'assigned_to' => ['sometimes', 'uuid'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ]);

        try {
            $data = $this->investigationService->list($companyId, $validated);
        } catch (Throwable $e) {
            return $this->safeServiceError($e, 'investigation_list');
        }

        return response()->json($data);
    }

    /**
     * POST /api/investigations
     */
    public function store(Request $request): JsonResponse
    {
        if (! $this->canonicalAvailable()) {
            return response()->json(['error' => 'Canonical investigations are not available'], 503);
        }

        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'category' => ['sometimes', 'string', 'max:80'],
            'subcategory' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'string', 'max:80'],
            'priority' => ['sometimes', 'string', 'max:80'],
            'reviewPeriodStart' => ['sometimes', 'nullable', 'date'],
            'reviewPeriodEnd' => ['sometimes', 'nullable', 'date'],
            'review_period_start' => ['sometimes', 'nullable', 'date'],
            'review_period_end' => ['sometimes', 'nullable', 'date'],
            'scopeStatement' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'scope_statement' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'scopeLimitations' => ['sometimes', 'array'],
            'scope_limitations' => ['sometimes', 'array'],
            'assignedTo' => ['sometimes', 'nullable', 'uuid'],
            'assigned_to' => ['sometimes', 'nullable', 'uuid'],
            'metadata' => ['sometimes', 'array'],
        ]);

        try {
            $investigation = $this->platformService->create($context, $request->user(), $validated);
        } catch (Throwable $e) {
            return $this->safeServiceError($e, 'investigation_create', [403, 404, 422]);
        }

        return response()->json([
            'investigation' => $this->platformService->investigationPayload($context, $investigation),
        ], 201);
    }

    /**
     * GET /api/investigations/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        if ($this->canonicalAvailable()) {
            $context = $this->resolveBusinessProfileContext($request);
            if ($context instanceof JsonResponse) {
                return $context;
            }

            try {
                $detail = $this->platformService->detail($context, $id);
            } catch (Throwable $e) {
                return $this->safeServiceError($e, 'investigation_detail');
            }

            if ($detail) {
                return response()->json($detail);
            }
        }

        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        try {
            $detail = $this->investigationService->detail($companyId, $id);
        } catch (Throwable $e) {
            return $this->safeServiceError($e, 'investigation_detail');
        }

        if (! $detail) {
            return response()->json(['error' => 'Investigation not found'], 404);
        }

        return response()->json($detail);
    }

    /**
     * PATCH /api/investigations/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        if (! $this->canonicalAvailable()) {
            return response()->json(['error' => 'Canonical investigations are not available'], 503);
        }

        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:500'],
            'category' => ['sometimes', 'string', 'max:80'],
            'subcategory' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'string', 'max:80'],
            'priority' => ['sometimes', 'string', 'max:80'],
            'reviewPeriodStart' => ['sometimes', 'nullable', 'date'],
            'reviewPeriodEnd' => ['sometimes', 'nullable', 'date'],
            'review_period_start' => ['sometimes', 'nullable', 'date'],
            'review_period_end' => ['sometimes', 'nullable', 'date'],
            'scopeStatement' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'scope_statement' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'scopeLimitations' => ['sometimes', 'array'],
            'scope_limitations' => ['sometimes', 'array'],
            'assignedTo' => ['sometimes', 'nullable', 'uuid'],
            'assigned_to' => ['sometimes', 'nullable', 'uuid'],
        ]);

        try {
            $investigation = $this->platformService->update($context, $request->user(), $id, $validated);
        } catch (Throwable $e) {
            return $this->safeServiceError($e, 'investigation_update', [403, 404, 422]);
        }

        if (! $investigation) {
            return response()->json(['error' => 'Investigation not found'], 404);
        }

        return response()->json([
            'investigation' => $this->platformService->investigationPayload($context, $investigation),
        ]);
    }

    /**
     * POST /api/investigations/{id}/assign
     */
    public function assign(Request $request, string $id): JsonResponse
    {
        if ($this->canonicalAvailable()) {
            $context = $this->resolveBusinessProfileContext($request);
            if ($context instanceof JsonResponse) {
                return $context;
            }

            $validated = $request->validate([
                'assignee_id' => ['required', 'uuid'],
            ]);

            try {
                $investigation = $this->platformService->update($context, $request->user(), $id, [
                    'assigned_to' => $validated['assignee_id'],
                ]);
            } catch (Throwable $e) {
                return $this->safeServiceError($e, 'investigation_assign', [404, 422]);
            }

            if ($investigation) {
                $payload = $this->platformService->investigationPayload($context, $investigation);

                return response()->json(['investigation' => $payload, 'case' => $payload]);
            }
        }

        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $validated = $request->validate([
            'assignee_id' => ['required', 'uuid'],
        ]);

        try {
            $result = $this->investigationService->assign(
                $companyId,
                $request->user()->id,
                $id,
                $validated['assignee_id'],
            );
        } catch (Throwable $e) {
            return $this->safeServiceError($e, 'investigation_assign', [404, 422]);
        }

        return response()->json($result);
    }

    /**
     * POST /api/investigations/{id}/status
     */
    public function status(Request $request, string $id): JsonResponse
    {
        if ($this->canonicalAvailable()) {
            $context = $this->resolveBusinessProfileContext($request);
            if ($context instanceof JsonResponse) {
                return $context;
            }

            $validated = $request->validate([
                'status' => ['sometimes', 'required_without:investigation_status', 'string', 'max:80'],
                'investigation_status' => ['sometimes', 'required_without:status', 'string', 'max:80'],
            ]);
            $nextStatus = $validated['status'] ?? $validated['investigation_status'];

            try {
                $investigation = $this->platformService->updateStatus($context, $request->user(), $id, $nextStatus);
            } catch (Throwable $e) {
                return $this->safeServiceError($e, 'investigation_status', [404, 422]);
            }

            if ($investigation) {
                $payload = $this->platformService->investigationPayload($context, $investigation);

                return response()->json(['investigation' => $payload, 'case' => $payload]);
            }
        }

        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'required_without:investigation_status', 'string', Rule::in(['open', 'in_progress', 'pending_review', 'closed'])],
            'investigation_status' => ['sometimes', 'required_without:status', 'string', Rule::in(['open', 'in_review', 'escalated', 'resolved', 'archived'])],
        ]);

        $legacyStatus = $validated['investigation_status'] ?? match ($validated['status'] ?? null) {
            'in_progress', 'pending_review' => 'in_review',
            'closed' => 'resolved',
            default => $validated['status'] ?? null,
        };

        try {
            $result = $this->investigationService->updateStatus(
                $companyId,
                $request->user()->id,
                $id,
                $legacyStatus,
            );
        } catch (Throwable $e) {
            return $this->safeServiceError($e, 'investigation_status', [404, 422]);
        }

        return response()->json($result);
    }

    /**
     * POST /api/investigations/{id}/notes
     */
    public function notes(Request $request, string $id): JsonResponse
    {
        if ($this->canonicalAvailable()) {
            $context = $this->resolveBusinessProfileContext($request);
            if ($context instanceof JsonResponse) {
                return $context;
            }

            $validated = $request->validate([
                'body' => ['sometimes', 'required_without:notes', 'string', 'max:10000'],
                'notes' => ['sometimes', 'required_without:body', 'string', 'max:10000'],
                'finding_id' => ['sometimes', 'nullable', 'uuid'],
                'findingId' => ['sometimes', 'nullable', 'uuid'],
            ]);
            $body = $validated['body'] ?? $validated['notes'];
            $findingId = $validated['finding_id'] ?? $validated['findingId'] ?? null;

            try {
                $note = $this->platformService->addNote($context, $request->user(), $id, $body, $findingId);
            } catch (Throwable $e) {
                return $this->safeServiceError($e, 'investigation_notes', [404, 422]);
            }

            if ($note) {
                return response()->json(['note' => $note]);
            }
        }

        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $validated = $request->validate([
            'body' => ['sometimes', 'required_without:notes', 'string', 'max:10000'],
            'notes' => ['sometimes', 'required_without:body', 'string', 'max:10000'],
        ]);

        try {
            $result = $this->investigationService->addNotes(
                $companyId,
                $request->user()->id,
                $id,
                $validated['notes'] ?? $validated['body'],
            );
        } catch (Throwable $e) {
            return $this->safeServiceError($e, 'investigation_notes', [404]);
        }

        return response()->json($result);
    }

    /**
     * GET /api/investigations/{id}/evidence
     */
    public function listEvidence(Request $request, string $id): JsonResponse
    {
        if ($this->canonicalAvailable()) {
            $context = $this->resolveBusinessProfileContext($request);
            if ($context instanceof JsonResponse) {
                return $context;
            }

            try {
                return response()->json($this->platformService->listEvidence($context, $id));
            } catch (Throwable $e) {
                if ($e->getCode() !== 404) {
                    return $this->safeServiceError($e, 'investigation_evidence_list', [404]);
                }
            }
        }

        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        try {
            $result = $this->evidenceService->list($companyId, $id);
        } catch (Throwable $e) {
            return $this->safeServiceError($e, 'investigation_evidence_list', [404]);
        }

        return response()->json($result);
    }

    /**
     * POST /api/investigations/{id}/evidence
     */
    public function addEvidence(Request $request, string $id): JsonResponse
    {
        if ($this->canonicalAvailable()) {
            $context = $this->resolveBusinessProfileContext($request);
            if ($context instanceof JsonResponse) {
                return $context;
            }

            $validated = $request->validate([
                'evidence_type' => ['required', 'string', 'max:120'],
                'finding_id' => ['sometimes', 'nullable', 'uuid'],
                'findingId' => ['sometimes', 'nullable', 'uuid'],
                'source' => ['sometimes', 'nullable', 'string', 'max:500'],
                'source_type' => ['sometimes', 'nullable', 'string', 'max:120'],
                'sourceType' => ['sometimes', 'nullable', 'string', 'max:120'],
                'source_id' => ['sometimes', 'nullable', 'string', 'max:255'],
                'sourceId' => ['sometimes', 'nullable', 'string', 'max:255'],
                'source_record_id' => ['sometimes', 'nullable', 'string', 'max:255'],
                'sourceRecordId' => ['sometimes', 'nullable', 'string', 'max:255'],
                'evidence_reference_id' => ['sometimes', 'nullable', 'string', 'max:255'],
                'title' => ['required', 'string', 'max:500'],
                'summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'citation_label' => ['sometimes', 'nullable', 'string', 'max:255'],
                'citationLabel' => ['sometimes', 'nullable', 'string', 'max:255'],
                'source_row_range' => ['sometimes', 'nullable', 'string', 'max:255'],
                'sourceRowRange' => ['sometimes', 'nullable', 'string', 'max:255'],
                'file_name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'fileName' => ['sometimes', 'nullable', 'string', 'max:255'],
                'storage_key' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'storageKey' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'hash' => ['sometimes', 'nullable', 'string', 'max:255'],
                'metadata' => ['sometimes', 'nullable', 'array'],
            ]);

            try {
                $item = $this->platformService->addEvidence($context, $request->user(), $id, $validated);
                return response()->json(['evidence_item' => $item], 201);
            } catch (Throwable $e) {
                if ($e->getCode() !== 404) {
                    return $this->safeServiceError($e, 'investigation_evidence_add', [403, 404, 422]);
                }
            }
        }

        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $validated = $request->validate([
            'evidence_type' => ['required', 'string', Rule::in([
                InvestigationEvidenceItem::TYPE_TRANSACTION,
                InvestigationEvidenceItem::TYPE_VENDOR,
                InvestigationEvidenceItem::TYPE_ALERT,
                InvestigationEvidenceItem::TYPE_RECOMMENDATION,
                InvestigationEvidenceItem::TYPE_NOTE,
                InvestigationEvidenceItem::TYPE_DOCUMENT,
                InvestigationEvidenceItem::TYPE_SYSTEM_FINDING,
            ])],
            'evidence_reference_id' => ['sometimes', 'nullable', 'uuid'],
            'title' => ['required', 'string', 'max:500'],
            'summary' => ['required', 'string', 'max:5000'],
            'source' => ['required', 'string', 'max:500'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]);

        try {
            $result = $this->evidenceService->add(
                companyId: $companyId,
                actorType: InvestigationEvidenceItem::ACTOR_USER,
                actorId: $request->user()->id,
                caseId: $id,
                data: $validated,
            );
        } catch (Throwable $e) {
            return $this->safeServiceError($e, 'investigation_evidence_add', [403, 404]);
        }

        return response()->json($result, 201);
    }

    /**
     * DELETE /api/investigations/{id}/evidence/{evidenceItemId}
     */
    public function removeEvidence(Request $request, string $id, string $evidenceItemId): JsonResponse
    {
        if ($this->canonicalAvailable()) {
            $context = $this->resolveBusinessProfileContext($request);
            if ($context instanceof JsonResponse) {
                return $context;
            }

            $deleted = $this->platformService->removeEvidence($context, $request->user(), $id, $evidenceItemId);
            if ($deleted) {
                return response()->json(['deleted' => true]);
            }
        }

        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        try {
            $this->evidenceService->remove(
                companyId: $companyId,
                actorType: InvestigationEvidenceItem::ACTOR_USER,
                actorId: $request->user()->id,
                caseId: $id,
                evidenceItemId: $evidenceItemId,
            );
        } catch (Throwable $e) {
            return $this->safeServiceError($e, 'investigation_evidence_remove', [403, 404]);
        }

        return response()->json(['deleted' => true]);
    }

    /**
     * GET /api/investigations/{id}/reports
     */
    public function reportExports(Request $request, string $id): JsonResponse
    {
        if ($this->canonicalAvailable()) {
            $context = $this->resolveBusinessProfileContext($request);
            if ($context instanceof JsonResponse) {
                return $context;
            }

            try {
                $packages = $this->casePackageService->list($context, $id)['packages'];
                return response()->json(['report_exports' => $packages, 'packages' => $packages]);
            } catch (Throwable $e) {
                if ($e->getCode() !== 404) {
                    return $this->safeServiceError($e, 'investigation_report_exports');
                }
            }
        }

        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        try {
            $result = $this->investigationService->reportExports($companyId, $id);
        } catch (Throwable $e) {
            return $this->safeServiceError($e, 'investigation_report_exports');
        }

        if (! $result) {
            return response()->json(['error' => 'Investigation not found'], 404);
        }

        return response()->json($result);
    }

    /**
     * POST /api/investigations/{id}/package-manifest
     *
     * User-triggered only. Generates a non-persistent sanitized manifest for
     * investigation export package review.
     */
    public function generatePackageManifest(Request $request, string $id): JsonResponse
    {
        if ($this->canonicalAvailable()) {
            $context = $this->resolveBusinessProfileContext($request);
            if ($context instanceof JsonResponse) {
                return $context;
            }

            $validated = $request->validate([
                'format' => ['required', 'string', Rule::in(['json'])],
            ]);

            try {
                $package = $this->casePackageService->generate($context, $request->user(), $id, $validated);
                return response()->json([
                    'manifest' => $package->manifest,
                    'package' => $this->casePackageService->packagePayload($package),
                ]);
            } catch (Throwable $e) {
                if ($e->getCode() !== 404) {
                    return $this->safeServiceError($e, 'investigation_package_manifest', [403, 404, 422]);
                }
            }
        }

        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $validated = $request->validate([
            'format' => ['required', 'string', Rule::in(['json'])],
        ]);

        try {
            $result = $this->packageManifestService->generate(
                companyId: $companyId,
                caseId: $id,
                actorType: InvestigationActivityEvent::ACTOR_USER,
                actorId: $request->user()->id,
                format: $validated['format'],
            );
        } catch (Throwable $e) {
            return $this->safeServiceError($e, 'investigation_package_manifest', [403, 404, 422]);
        }

        return response()->json($result);
    }

    /**
     * POST /api/investigations/{id}/reports
     *
     * Accepts format=json (default) or format=pdf.
     * PDF returns a downloadable application/pdf response.
     * User-triggered only — agents are blocked at the service layer.
     */
    public function generateReport(Request $request, string $id): JsonResponse|Response
    {
        if ($this->canonicalAvailable() && $request->input('format', 'json') === 'json') {
            $context = $this->resolveBusinessProfileContext($request);
            if ($context instanceof JsonResponse) {
                return $context;
            }

            try {
                $package = $this->casePackageService->generate($context, $request->user(), $id, ['format' => 'json']);
                return response()->json(['report' => $package->manifest, 'package' => $this->casePackageService->packagePayload($package)]);
            } catch (Throwable $e) {
                if ($e->getCode() !== 404) {
                    return $this->safeServiceError($e, 'investigation_report_generate', [403, 404, 422]);
                }
            }
        }

        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $validated = $request->validate([
            'format' => ['sometimes', 'string', Rule::in(['json', 'pdf'])],
        ]);

        $format = $validated['format'] ?? 'json';

        try {
            if ($format === 'pdf') {
                $filename = 'investigation-report-'.$id.'-'.now()->format('Y-m-d').'.pdf';

                $pdfBytes = $this->reportService->generatePdf(
                    companyId: $companyId,
                    caseId: $id,
                    actorType: InvestigationActivityEvent::ACTOR_USER,
                    actorId: $request->user()->id,
                    filename: $filename,
                );

                return response($pdfBytes, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                ]);
            }

            $result = $this->reportService->generate(
                companyId: $companyId,
                caseId: $id,
                actorType: InvestigationActivityEvent::ACTOR_USER,
                actorId: $request->user()->id,
            );
        } catch (Throwable $e) {
            return $this->safeServiceError($e, 'investigation_report_generate', [403, 404]);
        }

        return response()->json($result);
    }

    /**
     * GET /api/investigations/{id}/packages
     */
    public function packages(Request $request, string $id): JsonResponse
    {
        if ($this->canonicalAvailable()) {
            $context = $this->resolveBusinessProfileContext($request);
            if ($context instanceof JsonResponse) {
                return $context;
            }

            try {
                return response()->json($this->casePackageService->list($context, $id));
            } catch (Throwable $e) {
                if ($e->getCode() !== 404) {
                    return $this->safeServiceError($e, 'case_package_list', [404]);
                }
            }
        }

        return $this->legacyContractPackages($request, $id);
    }

    /**
     * POST /api/investigations/{id}/packages
     */
    public function generatePackage(Request $request, string $id): JsonResponse
    {
        if (! $this->canonicalAvailable()) {
            return response()->json(['error' => 'Canonical packages are not available'], 503);
        }

        $context = $this->resolveBusinessProfileContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $validated = $request->validate([
            'format' => ['sometimes', 'string', Rule::in(['json'])],
        ]);

        try {
            $package = $this->casePackageService->generate($context, $request->user(), $id, $validated);
        } catch (Throwable $e) {
            return $this->safeServiceError($e, 'case_package_generate', [403, 404, 422]);
        }

        return response()->json(['package' => $this->casePackageService->packagePayload($package)], 201);
    }

    /**
     * @param  array<int, int>  $safeStatusCodes
     */
    private function safeServiceError(
        Throwable $e,
        string $operation,
        array $safeStatusCodes = [403, 404, 422],
    ): JsonResponse {
        $status = (int) $e->getCode();

        if (in_array($status, $safeStatusCodes, true)) {
            return response()->json(['error' => $e->getMessage()], $status);
        }

        Log::warning('investigation_workspace.failed', [
            'operation' => $operation,
            'error_class' => $e::class,
            'error_code' => $status ?: null,
        ]);

        return response()->json([
            'error' => 'Investigation request could not be completed safely',
        ], 500);
    }

    private function canonicalAvailable(): bool
    {
        return Schema::hasTable('investigations');
    }

    private function legacyContractPackages(Request $request, string $id): JsonResponse
    {
        if (! Schema::hasTable('audit_cases')) {
            return response()->json(['error' => 'Investigation not found'], 404);
        }

        $companyId = $request->user()?->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $payload = $this->contractService->casePackages($companyId, $id);
        if (! $payload) {
            return response()->json(['error' => 'Investigation not found'], 404);
        }

        return response()->json($payload);
    }
}
