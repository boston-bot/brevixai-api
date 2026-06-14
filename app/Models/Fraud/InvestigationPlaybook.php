<?php

namespace App\Models\Fraud;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvestigationPlaybook extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'symptoms' => 'array',
        'red_flags' => 'array',
        'tests' => 'array',
        'document_requests' => 'array',
        'is_active' => 'boolean',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(PlaybookSource::class, 'source_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PlaybookVersion::class, 'playbook_id');
    }

    public function embeddings(): HasMany
    {
        return $this->hasMany(PlaybookEmbedding::class, 'playbook_id');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(RetrievalFeedback::class, 'playbook_id');
    }

    public function relatedPlaybooksAsSource(): HasMany
    {
        return $this->hasMany(PlaybookRelationship::class, 'source_playbook_id');
    }

    public function relatedPlaybooksAsTarget(): HasMany
    {
        return $this->hasMany(PlaybookRelationship::class, 'target_playbook_id');
    }
}
