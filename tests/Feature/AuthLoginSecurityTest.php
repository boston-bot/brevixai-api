<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthLoginSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('auth:'.hash('sha256', 'security@example.com').'|ip:127.0.0.1');
        RateLimiter::clear('ip:127.0.0.1');

        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('business_profile_memberships');
        Schema::dropIfExists('workspace_memberships');
        Schema::dropIfExists('business_profiles');
        Schema::dropIfExists('users');
        Schema::dropIfExists('companies');

        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->boolean('has_completed_onboarding')->default(false);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('role')->default('owner');
            $table->boolean('is_verified')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('tokenable_type');
            $table->string('tokenable_id');
            $table->index(['tokenable_type', 'tokenable_id']);
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_login_verifies_against_hashed_password_and_returns_token(): void
    {
        $user = $this->createUser('correct-password-123');

        $this->assertNotSame('correct-password-123', $user->password_hash);
        $this->assertTrue(Hash::check('correct-password-123', $user->password_hash));

        $this->postJson('/api/auth/login', [
            'email' => 'security@example.com',
            'password' => 'correct-password-123',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user'])
            ->assertJsonPath('user.email', 'security@example.com');
    }

    public function test_login_is_rate_limited_after_repeated_failures(): void
    {
        $this->createUser('correct-password-123');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/login', [
                'email' => 'security@example.com',
                'password' => 'wrong-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', [
            'email' => 'security@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    private function createUser(string $password): User
    {
        $company = new Company(['name' => 'Brevix Test Co']);
        $company->id = (string) Str::uuid();
        $company->save();

        $user = new User([
            'company_id' => $company->id,
            'email' => 'security@example.com',
            'password_hash' => Hash::make($password),
            'first_name' => 'Security',
            'last_name' => 'Tester',
            'role' => 'owner',
        ]);
        $user->id = (string) Str::uuid();
        $user->save();

        return $user;
    }
}
