<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalFinanceExport extends Model
{
    use HasUuids;

    public const FORMAT_PDF = 'pdf';

    public const FORMAT_DOCX = 'docx';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'company_id',
        'analysis_run_id',
        'generated_by_user_id',
        'format',
        'filename',
        'report_hash',
        'filters',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function analysisRun(): BelongsTo
    {
        return $this->belongsTo(PersonalFinanceAnalysisRun::class, 'analysis_run_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }
}
