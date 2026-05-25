<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingAnswer extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'onboarding_session_id',
        'company_id',
        'business_profile_id',
        'answered_by',
        'answer_key',
        'answer_value',
    ];

    protected $casts = [
        'answer_value' => 'array',
    ];

    public function onboardingSession(): BelongsTo
    {
        return $this->belongsTo(OnboardingSession::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function businessProfile(): BelongsTo
    {
        return $this->belongsTo(BusinessProfile::class);
    }

    public function answeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }
}
