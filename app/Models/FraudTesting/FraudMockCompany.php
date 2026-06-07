<?php

namespace App\Models\FraudTesting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FraudMockCompany extends Model
{
    use HasUuids;

    protected $table = 'fraud_mock_companies';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'scenario_submission_id', 'company_name', 'industry', 'entity_type',
        'annual_revenue', 'employee_count', 'vendor_count', 'customer_count',
        'months_of_activity', 'profile_payload',
    ];

    protected $casts = [
        'profile_payload' => 'array',
        'annual_revenue' => 'float',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FraudScenarioSubmission::class, 'scenario_submission_id');
    }

    public function parties(): HasMany
    {
        return $this->hasMany(FraudMockParty::class, 'mock_company_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FraudMockTransaction::class, 'mock_company_id');
    }
}
