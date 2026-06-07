<?php

namespace App\Models\FraudTesting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FraudMockParty extends Model
{
    use HasUuids;

    protected $table = 'fraud_mock_parties';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'scenario_submission_id', 'mock_company_id', 'external_party_id',
        'party_type', 'party_name', 'role', 'is_fraud_actor', 'is_related_party', 'attributes',
    ];

    protected $casts = [
        'attributes' => 'array',
        'is_fraud_actor' => 'boolean',
        'is_related_party' => 'boolean',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FraudScenarioSubmission::class, 'scenario_submission_id');
    }

    public function mockCompany(): BelongsTo
    {
        return $this->belongsTo(FraudMockCompany::class, 'mock_company_id');
    }
}
