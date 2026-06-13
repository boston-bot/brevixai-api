<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxNoticeInterpretation extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'company_id',
        'business_profile_id',
        'investigation_id',
        'finding_id',
        'source_upload_id',
        'created_by',
        'notice_text_hash',
        'notice_text_encrypted',
        'notice_type',
        'deadline_days',
        'deadline_description',
        'required_action',
        'risk_level',
        'key_amount',
        'summary',
        'extraction',
    ];

    protected function casts(): array
    {
        return [
            'notice_text_encrypted' => 'encrypted',
            'key_amount' => 'float',
            'extraction' => 'array',
        ];
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class);
    }
}
