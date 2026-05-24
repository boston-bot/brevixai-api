<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionCheckoutTest extends TestCase
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
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->uuid('company_id')->primary();
            $table->string('tier', 50)->default('starter');
            $table->string('status', 50)->default('active');
            $table->string('stripe_customer_id')->nullable();
            $table->string('stripe_subscription_id')->nullable();
            $table->timestampTz('current_period_end')->nullable();
            $table->timestampTz('updated_at')->nullable();
        });
    }

    public function test_subscription_checkout_upgrades_the_company_tier(): void
    {
        [$company, $user] = $this->createCompanyUser();
        Subscription::create([
            'company_id' => $company->id,
            'tier' => 'starter',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/subscriptions')
            ->assertOk()
            ->assertJsonPath('tier', 'starter')
            ->assertJsonPath('status', 'active');

        $this->postJson('/api/subscriptions/checkout', [
            'tier' => 'growth',
            'paymentMethod' => [
                'cardName' => 'Test User',
                'lastFour' => '4242',
                'expiry' => '12/30',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('tier', 'growth');

        $this->assertDatabaseHas('subscriptions', [
            'company_id' => $company->id,
            'tier' => 'growth',
            'status' => 'active',
        ]);
    }

    public function test_subscription_checkout_normalizes_legacy_firm_tier_to_risk_advisory(): void
    {
        [$company, $user] = $this->createCompanyUser();

        Sanctum::actingAs($user);

        $this->postJson('/api/subscriptions/checkout', [
            'tier' => 'accounting-firm',
            'paymentMethod' => [
                'cardName' => 'Test User',
                'lastFour' => '4242',
                'expiry' => '12/30',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('tier', 'risk-advisory');

        $this->assertDatabaseHas('subscriptions', [
            'company_id' => $company->id,
            'tier' => 'risk-advisory',
            'status' => 'active',
        ]);
    }

    public function test_subscription_cancel_marks_subscription_canceled(): void
    {
        [$company, $user] = $this->createCompanyUser();
        Subscription::create([
            'company_id' => $company->id,
            'tier' => 'growth',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/subscriptions/cancel')
            ->assertOk()
            ->assertJsonPath('status', 'canceled')
            ->assertJsonPath('tier', 'starter');

        $this->assertDatabaseHas('subscriptions', [
            'company_id' => $company->id,
            'tier' => 'starter',
            'status' => 'canceled',
        ]);
    }

    /**
     * @return array{0: Company, 1: User}
     */
    private function createCompanyUser(): array
    {
        $company = new Company([
            'name' => 'Brevix Test Co',
        ]);
        $company->id = (string) Str::uuid();
        $company->save();

        $user = new User([
            'company_id' => $company->id,
            'email' => Str::uuid() . '@example.com',
            'password_hash' => Hash::make('password'),
            'first_name' => 'Test',
            'last_name' => 'User',
            'role' => 'owner',
        ]);
        $user->id = (string) Str::uuid();
        $user->save();

        return [$company, $user];
    }
}
