<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityIdentityAlias extends Model
{
    protected $fillable = [
        'id',
        'entity_identity_id',
        'company_id',
        'business_profile_id',
        'scope_key',
        'entity_type',
        'alias_type',
        'alias_value',
        'normalized_alias',
        'first_seen_at',
        'last_seen_at',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function entityIdentity(): BelongsTo
    {
        return $this->belongsTo(EntityIdentity::class);
    }
}
