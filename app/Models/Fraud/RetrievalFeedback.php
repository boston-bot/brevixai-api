<?php

namespace App\Models\Fraud;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

class RetrievalFeedback extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'relevance_score' => 'integer',
    ];

    public function playbook(): BelongsTo
    {
        return $this->belongsTo(InvestigationPlaybook::class, 'playbook_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
