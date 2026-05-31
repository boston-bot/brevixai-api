<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteArticle extends Model
{
    use HasUuids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_REMOVED = 'removed';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'slug',
        'status',
        'title',
        'category',
        'description',
        'badge',
        'read_time',
        'accent_color',
        'sort_order',
        'draft_payload',
        'published_payload',
        'published_at',
        'published_by',
        'removed_at',
        'removed_by',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'draft_payload' => 'array',
            'published_payload' => 'array',
            'published_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function scopePublic(Builder $query): void
    {
        $query->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_payload');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function remover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }
}
