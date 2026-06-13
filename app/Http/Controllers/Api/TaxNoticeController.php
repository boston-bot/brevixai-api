<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\IrsTaxNoticeService;
use App\Services\SourceFindingAdapterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class TaxNoticeController extends Controller
{
    public function __construct(
        private readonly IrsTaxNoticeService $taxNoticeService,
        private readonly SourceFindingAdapterService $sourceFindingAdapter,
    ) {}

    public function interpret(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'notice_text' => 'required|string|min:20|max:10000',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['error' => 'Invalid request.', 'details' => $e->errors()], 422);
        }

        try {
            $result = $this->taxNoticeService->interpretNotice($validated['notice_text']);
            $normalized = $this->sourceFindingAdapter->taxNoticeFinding($result, $validated['notice_text']);

            return response()->json(array_merge($result, [
                'normalizedFinding' => $normalized['finding'],
                'normalizedEvidenceItems' => $normalized['evidenceItems'],
                'normalizedSuggestedRecords' => $normalized['suggestedRecords'],
            ]));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['error' => 'Tax notice interpretation is temporarily unavailable.'], 503);
        }
    }
}
