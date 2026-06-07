<?php

namespace App\Models\FraudTesting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FraudInvestigationQuestion extends Model
{
    use HasUuids;

    protected $table = 'fraud_investigation_questions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'scenario_submission_id', 'question', 'asked_to', 'why_question_matters', 'priority',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FraudScenarioSubmission::class, 'scenario_submission_id');
    }
}
