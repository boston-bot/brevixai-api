<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EntityIdentity extends Model
{
    protected $fillable = [
        'id',
        'company_id',
        'business_profile_id',
        'scope_key',
        'entity_type',
        'canonical_key',
        'display_name',
        'legacy_identity_hash',
        'metadata',
        'first_seen_at',
        'last_seen_at',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = [
        'metadata' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function aliases(): HasMany
    {
        return $this->hasMany(EntityIdentityAlias::class);
    }
}
