<?php

namespace App\Models\FraudTesting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FraudExpectedFinding extends Model
{
    use HasUuids;

    protected $table = 'fraud_expected_findings';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'scenario_submission_id', 'finding_key', 'finding_title', 'finding_description',
        'expected_risk_score', 'expected_confidence', 'recommended_action', 'expected_user_message',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FraudScenarioSubmission::class, 'scenario_submission_id');
    }
}
