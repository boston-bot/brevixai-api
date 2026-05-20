<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PersonalFinanceStatementImport extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'company_id',
        'imported_by_user_id',
        'source_filename',
        'source_path',
        'sha256',
        'statement_date',
        'period_start',
        'period_end',
        'account_last4',
        'status',
        'transaction_count',
        'warnings',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'period_start' => 'date',
            'period_end' => 'date',
            'transaction_count' => 'integer',
            'warnings' => 'array',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by_user_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PersonalFinanceTransaction::class, 'statement_import_id');
    }
}
