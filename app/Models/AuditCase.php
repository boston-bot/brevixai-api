<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuditCase extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'company_id', 'case_recommendation_id', 'title', 'description', 'status', 'severity',
        'assigned_to', 'created_by', 'resolution_notes', 'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function caseRecommendation(): BelongsTo
    {
        return $this->belongsTo(CaseRecommendation::class, 'case_recommendation_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AuditCaseEvent::class, 'case_id');
    }

    // Since alert_ids and transaction_ids are native PG arrays, we won't add them
    // to fillable or default JSON casts to avoid mapping issues during standard updates.
    // They should be updated via raw SQL statements if modified after creation.
}
