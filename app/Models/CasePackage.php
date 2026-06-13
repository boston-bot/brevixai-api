<?php

namespace App\Models;

use App\Models\Concerns\ScopesBusinessProfile;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CasePackage extends Model
{
    use HasUuids, ScopesBusinessProfile;

    public const FORMAT_JSON = 'json';
    public const FORMAT_PDF = 'pdf';
    public const FORMAT_ZIP = 'zip';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'company_id',
        'business_profile_id',
        'investigation_id',
        'format',
        'status',
        'title',
        'generated_at',
        'generated_by',
        'included_sections',
        'included_counts',
        'package_hash',
        'filename',
        'storage_key',
        'manifest',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'included_sections' => 'array',
            'included_counts' => 'array',
            'manifest' => 'array',
        ];
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
