<?php

namespace App\Http\Controllers\FraudTesting;

use App\Http\Controllers\Controller;
use App\Models\FraudTesting\FraudScenarioImport;
use App\Services\FraudTesting\FraudWorkbookImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FraudScenarioImportController extends Controller
{
    public function __construct(
        protected readonly FraudWorkbookImportService $importService,
    ) {}

    /**
     * POST /api/internal/fraud-testing/imports
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240',
        ]);

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension());
        $contents = file_get_contents($file->getRealPath());

        $import = $this->importService->importFromContents(
            $contents,
            $extension,
            null,
            $originalName,
        );

        return response()->json([
            'import_id' => $import->id,
            'status' => $import->status,
            'total_rows' => $import->total_rows,
            'successful_rows' => $import->successful_rows,
            'failed_rows' => $import->failed_rows,
            'validation_errors' => $import->validation_errors ?? [],
        ], 201);
    }

    /**
     * GET /api/internal/fraud-testing/imports
     */
    public function index(): JsonResponse
    {
        $imports = FraudScenarioImport::orderByDesc('created_at')
            ->select(['id', 'original_filename', 'status', 'total_rows', 'successful_rows', 'failed_rows', 'created_at', 'completed_at'])
            ->paginate(25);

        return response()->json($imports);
    }

    /**
     * GET /api/internal/fraud-testing/imports/{id}
     */
    public function show(string $id): JsonResponse
    {
        $import = FraudScenarioImport::find($id);
        if (! $import) {
            return response()->json(['error' => 'Import not found'], 404);
        }

        return response()->json([
            'import' => $import,
            'submissions' => $import->submissions()
                ->select(['id', 'external_scenario_id', 'title', 'status', 'extraction_status', 'mock_data_status', 'row_number'])
                ->get(),
        ]);
    }
}
