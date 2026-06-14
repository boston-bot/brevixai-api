<?php

namespace App\Models\Fraud;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlaybookSource extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function playbooks(): HasMany
    {
        return $this->hasMany(InvestigationPlaybook::class, 'source_id');
    }
}
