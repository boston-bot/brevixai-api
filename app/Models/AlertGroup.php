<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlertGroup extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'business_profile_id', 'title', 'alert_count', 'max_severity', 'total_impact',
    ];

    protected $casts = [
        'total_impact' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class, 'group_id');
    }
}
