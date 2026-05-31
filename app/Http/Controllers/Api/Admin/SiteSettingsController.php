<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteContent\AdminSiteSettingsResource;
use App\Http\Resources\SiteContent\SiteAssetResource;
use App\Services\SiteContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteSettingsController extends Controller
{
    public function __construct(private readonly SiteContentService $siteContent) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json((new AdminSiteSettingsResource($this->siteContent->getOrCreateSettings()))->resolve($request));
    }

    public function update(Request $request): JsonResponse
    {
        $settings = $this->siteContent->saveSettingsDraft($request->all(), $request->user());

        return response()->json((new AdminSiteSettingsResource($settings))->resolve($request));
    }

    public function preview(Request $request): JsonResponse
    {
        return $this->show($request);
    }

    public function publish(Request $request): JsonResponse
    {
        $settings = $this->siteContent->publishSettings($request->user());

        return response()->json((new AdminSiteSettingsResource($settings))->resolve($request));
    }

    public function storeAsset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'asset' => ['required', 'file', 'mimetypes:image/png,image/jpeg,image/webp', 'max:5120'],
        ]);

        $asset = $this->siteContent->storeAsset($validated['asset'], $request->user());

        return response()->json((new SiteAssetResource($asset))->resolve($request), 201);
    }
}
