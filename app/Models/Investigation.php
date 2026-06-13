<?php

namespace App\Models;

use App\Models\Concerns\ScopesBusinessProfile;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Investigation extends Model
{
    use HasUuids, ScopesBusinessProfile;

    public const CATEGORY_REVENUE = 'revenue';
    public const CATEGORY_EXPENSE = 'expense';
    public const CATEGORY_PAYROLL = 'payroll';
    public const CATEGORY_TAX = 'tax';
    public const CATEGORY_FRAUD = 'fraud';
    public const CATEGORY_RECONCILIATION = 'reconciliation';
    public const CATEGORY_CONTROLS = 'controls';
    public const CATEGORY_VENDOR_PAYMENTS = 'vendor_payments';
    public const CATEGORY_CASH_FLOW = 'cash_flow';
    public const CATEGORY_UNSURE = 'unsure';

    public const STATUS_OPEN = 'open';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_WAITING_ON_RECORDS = 'waiting_on_records';
    public const STATUS_PENDING_REVIEWER_APPROVAL = 'pending_reviewer_approval';
    public const STATUS_READY_FOR_PACKAGE = 'ready_for_package';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_ARCHIVED = 'archived';

    public const PRIORITY_CRITICAL = 'critical';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_LOW = 'low';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'company_id',
        'business_profile_id',
        'legacy_audit_case_id',
        'title',
        'category',
        'subcategory',
        'status',
        'priority',
        'review_period_start',
        'review_period_end',
        'scope_statement',
        'scope_limitations',
        'assigned_to',
        'created_by',
        'opened_at',
        'closed_at',
        'last_activity_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'review_period_start' => 'date',
            'review_period_end' => 'date',
            'scope_limitations' => 'array',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function businessProfile(): BelongsTo
    {
        return $this->belongsTo(BusinessProfile::class);
    }

    public function legacyAuditCase(): BelongsTo
    {
        return $this->belongsTo(AuditCase::class, 'legacy_audit_case_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    public function evidenceItems(): HasMany
    {
        return $this->hasMany(EvidenceItem::class);
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

    public function casePackages(): HasMany
    {
        return $this->hasMany(CasePackage::class)->orderByDesc('generated_at');
    }
}
