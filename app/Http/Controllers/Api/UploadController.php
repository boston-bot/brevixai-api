<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessProfileAccessException;
use App\Http\Controllers\Controller;
use App\Models\Upload;
use App\Models\UploadRowError;
use App\Services\BusinessProfileContextService;
use App\Services\UploadService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class UploadController extends Controller
{
    protected UploadService $uploadService;

    public function __construct(UploadService $uploadService, private readonly BusinessProfileContextService $businessProfileContext)
    {
        $this->uploadService = $uploadService;
    }

    /**
     * GET /api/uploads
     */
    public function index(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $data = $this->uploadService->list($context->companyId, $context->businessProfileId);

        return response()->json($data);
    }

    /**
     * GET /api/uploads/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $detail = $this->uploadService->getDetail($context->companyId, $id, $context->businessProfileId);
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
        $context = $this->resolveContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $request->validate([
            'importType' => ['required', 'string', Rule::in(UploadService::SUPPORTED_IMPORT_TYPES)],
            'originalFilename' => 'required|string|min:1',
            'claimedContentType' => 'nullable|string',
            'fileSizeBytes' => 'nullable|integer|min:1',
        ]);

        try {
            $result = $this->uploadService->createSession($context->companyId, $request->user()->id, $request->all(), $context->businessProfileId);

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
        $context = $this->resolveContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        try {
            $result = $this->uploadService->completeDirectUpload($context->companyId, $request->user()->id, $id, $context->businessProfileId);

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
        $context = $this->resolveContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        try {
            $this->uploadService->delete($context->companyId, $request->user()->id, $id, $context->businessProfileId);

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
        $context = $this->resolveContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        try {
            $result = $this->uploadService->getPreview($context->companyId, $id, $context->businessProfileId);

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
        $context = $this->resolveContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        try {
            $result = $this->uploadService->saveMapping($context->companyId, $request->user()->id, $id, $request->all(), $context->businessProfileId);

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
        $context = $this->resolveContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        try {
            $result = $this->uploadService->queueValidation($context->companyId, $request->user()->id, $id, $context->businessProfileId);

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
        $context = $this->resolveContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $uploadQuery = Upload::where('id', $id)->where('company_id', $context->companyId);
        if ($context->businessProfileId && Schema::hasColumn('uploads', 'business_profile_id')) {
            $uploadQuery->where('business_profile_id', $context->businessProfileId);
        }
        $upload = $uploadQuery->first();
        if (! $upload) {
            return response()->json(['error' => 'Upload not found'], 404);
        }

        $errors = UploadRowError::where('upload_id', $id)
            ->where('company_id', $context->companyId);
        if ($context->businessProfileId && Schema::hasColumn('upload_row_errors', 'business_profile_id')) {
            $errors->where('business_profile_id', $context->businessProfileId);
        }
        $errors = $errors->orderBy('source_row_number')
            ->get(['id', 'source_sheet_name', 'source_row_number', 'canonical_field_key', 'severity', 'error_code', 'message', 'raw_value', 'created_at']);

        return response()->json(['errors' => $errors]);
    }

    /**
     * POST /api/uploads/{id}/promote
     */
    public function promote(Request $request, string $id): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        try {
            $result = $this->uploadService->queuePromotion($context->companyId, $request->user()->id, $id, $context->businessProfileId);

            return response()->json($result, 202);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 500);
        }
    }

    private function resolveContext(Request $request): \App\Services\BusinessProfileContext|JsonResponse
    {
        try {
            return $this->businessProfileContext->resolveForRequest($request);
        } catch (BusinessProfileAccessException $e) {
            return response()->json(['error' => $e->getMessage()], $e->statusCode());
        }
    }
}
