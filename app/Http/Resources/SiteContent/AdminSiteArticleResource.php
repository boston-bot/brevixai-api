<?php

namespace App\Http\Resources\SiteContent;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminSiteArticleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->articlePayload($this->draft_payload ?: $this->published_payload ?: []);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function articlePayload(array $payload): array
    {
        return [
            'id' => (string) $this->id,
            'slug' => (string) ($payload['slug'] ?? $this->slug),
            'status' => $this->status,
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
