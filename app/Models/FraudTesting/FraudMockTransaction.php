<?php

namespace App\Models\FraudTesting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FraudMockTransaction extends Model
{
    use HasUuids;

    protected $table = 'fraud_mock_transactions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'scenario_submission_id', 'mock_company_id', 'external_transaction_id',
        'transaction_type', 'transaction_date', 'amount', 'party_id',
        'account_category', 'description', 'is_fraudulent', 'fraud_pattern',
        'expected_brevix_signal', 'payload',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'float',
        'is_fraudulent' => 'boolean',
        'payload' => 'array',
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
