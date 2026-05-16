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
        'company_id', 'uploaded_by', 'filename', 'file_size', 'status',
        'sheets_parsed', 'row_count', 'import_type', 'original_filename',
        'storage_filename', 'claimed_content_type', 'file_extension',
        'file_size_bytes', 'sha256',
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
