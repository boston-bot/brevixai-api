<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatUsageDaily extends Model
{
    protected $table = 'chat_usage_daily';

    protected $fillable = [
        'company_id', 'business_profile_id', 'date', 'message_count',
    ];

    protected $casts = [
        'date' => 'date',
    ];
}
