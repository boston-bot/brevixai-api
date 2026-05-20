<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalFinanceRule extends Model
{
    use HasUuids;

    public const TYPE_CATEGORY = 'category';

    public const TYPE_INCOME_SOURCE = 'income_source';

    public const TYPE_MERCHANT = 'merchant';

    public const TYPE_PERSON = 'person';

    public const TYPE_EXCLUSION = 'exclusion';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'company_id',
        'rule_type',
        'name',
        'match_field',
        'pattern',
        'target_value',
        'priority',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
