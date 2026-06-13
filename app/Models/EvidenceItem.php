<?php

namespace App\Models;

use App\Models\Concerns\ScopesBusinessProfile;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class EvidenceItem extends Model
{
    use HasUuids, ScopesBusinessProfile;

    public const ACTOR_USER = 'user';
    public const ACTOR_SYSTEM = 'system';
    public const ACTOR_AGENT = 'agent';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'company_id',
        'business_profile_id',
        'investigation_id',
        'finding_id',
        'legacy_evidence_item_id',
        'evidence_type',
        'source_type',
        'source_id',
        'source_record_id',
        'title',
        'summary',
        'citation_label',
        'source_row_range',
        'file_name',
        'storage_key',
        'hash',
        'added_by_actor_type',
        'added_by_actor_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class);
    }

    public function findings(): BelongsToMany
    {
        return $this->belongsToMany(Finding::class, 'evidence_item_finding');
    }
}
