<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReconciliationResult extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'company_id', 'period_start', 'period_end',
        'total_mismatches', 'total_impact', 'status', 'results',
    ];

    protected $casts = [
        'run_at' => 'datetime',
        'period_start' => 'date',
        'period_end' => 'date',
        'total_impact' => 'decimal:2',
        'results' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function discrepancies(): HasMany
    {
        return $this->hasMany(ReconciliationDiscrepancy::class, 'run_id');
    }

    public function mismatches(): HasMany
    {
        return $this->hasMany(ReconciliationMismatch::class, 'run_id');
    }
}
