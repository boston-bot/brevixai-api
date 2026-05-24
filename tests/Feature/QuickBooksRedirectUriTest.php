<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuickBooksRedirectUriTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.quickbooks.redirect_uri' => 'http://localhost:8081/callback',
            'app.frontend_url' => 'http://localhost:8081',
        ]);

        Schema::dropIfExists('integrations');
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

        Schema::create('integrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('provider', 50);
            $table->string('realm_id', 255)->nullable();
            $table->text('access_token_enc')->nullable();
            $table->text('refresh_token_enc')->nullable();
            $table->timestampTz('token_expires_at')->nullable();
            $table->text('client_id_enc')->nullable();
            $table->text('client_secret_enc')->nullable();
            $table->string('environment', 50)->nullable();
            $table->string('sync_status', 50)->nullable();
            $table->unsignedSmallInteger('sync_progress')->default(0);
            $table->text('sync_error')->nullable();
            $table->timestamps();
        });

        $this->clearGlobalQuickBooksEnv();
    }

    protected function tearDown(): void
    {
        $this->clearGlobalQuickBooksEnv();

        parent::tearDown();
    }

    public function test_qbo_connect_uses_configured_frontend_callback_redirect_uri(): void
    {
        [$company, $user] = $this->createCompanyUser();
        Sanctum::actingAs($user);

        DB::table('integrations')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'provider' => 'quickbooks',
            'realm_id' => null,
            'client_id_enc' => encrypt('client-id'),
            'client_secret_enc' => encrypt('client-secret'),
            'environment' => 'sandbox',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $url = $this->getJson('/api/integrations/qbo/connect')
            ->assertOk()
            ->json('url');

        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('http://localhost:8081/callback', $query['redirect_uri']);
        $this->assertSame('client-id', $query['client_id']);
    }

    public function test_qbo_connect_does_not_fall_back_to_global_credentials(): void
    {
        [, $user] = $this->createCompanyUser();
        Sanctum::actingAs($user);
        $this->setGlobalQuickBooksEnv();

        $this->getJson('/api/integrations/qbo/connect')
            ->assertStatus(400)
            ->assertJson([
                'error' => 'QuickBooks credentials not configured for this company.',
            ]);
    }

    public function test_qbo_connect_rejects_corrupted_company_credentials_without_env_fallback(): void
    {
        [$company, $user] = $this->createCompanyUser();
        Sanctum::actingAs($user);
        $this->setGlobalQuickBooksEnv();

        DB::table('integrations')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'provider' => 'quickbooks',
            'realm_id' => null,
            'client_id_enc' => 'not-valid-encrypted-payload',
            'client_secret_enc' => encrypt('client-secret'),
            'environment' => 'sandbox',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/integrations/qbo/connect')
            ->assertStatus(400)
            ->assertJson([
                'error' => 'QuickBooks credentials are invalid for this company.',
            ]);
    }

    /**
     * @return array{0: Company, 1: User}
     */
    private function createCompanyUser(): array
    {
        Cache::flush();

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

    private function setGlobalQuickBooksEnv(): void
    {
        putenv('QB_CLIENT_ID=global-client-id');
        putenv('QB_CLIENT_SECRET=global-client-secret');
        $_ENV['QB_CLIENT_ID'] = 'global-client-id';
        $_ENV['QB_CLIENT_SECRET'] = 'global-client-secret';
        $_SERVER['QB_CLIENT_ID'] = 'global-client-id';
        $_SERVER['QB_CLIENT_SECRET'] = 'global-client-secret';
    }

    private function clearGlobalQuickBooksEnv(): void
    {
        putenv('QB_CLIENT_ID');
        putenv('QB_CLIENT_SECRET');
        unset(
            $_ENV['QB_CLIENT_ID'],
            $_ENV['QB_CLIENT_SECRET'],
            $_SERVER['QB_CLIENT_ID'],
            $_SERVER['QB_CLIENT_SECRET'],
        );
    }
}
