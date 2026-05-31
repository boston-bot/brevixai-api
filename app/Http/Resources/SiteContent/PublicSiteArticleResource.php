<?php

namespace App\Http\Resources\SiteContent;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicSiteArticleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = $this->published_payload ?: [];

        return [
            'id' => (string) $this->id,
            'slug' => (string) ($payload['slug'] ?? $this->slug),
            'status' => 'published',
            'title' => (string) ($payload['title'] ?? $this->title),
            'category' => (string) ($payload['category'] ?? $this->category),
            'description' => (string) ($payload['description'] ?? $this->description),
            'body' => $payload['body'] ?? null,
            'badge' => $payload['badge'] ?? $this->badge,
            'readTime' => $payload['readTime'] ?? $this->read_time,
            'accentColor' => $payload['accentColor'] ?? $this->accent_color,
            'icon' => $payload['icon'] ?? null,
            'sortOrder' => (int) ($payload['sortOrder'] ?? $this->sort_order),
            'publishedAt' => $this->published_at?->toISOString(),
        ];
    }
}
