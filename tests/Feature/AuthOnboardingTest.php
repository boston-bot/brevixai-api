<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthOnboardingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_me_returns_company_onboarding_status(): void
    {
        [$company, $user] = $this->createCompanyUser(hasCompletedOnboarding: true);

        Sanctum::actingAs($user);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('companyId', $company->id)
            ->assertJsonPath('hasCompletedOnboarding', true);
    }

    public function test_complete_onboarding_updates_company(): void
    {
        [$company, $user] = $this->createCompanyUser(hasCompletedOnboarding: false);

        Sanctum::actingAs($user);

        $this->postJson('/api/auth/complete-onboarding')
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertTrue($company->refresh()->has_completed_onboarding);
        $this->assertFalse(Schema::hasColumn('users', 'has_completed_onboarding'));
    }

    /**
     * @return array{0: Company, 1: User}
     */
    private function createCompanyUser(bool $hasCompletedOnboarding): array
    {
        $company = new Company([
            'name' => 'Brevix Test Co',
            'has_completed_onboarding' => $hasCompletedOnboarding,
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
