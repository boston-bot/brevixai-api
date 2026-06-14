<?php

namespace App\Models;

use App\Models\Concerns\ScopesBusinessProfile;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxpayerTransparencyItem extends Model
{
    use HasUuids, ScopesBusinessProfile;

    public const CATEGORY_VERIFIED_FACT = 'verified_fact';

    public const CATEGORY_UNVERIFIED_CLAIM = 'unverified_claim';

    public const CATEGORY_ASSUMPTION = 'assumption';

    public const CATEGORY_UNKNOWN = 'unknown';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'company_id',
        'business_profile_id',
        'audit_case_id',
        'created_by',
        'category',
        'status_key',
        'tax_period',
        'label',
        'detail',
        'source_type',
        'source_label',
        'source_reference',
        'source_date',
        'captured_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'source_date' => 'date',
            'captured_at' => 'datetime',
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

    public function auditCase(): BelongsTo
    {
        return $this->belongsTo(AuditCase::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
