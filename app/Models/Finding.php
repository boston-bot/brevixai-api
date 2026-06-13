<?php

namespace App\Models;

use App\Models\Concerns\ScopesBusinessProfile;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Finding extends Model
{
    use HasUuids, ScopesBusinessProfile;

    public const STATUS_NEW = 'new';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_NEEDS_MORE_EVIDENCE = 'needs_more_evidence';
    public const STATUS_REVIEWED = 'reviewed';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_ESCALATED = 'escalated';
    public const STATUS_INCLUDED_IN_PACKAGE = 'included_in_package';

    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'company_id',
        'business_profile_id',
        'investigation_id',
        'category',
        'source_module',
        'source_record_type',
        'source_record_id',
        'title',
        'summary',
        'detail',
        'severity',
        'confidence',
        'confidence_score',
        'reason_code',
        'status',
        'evidence_refs',
        'recommended_action',
        'reviewer_status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'confidence_score' => 'float',
            'evidence_refs' => 'array',
            'recommended_action' => 'array',
            'metadata' => 'array',
        ];
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function evidenceItems(): HasMany
    {
        return $this->hasMany(EvidenceItem::class);
    }

    public function linkedEvidenceItems(): BelongsToMany
    {
        return $this->belongsToMany(EvidenceItem::class, 'evidence_item_finding');
    }

    public function suggestedRecords(): HasMany
    {
        return $this->hasMany(SuggestedRecord::class);
    }

    public function reviewerNotes(): HasMany
    {
        return $this->hasMany(ReviewerNote::class)->orderBy('created_at');
    }

    public function reviewEvents(): HasMany
    {
        return $this->hasMany(ReviewEvent::class)->orderBy('created_at');
    }
}
