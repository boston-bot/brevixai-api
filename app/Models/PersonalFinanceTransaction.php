<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalFinanceTransaction extends Model
{
    use HasUuids;

    public const DIRECTION_INFLOW = 'inflow';

    public const DIRECTION_OUTFLOW = 'outflow';

    public const PERSON_A = 'person_a';

    public const PERSON_B = 'person_b';

    public const PERSON_SHARED = 'shared';

    public const PERSON_EXCLUDED = 'excluded';

    public const PERSON_UNKNOWN = 'unknown';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'company_id',
        'statement_import_id',
        'posted_date',
        'description',
        'normalized_merchant',
        'amount',
        'direction',
        'category',
        'person_scope',
        'recurring_key',
        'source_section',
        'confidence',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'posted_date' => 'date',
            'amount' => 'decimal:2',
            'confidence' => 'integer',
            'raw_payload' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function statementImport(): BelongsTo
    {
        return $this->belongsTo(PersonalFinanceStatementImport::class, 'statement_import_id');
    }
}
