<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationDiscrepancyEvent extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'company_id', 'discrepancy_id', 'user_id', 'event_type',
        'previous_status', 'next_status', 'selected_action', 'note', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function discrepancy(): BelongsTo
    {
        return $this->belongsTo(ReconciliationDiscrepancy::class, 'discrepancy_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
