<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuditCase extends Model
{
    use HasUuids;

    public const INVESTIGATION_STATUS_OPEN = 'open';

    public const INVESTIGATION_STATUS_IN_REVIEW = 'in_review';

    public const INVESTIGATION_STATUS_ESCALATED = 'escalated';

    public const INVESTIGATION_STATUS_RESOLVED = 'resolved';

    public const INVESTIGATION_STATUS_ARCHIVED = 'archived';

    public const INVESTIGATION_PRIORITY_CRITICAL = 'critical';

    public const INVESTIGATION_PRIORITY_HIGH = 'high';

    public const INVESTIGATION_PRIORITY_MEDIUM = 'medium';

    public const INVESTIGATION_PRIORITY_LOW = 'low';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'company_id', 'business_profile_id', 'case_recommendation_id', 'title', 'description', 'status', 'severity',
        'assigned_to', 'created_by', 'resolution_notes', 'resolved_at',
        'investigation_status', 'investigation_assigned_user_id', 'investigation_priority',
        'investigation_summary', 'investigation_notes', 'last_activity_at', 'investigation_metadata',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'investigation_metadata' => 'array',
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

    public function investigationActivityEvents(): HasMany
    {
        return $this->hasMany(InvestigationActivityEvent::class, 'audit_case_id')->orderBy('created_at');
    }

    public function investigationReportExports(): HasMany
    {
        return $this->hasMany(InvestigationReportExport::class, 'audit_case_id')->orderByDesc('generated_at');
    }

    public function investigationAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'investigation_assigned_user_id');
    }

    // Since alert_ids and transaction_ids are native PG arrays, we won't add them
    // to fillable or default JSON casts to avoid mapping issues during standard updates.
    // They should be updated via raw SQL statements if modified after creation.
}
