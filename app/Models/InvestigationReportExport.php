<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestigationReportExport extends Model
{
    use HasUuids;

    public const FORMAT_JSON = 'json';

    public const FORMAT_PDF = 'pdf';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'audit_case_id',
        'company_id',
        'generated_by_user_id',
        'format',
        'filename',
        'report_hash',
        'generated_at',
        'metadata',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function auditCase(): BelongsTo
    {
        return $this->belongsTo(AuditCase::class, 'audit_case_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function generatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }
}
