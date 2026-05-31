<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IrmSection extends Model
{
    protected $fillable = [
        'irm_document_id',
        'xml_id',
        'irm_reference',
        'depth',
        'title',
        'effective_date',
        'body_text',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'depth' => 'integer',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(IrmDocument::class, 'irm_document_id');
    }
}
