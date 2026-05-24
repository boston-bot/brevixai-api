<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PasswordReset;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('password_resets');
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
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('password_resets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('token', 255)->unique();
            $table->timestamp('expires_at');
            $table->boolean('used')->default(false);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_forgot_password_returns_generic_success_for_unknown_email(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJsonPath('message', 'If an account with that email exists, a password reset link has been sent.');

        $this->assertSame(0, PasswordReset::count());
    }

    public function test_forgot_password_creates_token_for_known_email(): void
    {
        $user = $this->createUser();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('message', 'If an account with that email exists, a password reset link has been sent.');

        $this->assertSame(1, PasswordReset::where('user_id', $user->id)->count());
        $record = PasswordReset::where('user_id', $user->id)->first();
        $this->assertFalse((bool) $record->used);
        $this->assertTrue($record->expires_at->isFuture());
    }

    public function test_forgot_password_replaces_existing_token(): void
    {
        $user = $this->createUser();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();
        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

        $this->assertSame(1, PasswordReset::where('user_id', $user->id)->count());
    }

    public function test_forgot_password_requires_valid_email(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_reset_password_succeeds_with_valid_token(): void
    {
        $user = $this->createUser('old-password-123');
        $rawToken = $this->createResetToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $rawToken,
            'password' => 'new-password-456',
        ])->assertOk()->assertJsonPath('message', 'Password has been reset successfully.');

        $this->assertTrue(Hash::check('new-password-456', $user->refresh()->password_hash));
        $this->assertTrue((bool) PasswordReset::where('user_id', $user->id)->first()->used);
    }

    public function test_reset_password_rejects_invalid_token(): void
    {
        $this->postJson('/api/auth/reset-password', [
            'token' => 'not-a-real-token',
            'password' => 'new-password-456',
        ])->assertStatus(422)->assertJsonPath('error', 'Invalid or expired password reset token.');
    }

    public function test_reset_password_rejects_used_token(): void
    {
        $user = $this->createUser();
        $rawToken = $this->createResetToken($user, used: true);

        $this->postJson('/api/auth/reset-password', [
            'token' => $rawToken,
            'password' => 'new-password-456',
        ])->assertStatus(422);
    }

    public function test_reset_password_rejects_expired_token(): void
    {
        $user = $this->createUser();
        $rawToken = $this->createResetToken($user, expiresAt: now()->subHour());

        $this->postJson('/api/auth/reset-password', [
            'token' => $rawToken,
            'password' => 'new-password-456',
        ])->assertStatus(422);
    }

    public function test_reset_password_requires_minimum_length(): void
    {
        $user = $this->createUser();
        $rawToken = $this->createResetToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $rawToken,
            'password' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
    }

    private function createUser(string $password = 'password123'): User
    {
        $company = new Company(['name' => 'Test Co', 'has_completed_onboarding' => false]);
        $company->id = (string) Str::uuid();
        $company->save();

        $user = new User([
            'company_id' => $company->id,
            'email' => Str::uuid() . '@example.com',
            'password_hash' => Hash::make($password),
            'role' => 'owner',
        ]);
        $user->id = (string) Str::uuid();
        $user->save();

        return $user;
    }

    private function createResetToken(User $user, bool $used = false, mixed $expiresAt = null): string
    {
        $rawToken = Str::random(64);
        PasswordReset::create([
            'user_id' => $user->id,
            'token' => hash('sha256', $rawToken),
            'expires_at' => $expiresAt ?? now()->addHour(),
            'used' => $used,
        ]);
        return $rawToken;
    }
}
