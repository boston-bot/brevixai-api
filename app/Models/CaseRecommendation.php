<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CaseRecommendation extends Model
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
        'case_type',
        'severity',
        'title',
        'summary',
        'source_risk_domains',
        'related_alert_recommendation_ids',
        'evidence',
        'confidence_score',
        'requires_human_review',
        'can_auto_create',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_note',
    ];

    protected $attributes = [
        'requires_human_review' => true,
        'can_auto_create' => false,
        'status' => self::STATUS_PENDING_REVIEW,
    ];

    protected $casts = [
        'source_risk_domains' => 'array',
        'related_alert_recommendation_ids' => 'array',
        'evidence' => 'array',
        'confidence_score' => 'float',
        'requires_human_review' => 'boolean',
        'can_auto_create' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (CaseRecommendation $recommendation): void {
            $recommendation->requires_human_review = true;
            $recommendation->can_auto_create = false;
        });
    }

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

    public function auditCase(): HasOne
    {
        return $this->hasOne(AuditCase::class, 'case_recommendation_id');
    }

    public function reviewEvents(): HasMany
    {
        return $this->hasMany(RecommendationReviewEvent::class, 'recommendation_id')
            ->where('recommendation_type', RecommendationReviewEvent::TYPE_CASE);
    }
}
