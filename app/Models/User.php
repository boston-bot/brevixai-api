<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'company_id', 'email', 'password_hash',
        'first_name', 'last_name', 'role',
        'is_verified', 'last_login_at',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Map Laravel's expected 'password' auth field to our 'password_hash' column.
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function workspaceMemberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }

    public function businessProfileMemberships(): HasMany
    {
        return $this->hasMany(BusinessProfileMembership::class);
    }
}
