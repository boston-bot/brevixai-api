<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestigationActivityEvent extends Model
{
    use HasUuids;

    public const ACTOR_USER = 'user';

    public const ACTOR_SYSTEM = 'system';

    public const ACTOR_AGENT = 'agent';

    public const EVENT_CASE_CREATED = 'case_created';

    public const EVENT_ASSIGNED = 'assigned';

    public const EVENT_STATUS_CHANGED = 'status_changed';

    public const EVENT_NOTES_ADDED = 'notes_added';

    public const EVENT_EVIDENCE_LINKED = 'evidence_linked';

    public const EVENT_EVIDENCE_REMOVED = 'evidence_removed';

    public const EVENT_RECOMMENDATION_APPROVED = 'recommendation_approved';

    public const EVENT_REPORT_GENERATED = 'report_generated';

    public const EVENT_PACKAGE_MANIFEST_GENERATED = 'package_manifest_generated';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'audit_case_id',
        'company_id',
        'event_type',
        'actor_type',
        'actor_id',
        'event_summary',
        'event_metadata',
    ];

    protected $casts = [
        'event_metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function auditCase(): BelongsTo
    {
        return $this->belongsTo(AuditCase::class, 'audit_case_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
