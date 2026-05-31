<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SiteArticle;
use App\Models\SiteContentRevision;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SiteContentApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
    }

    public function test_admin_site_content_api_requires_sanctum_admin(): void
    {
        $this->patchJson('/api/admin/site/settings', $this->settingsPayload())
            ->assertUnauthorized();

        Sanctum::actingAs($this->createUser('owner'));
        $this->patchJson('/api/admin/site/settings', $this->settingsPayload())
            ->assertForbidden();

        Sanctum::actingAs($this->createUser('admin'));
        $this->patchJson('/api/admin/site/settings', $this->settingsPayload('Brevix Admin'))
            ->assertOk()
            ->assertJsonPath('brandName', 'Brevix Admin');
    }

    public function test_pages_and_settings_use_draft_preview_publish_flow_without_public_leakage(): void
    {
        $admin = $this->createUser('admin');
        Sanctum::actingAs($admin);

        $this->patchJson('/api/admin/site/settings', $this->settingsPayload('Draft Brand'))
            ->assertOk()
            ->assertJsonPath('brandName', 'Draft Brand');

        $this->getJson('/api/site/settings')->assertNotFound();

        $this->postJson('/api/admin/site/settings/publish')
            ->assertOk()
            ->assertJsonPath('brandName', 'Draft Brand');

        $this->getJson('/api/site/settings')
            ->assertOk()
            ->assertJsonPath('brandName', 'Draft Brand');

        $this->patchJson('/api/admin/site/pages/home/draft', $this->pagePayload('home', $this->homePayload('Published headline')))
            ->assertOk()
            ->assertJsonPath('payload.hero.headline', 'Published headline');

        $this->getJson('/api/site/pages/home')->assertNotFound();

        $this->postJson('/api/admin/site/pages/home/publish')
            ->assertOk()
            ->assertJsonPath('payload.hero.headline', 'Published headline')
            ->assertJsonPath('status', 'published');

        $this->patchJson('/api/admin/site/pages/home/draft', $this->pagePayload('home', $this->homePayload('Unpublished headline')))
            ->assertOk()
            ->assertJsonPath('payload.hero.headline', 'Unpublished headline')
            ->assertJsonPath('status', 'draft');

        $this->getJson('/api/admin/site/pages/home/preview')
            ->assertOk()
            ->assertJsonPath('payload.hero.headline', 'Unpublished headline');

        $this->getJson('/api/site/pages/home')
            ->assertOk()
            ->assertJsonPath('payload.hero.headline', 'Published headline');

        $this->assertDatabaseHas('site_content_revisions', [
            'content_type' => SiteContentRevision::TYPE_PAGE,
            'event' => SiteContentRevision::EVENT_PUBLISHED,
            'actor_id' => $admin->id,
        ]);
    }

    public function test_articles_publish_from_published_payload_and_remove_idempotently(): void
    {
        $admin = $this->createUser('admin');
        Sanctum::actingAs($admin);

        $articleA = $this->postJson('/api/admin/site/articles', $this->articlePayload([
            'slug' => 'article-a',
            'title' => 'Article A Published',
            'sortOrder' => 1,
        ]))
            ->assertCreated()
            ->assertJsonPath('status', SiteArticle::STATUS_DRAFT)
            ->json();

        $this->getJson('/api/site/articles')
            ->assertOk()
            ->assertJsonCount(0);

        $this->postJson("/api/admin/site/articles/{$articleA['id']}/publish")
            ->assertOk()
            ->assertJsonPath('status', SiteArticle::STATUS_PUBLISHED);

        $articleB = $this->postJson('/api/admin/site/articles', $this->articlePayload([
            'slug' => 'article-b',
            'title' => 'Article B Published',
            'sortOrder' => 2,
        ]))
            ->assertCreated()
            ->json();

        $this->postJson("/api/admin/site/articles/{$articleB['id']}/publish")
            ->assertOk();

        $this->patchJson("/api/admin/site/articles/{$articleA['id']}/draft", $this->articlePayload([
            'slug' => 'article-a',
            'title' => 'Article A Draft',
            'sortOrder' => 3,
        ]))
            ->assertOk()
            ->assertJsonPath('title', 'Article A Draft');

        $publicBeforePublish = $this->getJson('/api/site/articles')
            ->assertOk()
            ->json();

        $this->assertSame(['Article A Published', 'Article B Published'], array_column($publicBeforePublish, 'title'));
        $this->assertSame([1, 2], array_column($publicBeforePublish, 'sortOrder'));

        $this->postJson("/api/admin/site/articles/{$articleA['id']}/publish")
            ->assertOk()
            ->assertJsonPath('title', 'Article A Draft');

        $publicAfterPublish = $this->getJson('/api/site/articles')
            ->assertOk()
            ->json();

        $this->assertSame(['Article B Published', 'Article A Draft'], array_column($publicAfterPublish, 'title'));

        $this->postJson("/api/admin/site/articles/{$articleA['id']}/remove-from-public")
            ->assertOk()
            ->assertJsonPath('status', SiteArticle::STATUS_REMOVED);
        $this->postJson("/api/admin/site/articles/{$articleA['id']}/remove-from-public")
            ->assertOk()
            ->assertJsonPath('status', SiteArticle::STATUS_REMOVED);

        $this->getJson('/api/site/articles')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.slug', 'article-b');

        $this->assertSame(1, SiteContentRevision::where('event', SiteContentRevision::EVENT_REMOVED)->count());
    }

    public function test_validation_and_asset_upload_constraints(): void
    {
        Sanctum::actingAs($this->createUser('admin'));

        $this->patchJson('/api/admin/site/settings', $this->settingsPayload(primaryColor: 'blue'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['primaryColor']);

        $home = $this->homePayload();
        $home['hero']['primaryCtaTarget'] = 'javascript:alert(1)';

        $this->patchJson('/api/admin/site/pages/home/draft', $this->pagePayload('home', $home))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['hero.primaryCtaTarget']);

        $this->postJson('/api/admin/site/articles', $this->articlePayload([
            'slug' => 'duplicate-slug',
            'title' => 'First Article',
        ]))->assertCreated();

        $this->postJson('/api/admin/site/articles', $this->articlePayload([
            'slug' => 'duplicate-slug',
            'title' => 'Second Article',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);

        Storage::fake('public');

        $this->post('/api/admin/site/assets', [
            'asset' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['asset']);

        $this->post('/api/admin/site/assets', [
            'asset' => UploadedFile::fake()->create('logo.png', 4, 'image/png'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonStructure(['id', 'url']);

        $this->assertDatabaseHas('site_assets', [
            'disk' => 'public',
            'mime_type' => 'image/png',
            'original_filename' => 'logo.png',
        ]);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('site_content_revisions');
        Schema::dropIfExists('site_articles');
        Schema::dropIfExists('site_pages');
        Schema::dropIfExists('site_settings');
        Schema::dropIfExists('site_assets');
        Schema::dropIfExists('users');
        Schema::dropIfExists('companies');

        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
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

        Schema::create('site_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('disk');
            $table->string('path');
            $table->string('url')->nullable();
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->string('original_filename');
            $table->uuid('uploaded_by');
            $table->timestamps();
        });

        Schema::create('site_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->json('draft_payload');
            $table->json('published_payload')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->uuid('published_by')->nullable();
            $table->timestamps();
        });

        Schema::create('site_pages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->string('title');
            $table->json('draft_payload');
            $table->json('published_payload')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->uuid('published_by')->nullable();
            $table->timestamps();
        });

        Schema::create('site_articles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->string('status')->default(SiteArticle::STATUS_DRAFT);
            $table->string('title');
            $table->string('category');
            $table->text('description');
            $table->string('badge')->nullable();
            $table->string('read_time')->nullable();
            $table->string('accent_color')->nullable();
            $table->integer('sort_order')->default(0);
            $table->json('draft_payload');
            $table->json('published_payload')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->uuid('published_by')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->uuid('removed_by')->nullable();
            $table->timestamps();
        });

        Schema::create('site_content_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('content_type');
            $table->uuid('content_id');
            $table->string('event');
            $table->json('payload')->nullable();
            $table->uuid('actor_id');
            $table->timestamps();
        });
    }

    private function createUser(string $role): User
    {
        $company = new Company(['name' => 'Brevix Test']);
        $company->id = (string) Str::uuid();
        $company->save();

        $user = new User([
            'company_id' => $company->id,
            'email' => Str::uuid().'@example.com',
            'password_hash' => Hash::make('password'),
            'role' => $role,
            'is_verified' => true,
        ]);
        $user->id = (string) Str::uuid();
        $user->save();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsPayload(string $brandName = 'Brevix AI', string $primaryColor = '#3B82F6'): array
    {
        return [
            'brandName' => $brandName,
            'logoUrl' => null,
            'primaryColor' => $primaryColor,
            'accentColor' => '#06B6D4',
            'themePreset' => 'dark-default',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function pagePayload(string $key, array $payload): array
    {
        return [
            'key' => $key,
            'title' => $key === 'about' ? ($payload['title'] ?? 'About Brevix AI') : 'Home',
            'payload' => $payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function homePayload(string $headline = 'Detect fraud in your books,'): array
    {
        return [
            'hero' => [
                'tagline' => 'Introducing Brevix AI',
                'headline' => $headline,
                'headlineAccent' => 'before it costs you',
                'subheadline' => 'Your AI-powered financial risk detection platform for small business.',
                'primaryCtaLabel' => 'Get Started Free',
                'primaryCtaTarget' => '/(auth)/signup',
                'secondaryCtaLabel' => 'See How It Works',
                'secondaryCtaTarget' => '#features',
                'trustItems' => ['Setup in 5 minutes', 'No credit card required'],
            ],
            'footer' => [
                'brandDescription' => 'AI-powered fraud detection for small businesses.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function articlePayload(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'financial-risk-review-checklist',
            'status' => SiteArticle::STATUS_DRAFT,
            'title' => 'Financial Risk Review Checklist',
            'category' => 'RISK MONITORING',
            'description' => 'A practical review routine for finding duplicate payments before they become operational risk.',
            'body' => 'Full article body.',
            'badge' => 'Guide',
            'readTime' => '8 min read',
            'accentColor' => '#3B82F6',
            'icon' => '01',
            'sortOrder' => 1,
        ], $overrides);
    }
}
