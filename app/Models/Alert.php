<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'company_id', 'business_profile_id', 'group_id', 'alert_recommendation_id', 'rule_key', 'severity', 'title',
        'detail', 'evidence', 'reason_codes', 'source_system', 'source_recommendation_id',
        'confidence_score', 'evidence_refs', 'comparison_window', 'status', 'priority_score',
        'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'evidence' => 'array',
        'reason_codes' => 'array',
        'confidence_score' => 'float',
        'evidence_refs' => 'array',
        'comparison_window' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(AlertGroup::class, 'group_id');
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(AlertRecommendation::class, 'alert_recommendation_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function toArray(): array
    {
        $array = parent::toArray();
        
        $array['reasonCodes'] = $this->reason_codes;
        $array['sourceSystem'] = $this->source_system;
        $array['evidenceRefs'] = $this->evidence_refs;
        $array['confidenceScore'] = $this->confidence_score;
        $array['deterministicCheckName'] = $this->rule_key;
        $array['comparisonWindow'] = $this->comparison_window;
        $array['sourceFreshness'] = $this->created_at?->diffForHumans();
        $array['humanReviewStatus'] = $this->status === 'reviewed' ? 'reviewed' : 'pending';

        return $array;
    }
}
