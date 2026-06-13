<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait ScopesBusinessProfile
{
    /** @param Builder<static> $query */
    public function scopeWhereProfile(Builder $query, ?string $businessProfileId): Builder
    {
        return $businessProfileId ? $query->where('business_profile_id', $businessProfileId) : $query;
    }
}
