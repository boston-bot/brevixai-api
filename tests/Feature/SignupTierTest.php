<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SignupTierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('subscriptions');
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
            $table->foreignUuid('company_id')->nullable();
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

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->uuid('company_id')->primary();
            $table->string('tier', 50)->default('free');
            $table->string('status', 50)->default('active');
            $table->string('stripe_customer_id')->nullable();
            $table->string('stripe_subscription_id')->nullable();
            $table->timestampTz('current_period_end')->nullable();
            $table->timestampTz('updated_at')->nullable();
        });

        if (! Schema::hasTable('personal_access_tokens')) {
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
    }

    public function test_signup_ignores_submitted_pro_tier_and_creates_free_subscription(): void
    {
        $this->assertSignupCreatesFreeSubscription('pro', 'pro@example.com');
    }

    public function test_signup_ignores_submitted_legacy_tier_and_creates_free_subscription(): void
    {
        $this->assertSignupCreatesFreeSubscription('growth', 'growth@example.com');
    }

    public function test_signup_without_tier_creates_free_subscription(): void
    {
        $this->assertSignupCreatesFreeSubscription(null, 'notier@example.com');
    }

    private function assertSignupCreatesFreeSubscription(?string $tier, string $email): void
    {
        Notification::fake();

        $payload = [
            'email' => $email,
            'password' => 'password123',
            'companyName' => 'Brevix Test Co',
        ];

        if ($tier !== null) {
            $payload['tier'] = $tier;
        }

        $this->postJson('/api/auth/signup', $payload)->assertCreated();

        $user = User::where('email', $email)->firstOrFail();
        $subscription = Subscription::where('company_id', $user->company_id)->firstOrFail();

        $this->assertSame('free', $subscription->tier);
        $this->assertSame('active', $subscription->status);
    }
}
