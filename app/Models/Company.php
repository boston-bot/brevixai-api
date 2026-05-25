<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Company extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name', 'industry', 'size', 'website', 'entity_type', 'has_completed_onboarding',
    ];

    protected $casts = [
        'has_completed_onboarding' => 'boolean',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function businessProfiles(): HasMany
    {
        return $this->hasMany(BusinessProfile::class);
    }

    public function workspaceMemberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function uploads(): HasMany
    {
        return $this->hasMany(Upload::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function reconciliationResults(): HasMany
    {
        return $this->hasMany(ReconciliationResult::class);
    }
}
