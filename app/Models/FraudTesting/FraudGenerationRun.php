<?php

namespace App\Models\FraudTesting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FraudGenerationRun extends Model
{
    use HasUuids;

    public const RUN_TYPE_EXTRACTION = 'extraction';
    public const RUN_TYPE_MOCK_DATA_GENERATION = 'mock_data_generation';
    public const RUN_TYPE_QUICKBOOKS_PAYLOAD = 'quickbooks_payload_generation';
    public const RUN_TYPE_REGRESSION_TEST = 'regression_test';
    public const RUN_TYPE_MANUAL_IMPORT = 'manual_import';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_NEEDS_REVIEW = 'needs_review';

    protected $table = 'fraud_generation_runs';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'scenario_submission_id', 'run_type', 'status',
        'started_at', 'completed_at', 'input_payload', 'output_payload', 'errors',
    ];

    protected $casts = [
        'input_payload' => 'array',
        'output_payload' => 'array',
        'errors' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FraudScenarioSubmission::class, 'scenario_submission_id');
    }
}
