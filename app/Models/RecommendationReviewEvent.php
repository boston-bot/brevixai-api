<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecommendationReviewEvent extends Model
{
    use HasUuids;

    public const TYPE_ALERT = 'alert';

    public const TYPE_CASE = 'case';

    public const EVENT_CREATED = 'created';

    public const EVENT_VIEWED = 'viewed';

    public const EVENT_APPROVED = 'approved';

    public const EVENT_DISMISSED = 'dismissed';

    public const EVENT_EXPIRED = 'expired';

    public const ACTOR_USER = 'user';

    public const ACTOR_SYSTEM = 'system';

    public const ACTOR_AGENT = 'agent';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'business_profile_id',
        'recommendation_type',
        'recommendation_id',
        'event_type',
        'actor_type',
        'actor_id',
        'event_metadata',
        'created_at',
    ];

    protected $casts = [
        'event_metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
