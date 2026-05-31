<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteContent\AdminSiteArticleResource;
use App\Models\SiteArticle;
use App\Services\SiteContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteArticleController extends Controller
{
    public function __construct(private readonly SiteContentService $siteContent) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(AdminSiteArticleResource::collection($this->siteContent->adminArticles())->resolve($request));
    }

    public function store(Request $request): JsonResponse
    {
        $article = $this->siteContent->createArticle($request->all(), $request->user());

        return response()->json((new AdminSiteArticleResource($article))->resolve($request), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $article = SiteArticle::findOrFail($id);

        return response()->json((new AdminSiteArticleResource($article))->resolve($request));
    }

    public function saveDraft(Request $request, string $id): JsonResponse
    {
        $article = SiteArticle::findOrFail($id);
        $article = $this->siteContent->saveArticleDraft($article, $request->all(), $request->user());

        return response()->json((new AdminSiteArticleResource($article))->resolve($request));
    }

    public function preview(Request $request, string $id): JsonResponse
    {
        return $this->show($request, $id);
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        $article = SiteArticle::findOrFail($id);
        $article = $this->siteContent->publishArticle($article, $request->user());

        return response()->json((new AdminSiteArticleResource($article))->resolve($request));
    }

    public function removeFromPublic(Request $request, string $id): JsonResponse
    {
        $article = SiteArticle::findOrFail($id);
        $article = $this->siteContent->removeArticleFromPublic($article, $request->user());

        return response()->json((new AdminSiteArticleResource($article))->resolve($request));
    }
}
