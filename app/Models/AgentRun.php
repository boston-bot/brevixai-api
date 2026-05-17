<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentRun extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'company_id',
        'user_id',
        'conversation_id',
        'status',
        'intent',
        'input_message',
        'final_response',
        'model_provider',
        'model_name',
        'tokens_input',
        'tokens_output',
        'cost_estimate',
        'started_at',
        'completed_at',
        'failed_at',
        'error_message',
    ];

    protected $casts = [
        'tokens_input' => 'integer',
        'tokens_output' => 'integer',
        'cost_estimate' => 'decimal:6',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AgentStep::class);
    }

    public function actionApprovals(): HasMany
    {
        return $this->hasMany(AgentActionApproval::class);
    }
}
