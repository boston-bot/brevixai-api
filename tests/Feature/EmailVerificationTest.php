<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => 'http://frontend.test']);

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

    public function test_signup_sends_verification_email_and_reports_unverified(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/auth/signup', [
            'email' => 'newowner@example.com',
            'password' => 'password123',
            'companyName' => 'Brevix Test Co',
        ])->assertCreated();

        $response->assertJsonPath('user.emailVerified', false);

        $user = User::where('email', 'newowner@example.com')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_unverified_user_is_blocked_from_verified_routes_with_stable_code(): void
    {
        [, $user] = $this->createCompanyUser(verified: false);

        Sanctum::actingAs($user);

        $this->postJson('/api/auth/complete-onboarding')
            ->assertForbidden()
            ->assertJsonPath('code', 'EMAIL_UNVERIFIED');
    }

    public function test_unverified_user_can_still_reach_me_and_resend(): void
    {
        Notification::fake();
        [, $user] = $this->createCompanyUser(verified: false);

        Sanctum::actingAs($user);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('emailVerified', false);

        $this->postJson('/api/auth/resend-verification')
            ->assertOk()
            ->assertJsonPath('message', 'Verification email sent.');

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_signed_verification_link_marks_email_verified_and_redirects(): void
    {
        [, $user] = $this->createCompanyUser(verified: false);

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->get($url)
            ->assertRedirect('http://frontend.test/email-verified?status=success');

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->is_verified);

        // Second click reports already-verified rather than re-verifying.
        $this->get($url)
            ->assertRedirect('http://frontend.test/email-verified?status=already-verified');
    }

    public function test_verification_link_with_wrong_hash_is_rejected(): void
    {
        [, $user] = $this->createCompanyUser(verified: false);

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1('someone-else@example.com'),
        ]);

        $this->get($url)
            ->assertRedirect('http://frontend.test/email-verified?status=invalid');

        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function test_verified_user_passes_verified_routes(): void
    {
        [, $user] = $this->createCompanyUser(verified: true);

        Sanctum::actingAs($user);

        $this->postJson('/api/auth/complete-onboarding')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    /**
     * @return array{0: Company, 1: User}
     */
    private function createCompanyUser(bool $verified): array
    {
        $company = new Company(['name' => 'Brevix Test Co']);
        $company->id = (string) Str::uuid();
        $company->save();

        $user = new User([
            'company_id' => $company->id,
            'email' => Str::uuid().'@example.com',
            'password_hash' => Hash::make('password'),
            'role' => 'owner',
        ]);
        $user->id = (string) Str::uuid();
        if ($verified) {
            $user->email_verified_at = now();
            $user->is_verified = true;
        }
        $user->save();

        return [$company, $user];
    }
}
