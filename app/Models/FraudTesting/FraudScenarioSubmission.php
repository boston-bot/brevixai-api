<?php

namespace App\Models\FraudTesting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FraudScenarioSubmission extends Model
{
    use HasUuids;

    public const STATUS_IMPORTED = 'imported';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ARCHIVED = 'archived';

    public const EXTRACTION_STATUS_PENDING = 'pending';
    public const EXTRACTION_STATUS_PROCESSING = 'processing';
    public const EXTRACTION_STATUS_COMPLETED = 'completed';
    public const EXTRACTION_STATUS_FAILED = 'failed';
    public const EXTRACTION_STATUS_NEEDS_REVIEW = 'needs_review';

    public const MOCK_DATA_STATUS_PENDING = 'pending';
    public const MOCK_DATA_STATUS_PROCESSING = 'processing';
    public const MOCK_DATA_STATUS_COMPLETED = 'completed';
    public const MOCK_DATA_STATUS_FAILED = 'failed';
    public const MOCK_DATA_STATUS_NEEDS_REVIEW = 'needs_review';

    public const REVIEW_STATUS_UNREVIEWED = 'unreviewed';
    public const REVIEW_STATUS_APPROVED = 'approved';
    public const REVIEW_STATUS_REJECTED = 'rejected';
    public const REVIEW_STATUS_NEEDS_REVISION = 'needs_revision';

    protected $table = 'fraud_scenario_submissions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'import_id', 'external_scenario_id', 'title', 'narrative', 'source', 'severity',
        'status', 'extraction_status', 'mock_data_status', 'review_status',
        'row_number', 'raw_row',
    ];

    protected $casts = [
        'raw_row' => 'array',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(FraudScenarioImport::class, 'import_id');
    }

    public function extraction(): HasOne
    {
        return $this->hasOne(FraudScenarioExtraction::class, 'scenario_submission_id');
    }

    public function expectedIndicators(): HasMany
    {
        return $this->hasMany(FraudExpectedIndicator::class, 'scenario_submission_id');
    }

    public function expectedFindings(): HasMany
    {
        return $this->hasMany(FraudExpectedFinding::class, 'scenario_submission_id');
    }

    public function mockCompany(): HasOne
    {
        return $this->hasOne(FraudMockCompany::class, 'scenario_submission_id');
    }

    public function mockParties(): HasMany
    {
        return $this->hasMany(FraudMockParty::class, 'scenario_submission_id');
    }

    public function mockTransactions(): HasMany
    {
        return $this->hasMany(FraudMockTransaction::class, 'scenario_submission_id');
    }

    public function documentRequests(): HasMany
    {
        return $this->hasMany(FraudDocumentRequest::class, 'scenario_submission_id');
    }

    public function investigationQuestions(): HasMany
    {
        return $this->hasMany(FraudInvestigationQuestion::class, 'scenario_submission_id');
    }

    public function generationRuns(): HasMany
    {
        return $this->hasMany(FraudGenerationRun::class, 'scenario_submission_id');
    }
}
