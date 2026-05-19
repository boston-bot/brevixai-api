<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AlertRecommendation extends Model
{
    use HasUuids;

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_EXPIRED = 'expired';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'company_id',
        'source_risk_domain',
        'alert_type',
        'severity',
        'title',
        'summary',
        'evidence',
        'source_rule_ids',
        'confidence_score',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_note',
    ];

    protected $casts = [
        'evidence' => 'array',
        'source_rule_ids' => 'array',
        'confidence_score' => 'float',
        'reviewed_at' => 'datetime',
    ];

    public function scopePendingReview(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING_REVIEW);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function alert(): HasOne
    {
        return $this->hasOne(Alert::class);
    }

    public function reviewEvents(): HasMany
    {
        return $this->hasMany(RecommendationReviewEvent::class, 'recommendation_id')
            ->where('recommendation_type', RecommendationReviewEvent::TYPE_ALERT);
    }
}
