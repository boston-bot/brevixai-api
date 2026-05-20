<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalFinanceBudgetProfile extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'company_id',
        'name',
        'person_a_label',
        'person_b_label',
        'person_a_monthly_allowance',
        'person_b_monthly_allowance',
        'shared_monthly_cap',
        'opaque_card_payment_cap',
        'catch_up_target_amount',
        'category_caps',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'person_a_monthly_allowance' => 'decimal:2',
            'person_b_monthly_allowance' => 'decimal:2',
            'shared_monthly_cap' => 'decimal:2',
            'opaque_card_payment_cap' => 'decimal:2',
            'catch_up_target_amount' => 'decimal:2',
            'category_caps' => 'array',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
