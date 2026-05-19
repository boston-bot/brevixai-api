<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InvestigationActivityEvent;
use App\Models\InvestigationEvidenceItem;
use App\Services\InvestigationEvidenceService;
use App\Services\InvestigationReportService;
use App\Services\InvestigationService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class InvestigationController extends Controller
{
    public function __construct(
        private readonly InvestigationService $investigationService,
        private readonly InvestigationEvidenceService $evidenceService,
        private readonly InvestigationReportService $reportService,
    ) {}

    /**
     * GET /api/investigations
     */
    public function index(Request $request): JsonResponse
    {
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

        $data = $this->investigationService->list($companyId, $validated);

        return response()->json($data);
    }

    /**
     * GET /api/investigations/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $detail = $this->investigationService->detail($companyId, $id);
        if (! $detail) {
            return response()->json(['error' => 'Investigation not found'], 404);
        }

        return response()->json($detail);
    }

    /**
     * POST /api/investigations/{id}/assign
     */
    public function assign(Request $request, string $id): JsonResponse
    {
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
        } catch (Exception $e) {
            $status = match ($e->getCode()) {
                404 => 404,
                422 => 422,
                default => 500,
            };

            return response()->json(['error' => $e->getMessage()], $status);
        }

        return response()->json($result);
    }

    /**
     * POST /api/investigations/{id}/status
     */
    public function status(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $validated = $request->validate([
            'investigation_status' => ['required', 'string', Rule::in(['open', 'in_review', 'escalated', 'resolved', 'archived'])],
        ]);

        try {
            $result = $this->investigationService->updateStatus(
                $companyId,
                $request->user()->id,
                $id,
                $validated['investigation_status'],
            );
        } catch (Exception $e) {
            $status = match ($e->getCode()) {
                404 => 404,
                422 => 422,
                default => 500,
            };

            return response()->json(['error' => $e->getMessage()], $status);
        }

        return response()->json($result);
    }

    /**
     * POST /api/investigations/{id}/notes
     */
    public function notes(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $validated = $request->validate([
            'notes' => ['required', 'string', 'max:10000'],
        ]);

        try {
            $result = $this->investigationService->addNotes(
                $companyId,
                $request->user()->id,
                $id,
                $validated['notes'],
            );
        } catch (Exception $e) {
            $status = match ($e->getCode()) {
                404 => 404,
                default => 500,
            };

            return response()->json(['error' => $e->getMessage()], $status);
        }

        return response()->json($result);
    }

    /**
     * GET /api/investigations/{id}/evidence
     */
    public function listEvidence(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        try {
            $result = $this->evidenceService->list($companyId, $id);
        } catch (Exception $e) {
            $status = match ($e->getCode()) {
                404 => 404,
                default => 500,
            };

            return response()->json(['error' => $e->getMessage()], $status);
        }

        return response()->json($result);
    }

    /**
     * POST /api/investigations/{id}/evidence
     */
    public function addEvidence(Request $request, string $id): JsonResponse
    {
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
        } catch (Exception $e) {
            $status = match ($e->getCode()) {
                404 => 404,
                403 => 403,
                default => 500,
            };

            return response()->json(['error' => $e->getMessage()], $status);
        }

        return response()->json($result, 201);
    }

    /**
     * DELETE /api/investigations/{id}/evidence/{evidenceItemId}
     */
    public function removeEvidence(Request $request, string $id, string $evidenceItemId): JsonResponse
    {
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
        } catch (Exception $e) {
            $status = match ($e->getCode()) {
                404 => 404,
                403 => 403,
                default => 500,
            };

            return response()->json(['error' => $e->getMessage()], $status);
        }

        return response()->json(['deleted' => true]);
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
                $pdfBytes = $this->reportService->generatePdf(
                    companyId: $companyId,
                    caseId: $id,
                    actorType: InvestigationActivityEvent::ACTOR_USER,
                    actorId: $request->user()->id,
                );

                $filename = 'investigation-report-' . $id . '-' . now()->format('Y-m-d') . '.pdf';

                return response($pdfBytes, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                ]);
            }

            $result = $this->reportService->generate(
                companyId: $companyId,
                caseId: $id,
                actorType: InvestigationActivityEvent::ACTOR_USER,
                actorId: $request->user()->id,
            );
        } catch (Exception $e) {
            $status = match ($e->getCode()) {
                404 => 404,
                403 => 403,
                default => 500,
            };

            return response()->json(['error' => $e->getMessage()], $status);
        }

        return response()->json($result);
    }
}
