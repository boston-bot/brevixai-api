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

class RexPaywallTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('chat_usage_daily');
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
            $table->string('role')->default('owner');
            $table->boolean('is_verified')->default(false);
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

        Schema::create('chat_usage_daily', function (Blueprint $table): void {
            $table->id();
            $table->uuid('company_id');
            $table->date('date');
            $table->integer('message_count')->default(0);
            $table->timestamps();
        });
    }

    public function test_free_tier_cannot_reach_rex_chat(): void
    {
        [, $user] = $this->createCompanyUser(tier: 'free');

        Sanctum::actingAs($user);

        $this->postJson('/api/chat/agent/messages', [
            'company_id' => $user->company_id,
            'message' => 'Review my vendor payments.',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $this->getJson('/api/chat/usage')
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_canceled_pro_subscription_is_blocked(): void
    {
        [, $user] = $this->createCompanyUser(tier: 'pro', status: 'canceled');

        Sanctum::actingAs($user);

        $this->getJson('/api/chat/usage')
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_active_pro_subscription_passes_the_paywall(): void
    {
        [, $user] = $this->createCompanyUser(tier: 'pro');

        Sanctum::actingAs($user);

        $this->getJson('/api/chat/usage')
            ->assertOk()
            ->assertJsonStructure(['used', 'limit', 'remaining']);
    }

    public function test_legacy_paid_tier_rows_still_pass_the_paywall(): void
    {
        [, $user] = $this->createCompanyUser(tier: 'growth');

        Sanctum::actingAs($user);

        $this->getJson('/api/chat/usage')->assertOk();
    }

    /**
     * @return array{0: Company, 1: User}
     */
    private function createCompanyUser(string $tier, string $status = 'active'): array
    {
        $company = new Company(['name' => 'Brevix Test Co']);
        $company->id = (string) Str::uuid();
        $company->save();

        Subscription::create([
            'company_id' => $company->id,
            'tier' => $tier,
            'status' => $status,
        ]);

        $user = new User([
            'company_id' => $company->id,
            'email' => Str::uuid().'@example.com',
            'password_hash' => Hash::make('password'),
            'role' => 'owner',
        ]);
        $user->id = (string) Str::uuid();
        $user->save();

        return [$company, $user];
    }
}
