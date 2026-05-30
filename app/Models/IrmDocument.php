<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IrmDocument extends Model
{
    protected $fillable = [
        'irm_reference',
        'part_number',
        'chapter_number',
        'section_number',
        'title',
        'catalog_number',
        'effective_date',
        'audience',
        's3_key',
        'file_hash',
        'last_synced_at',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'last_synced_at' => 'datetime',
        'part_number' => 'integer',
        'chapter_number' => 'integer',
        'section_number' => 'integer',
    ];

    public function sections(): HasMany
    {
        return $this->hasMany(IrmSection::class);
    }
}
