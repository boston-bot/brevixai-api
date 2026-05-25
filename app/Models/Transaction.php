<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'upload_id', 'company_id', 'business_profile_id', 'txn_id', 'date', 'department',
        'vendor_customer', 'type', 'category', 'payment_method', 'amount',
        'invoice_ref', 'memo', 'anomaly_flag', 'anomaly_reason', 'raw_row',
        'import_batch_id', 'source_sheet_name', 'source_row_number',
        'validation_status', 'parse_warnings', 'row_content_hash',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
        'anomaly_flag' => 'boolean',
        'raw_row' => 'array',
        'parse_warnings' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }
}
