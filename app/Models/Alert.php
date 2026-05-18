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
        'company_id', 'group_id', 'alert_recommendation_id', 'rule_key', 'severity', 'title',
        'detail', 'evidence', 'status', 'priority_score',
        'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'evidence' => 'array',
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
}
