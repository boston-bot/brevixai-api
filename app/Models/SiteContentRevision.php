<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteContentRevision extends Model
{
    use HasUuids;

    public const TYPE_SETTINGS = 'settings';

    public const TYPE_PAGE = 'page';

    public const TYPE_ARTICLE = 'article';

    public const EVENT_DRAFT_SAVED = 'draft_saved';

    public const EVENT_PUBLISHED = 'published';

    public const EVENT_REMOVED = 'removed';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'content_type',
        'content_id',
        'event',
        'payload',
        'actor_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
