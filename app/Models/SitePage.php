<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SitePage extends Model
{
    use HasUuids;

    public const KEY_HOME = 'home';

    public const KEY_ABOUT = 'about';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'key',
        'title',
        'draft_payload',
        'published_payload',
        'published_at',
        'published_by',
    ];

    protected function casts(): array
    {
        return [
            'draft_payload' => 'array',
            'published_payload' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
