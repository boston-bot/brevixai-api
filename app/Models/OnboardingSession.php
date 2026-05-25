<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OnboardingSession extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'company_id',
        'business_profile_id',
        'created_by',
        'status',
        'primary_intent',
        'current_step',
        'review_period_start',
        'review_period_end',
        'scope_mode',
        'business_context',
        'metadata',
        'scope_acknowledged_at',
        'completed_at',
    ];

    protected $casts = [
        'business_context' => 'array',
        'metadata' => 'array',
        'review_period_start' => 'date:Y-m-d',
        'review_period_end' => 'date:Y-m-d',
        'scope_acknowledged_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function businessProfile(): BelongsTo
    {
        return $this->belongsTo(BusinessProfile::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(OnboardingAnswer::class);
    }
}
