<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuleDefinition extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'company_id', 'rule_key', 'display_name', 'description',
        'severity', 'enabled', 'config',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'config' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
