<?php

namespace App\Models\Fraud;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaybookRelationship extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function sourcePlaybook(): BelongsTo
    {
        return $this->belongsTo(InvestigationPlaybook::class, 'source_playbook_id');
    }

    public function targetPlaybook(): BelongsTo
    {
        return $this->belongsTo(InvestigationPlaybook::class, 'target_playbook_id');
    }
}
