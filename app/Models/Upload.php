<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Upload extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id', 'company_id', 'business_profile_id', 'uploaded_by', 'filename', 'file_size', 'status',
        'sheets_parsed', 'row_count', 'import_type', 'original_filename',
        'storage_filename', 'claimed_content_type', 'file_extension',
        'file_size_bytes', 'sha256', 'quarantine_bucket', 'quarantine_key',
        'detected_content_type', 'status_detail', 'failure_code', 'scan_status',
        'scan_result', 'inspection_summary', 'latest_mapping_version_id',
        'latest_validation_run_id', 'uploaded_at', 'scanned_at', 'inspected_at',
        'validated_at', 'promoted_at',
    ];

    protected $casts = [
        'sheets_parsed' => 'array',
        'scan_result' => 'array',
        'inspection_summary' => 'array',
        'uploaded_at' => 'datetime',
        'promoted_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
