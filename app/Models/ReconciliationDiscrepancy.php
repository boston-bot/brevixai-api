<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReconciliationDiscrepancy extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'company_id', 'run_id', 'bank_txn_id', 'ledger_txn_id',
        'amount', 'category', 'reason_code', 'confidence_score',
        'risk_level', 'recommended_action', 'recommendation_explanation',
        'status', 'resolution_notes', 'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'confidence_score' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ReconciliationResult::class, 'run_id');
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'bank_txn_id');
    }

    public function ledgerTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'ledger_txn_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ReconciliationDiscrepancyEvent::class, 'discrepancy_id')
                    ->orderBy('created_at', 'desc');
    }
}
