<?php

namespace App\Models\FraudTesting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FraudScenarioImport extends Model
{
    use HasUuids;

    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';
    public const STATUS_FAILED = 'failed';

    protected $table = 'fraud_scenario_imports';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'original_filename', 'storage_path', 'uploaded_by_id', 'status',
        'total_rows', 'successful_rows', 'failed_rows', 'validation_errors',
        'started_at', 'completed_at',
    ];

    protected $casts = [
        'validation_errors' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function submissions(): HasMany
    {
        return $this->hasMany(FraudScenarioSubmission::class, 'import_id');
    }
}
