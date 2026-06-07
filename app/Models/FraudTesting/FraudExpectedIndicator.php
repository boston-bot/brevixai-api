<?php

namespace App\Models\FraudTesting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FraudExpectedIndicator extends Model
{
    use HasUuids;

    protected $table = 'fraud_expected_indicators';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'scenario_submission_id', 'indicator_key', 'indicator_name',
        'indicator_category', 'description', 'severity', 'data_needed', 'should_detect',
    ];

    protected $casts = [
        'data_needed' => 'array',
        'should_detect' => 'boolean',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FraudScenarioSubmission::class, 'scenario_submission_id');
    }
}
