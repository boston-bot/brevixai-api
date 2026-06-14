<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\IrsTaxNoticeService;
use App\Services\SourceFindingAdapterService;
use App\Services\SourceFindingMaterializationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class TaxNoticeController extends Controller
{
    public function __construct(
        private readonly IrsTaxNoticeService $taxNoticeService,
        private readonly SourceFindingAdapterService $sourceFindingAdapter,
        private readonly SourceFindingMaterializationService $sourceFindingMaterialization,
    ) {}

    public function interpret(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'notice_text' => 'required|string|min:20|max:10000',
                'persist_finding' => 'sometimes|boolean',
                'investigation_id' => 'sometimes|nullable|uuid',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['error' => 'Invalid request.', 'details' => $e->errors()], 422);
        }

        try {
            $result = $this->taxNoticeService->interpretNotice($validated['notice_text']);
            $normalized = $this->sourceFindingAdapter->taxNoticeFinding($result, $validated['notice_text']);
            $response = array_merge($result, [
                'normalizedFinding' => $normalized['finding'],
                'normalizedEvidenceItems' => $normalized['evidenceItems'],
                'normalizedSuggestedRecords' => $normalized['suggestedRecords'],
            ]);

            if ((bool) ($validated['persist_finding'] ?? false)) {
                $context = $this->resolveBusinessProfileContext($request);
                if ($context instanceof JsonResponse) {
                    return $context;
                }

                $materialized = $this->sourceFindingMaterialization->materializePayload(
                    $context,
                    $request->user(),
                    [
                        'findings' => [$normalized['finding']],
                        'evidenceItems' => $normalized['evidenceItems'],
                        'suggestedRecords' => $normalized['suggestedRecords'],
                    ],
                    $validated['investigation_id'] ?? null,
                );

                $response['materialization'] = $materialized['materialized'];
                $response['persistedFinding'] = $materialized['findings'][0] ?? null;
            }

            return response()->json($response);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            $status = in_array($e->getCode(), [404, 422, 503], true) ? $e->getCode() : 503;

            return response()->json([
                'error' => $status === 503 ? 'Tax notice interpretation is temporarily unavailable.' : $e->getMessage(),
            ], $status);
        }
    }
}
