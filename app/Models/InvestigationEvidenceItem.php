<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestigationEvidenceItem extends Model
{
    use HasUuids;

    public const TYPE_TRANSACTION = 'transaction';

    public const TYPE_VENDOR = 'vendor';

    public const TYPE_ALERT = 'alert';

    public const TYPE_RECOMMENDATION = 'recommendation';

    public const TYPE_NOTE = 'note';

    public const TYPE_DOCUMENT = 'document';

    public const TYPE_SYSTEM_FINDING = 'system_finding';

    public const ACTOR_USER = 'user';

    public const ACTOR_SYSTEM = 'system';

    public const ACTOR_AGENT = 'agent';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'audit_case_id',
        'company_id',
        'evidence_type',
        'evidence_reference_id',
        'title',
        'summary',
        'source',
        'added_by_actor_type',
        'added_by_actor_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
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
