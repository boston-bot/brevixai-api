<?php

namespace App\Http\Resources\SiteContent;

use App\Services\SiteContentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminSitePageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $service = app(SiteContentService::class);

        return [
            'key' => $this->key,
            'title' => $this->title,
            'payload' => $this->draft_payload ?: [],
            'publishedAt' => $this->published_at?->toISOString(),
            'status' => $service->pageStatus($this->resource),
        ];
    }
}
