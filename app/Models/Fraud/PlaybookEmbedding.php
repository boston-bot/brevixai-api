<?php

namespace App\Models\Fraud;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaybookEmbedding extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'embedding' => 'array',
    ];

    public function playbook(): BelongsTo
    {
        return $this->belongsTo(InvestigationPlaybook::class, 'playbook_id');
    }
}
