<?php

namespace App\Models\FraudTesting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FraudScenarioExtraction extends Model
{
    use HasUuids;

    protected $table = 'fraud_scenario_extractions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'scenario_submission_id', 'fraud_category', 'industry', 'actor_type',
        'concealment_method', 'summary', 'structured_payload', 'confidence_score',
        'model_name', 'prompt_version', 'extraction_errors',
    ];

    protected $casts = [
        'structured_payload' => 'array',
        'extraction_errors' => 'array',
        'confidence_score' => 'float',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FraudScenarioSubmission::class, 'scenario_submission_id');
    }
}
