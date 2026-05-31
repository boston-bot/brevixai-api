<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteContent\AdminSitePageResource;
use App\Services\SiteContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SitePageController extends Controller
{
    public function __construct(private readonly SiteContentService $siteContent) {}

    public function show(Request $request, string $key): JsonResponse
    {
        abort_unless($this->siteContent->isAllowedPageKey($key), 404);

        return response()->json((new AdminSitePageResource($this->siteContent->getOrCreatePage($key)))->resolve($request));
    }

    public function saveDraft(Request $request, string $key): JsonResponse
    {
        abort_unless($this->siteContent->isAllowedPageKey($key), 404);

        $page = $this->siteContent->savePageDraft($key, $request->all(), $request->user());

        return response()->json((new AdminSitePageResource($page))->resolve($request));
    }

    public function preview(Request $request, string $key): JsonResponse
    {
        return $this->show($request, $key);
    }

    public function publish(Request $request, string $key): JsonResponse
    {
        abort_unless($this->siteContent->isAllowedPageKey($key), 404);

        $page = $this->siteContent->publishPage($key, $request->user());

        return response()->json((new AdminSitePageResource($page))->resolve($request));
    }
}
