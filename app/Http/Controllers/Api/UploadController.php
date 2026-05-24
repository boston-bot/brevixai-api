<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Upload;
use App\Models\UploadRowError;
use App\Services\UploadService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UploadController extends Controller
{
    protected UploadService $uploadService;

    public function __construct(UploadService $uploadService)
    {
        $this->uploadService = $uploadService;
    }

    /**
     * GET /api/uploads
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $data = $this->uploadService->list($companyId);

        return response()->json($data);
    }

    /**
     * GET /api/uploads/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $detail = $this->uploadService->getDetail($companyId, $id);
        if (! $detail) {
            return response()->json(['error' => 'Upload not found'], 404);
        }

        return response()->json($detail);
    }

    /**
     * POST /api/uploads
     */
    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $request->validate([
            'importType' => ['required', 'string', Rule::in(UploadService::SUPPORTED_IMPORT_TYPES)],
            'originalFilename' => 'required|string|min:1',
            'claimedContentType' => 'nullable|string',
            'fileSizeBytes' => 'nullable|integer|min:1',
        ]);

        try {
            $result = $this->uploadService->createSession($companyId, $request->user()->id, $request->all());

            return response()->json($result, 201);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 500);
        }
    }

    /**
     * POST /api/uploads/{id}/complete
     */
    public function complete(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        try {
            $result = $this->uploadService->completeDirectUpload($companyId, $request->user()->id, $id);

            return response()->json($result, 202);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 500);
        }
    }

    /**
     * DELETE /api/uploads/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        try {
            $this->uploadService->delete($companyId, $request->user()->id, $id);

            return response()->json(['success' => true]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 500);
        }
    }

    /**
     * GET /api/uploads/{id}/preview
     */
    public function preview(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        try {
            $result = $this->uploadService->getPreview($companyId, $id);

            return response()->json($result);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 500);
        }
    }

    /**
     * POST /api/uploads/{id}/mappings
     */
    public function mappings(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        try {
            $result = $this->uploadService->saveMapping($companyId, $request->user()->id, $id, $request->all());

            return response()->json($result);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 500);
        }
    }

    /**
     * POST /api/uploads/{id}/validate
     */
    public function validateUpload(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        try {
            $result = $this->uploadService->queueValidation($companyId, $request->user()->id, $id);

            return response()->json($result, 202);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 500);
        }
    }

    /**
     * GET /api/uploads/{id}/errors
     */
    public function errors(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $upload = Upload::where('id', $id)->where('company_id', $companyId)->first();
        if (! $upload) {
            return response()->json(['error' => 'Upload not found'], 404);
        }

        $errors = UploadRowError::where('upload_id', $id)
            ->where('company_id', $companyId)
            ->orderBy('source_row_number')
            ->get(['id', 'source_sheet_name', 'source_row_number', 'canonical_field_key', 'severity', 'error_code', 'message', 'raw_value', 'created_at']);

        return response()->json(['errors' => $errors]);
    }

    /**
     * POST /api/uploads/{id}/promote
     */
    public function promote(Request $request, string $id): JsonResponse
    {
        $companyId = $request->user()->company_id;
        if (! $companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        try {
            $result = $this->uploadService->queuePromotion($companyId, $request->user()->id, $id);

            return response()->json($result, 202);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 500);
        }
    }
}
