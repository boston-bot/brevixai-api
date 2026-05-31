<?php

namespace App\Http\Resources\SiteContent;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicSitePageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'payload' => $this->published_payload ?: [],
            'publishedAt' => $this->published_at?->toISOString(),
            'status' => 'published',
        ];
    }
}
