<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UploadRowError extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    const UPDATED_AT = null;

    protected $fillable = [
        'upload_id', 'company_id', 'business_profile_id', 'validation_run_id',
        'source_sheet_name', 'source_row_number',
        'canonical_field_key', 'severity', 'error_code', 'message', 'raw_value',
    ];

    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }
}
