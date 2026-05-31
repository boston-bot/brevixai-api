<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteContent\PublicSiteArticleResource;
use App\Http\Resources\SiteContent\PublicSitePageResource;
use App\Http\Resources\SiteContent\PublicSiteSettingsResource;
use App\Services\SiteContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteContentController extends Controller
{
    public function __construct(private readonly SiteContentService $siteContent) {}

    public function settings(Request $request): JsonResponse
    {
        $settings = $this->siteContent->publicSettings();
        abort_unless($settings, 404, 'Published site settings not found');

        return response()->json((new PublicSiteSettingsResource($settings))->resolve($request));
    }

    public function page(Request $request, string $key): JsonResponse
    {
        abort_unless($this->siteContent->isAllowedPageKey($key), 404);

        $page = $this->siteContent->publicPage($key);
        abort_unless($page, 404, 'Published site page not found');

        return response()->json((new PublicSitePageResource($page))->resolve($request));
    }

    public function articles(Request $request): JsonResponse
    {
        return response()->json(PublicSiteArticleResource::collection($this->siteContent->publicArticles())->resolve($request));
    }

    public function article(Request $request, string $slug): JsonResponse
    {
        $article = $this->siteContent->publicArticleBySlug($slug);
        abort_unless($article, 404, 'Published site article not found');

        return response()->json((new PublicSiteArticleResource($article))->resolve($request));
    }
}
