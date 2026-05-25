<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RexPendingAction extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'session_id', 'company_id', 'business_profile_id', 'action_type', 'preview', 'status', 'confirmed_by', 'confirmed_at',
    ];

    protected $casts = [
        'preview' => 'array',
        'confirmed_at' => 'datetime',
    ];
}
