<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewEvent extends Model
{
    use HasUuids;

    public const ACTOR_USER = 'user';
    public const ACTOR_SYSTEM = 'system';
    public const ACTOR_AGENT = 'agent';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'business_profile_id',
        'investigation_id',
        'finding_id',
        'event_type',
        'actor_type',
        'actor_id',
        'previous_status',
        'next_status',
        'note',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class);
    }
}
