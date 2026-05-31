<?php

namespace App\Http\Resources\SiteContent;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteAssetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'url' => $this->url,
        ];
    }
}
