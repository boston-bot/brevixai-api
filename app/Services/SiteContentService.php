<?php

namespace App\Services;

use App\Models\SiteArticle;
use App\Models\SiteAsset;
use App\Models\SiteContentRevision;
use App\Models\SitePage;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SiteContentService
{
    private const HOME_SECTION_CONTROLS = [
        ['id' => 'features', 'label' => 'Features', 'isVisible' => true, 'sortOrder' => 1],
        ['id' => 'how-it-works', 'label' => 'How It Works', 'isVisible' => true, 'sortOrder' => 2],
        ['id' => 'roi-calculator', 'label' => 'ROI Calculator', 'isVisible' => true, 'sortOrder' => 3],
        ['id' => 'capabilities', 'label' => 'Capabilities', 'isVisible' => true, 'sortOrder' => 4],
        ['id' => 'comparison', 'label' => 'Comparison', 'isVisible' => true, 'sortOrder' => 5],
        ['id' => 'compliance', 'label' => 'Compliance', 'isVisible' => true, 'sortOrder' => 6],
    ];

    /** @return list<string> */
    public function pageKeys(): array
    {
        return [SitePage::KEY_HOME, SitePage::KEY_ABOUT];
    }

    public function isAllowedPageKey(string $key): bool
    {
        return in_array($key, $this->pageKeys(), true);
    }

    public function getOrCreateSettings(): SiteSetting
    {
        $settings = SiteSetting::where('key', SiteSetting::DEFAULT_KEY)->first();
        if ($settings) {
            return $settings;
        }

        return SiteSetting::create([
            'key' => SiteSetting::DEFAULT_KEY,
            'draft_payload' => $this->defaultSettingsPayload(),
        ]);
    }

    public function publicSettings(): ?SiteSetting
    {
        return SiteSetting::where('key', SiteSetting::DEFAULT_KEY)
            ->whereNotNull('published_payload')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function saveSettingsDraft(array $input, User $actor): SiteSetting
    {
        $payload = $this->validateSettingsPayload($input);
        $settings = $this->getOrCreateSettings();

        $settings->fill(['draft_payload' => $payload])->save();
        $this->recordRevision(SiteContentRevision::TYPE_SETTINGS, $settings->id, SiteContentRevision::EVENT_DRAFT_SAVED, $payload, $actor);

        return $settings->refresh();
    }

    public function publishSettings(User $actor): SiteSetting
    {
        return DB::transaction(function () use ($actor): SiteSetting {
            $settings = $this->getOrCreateSettings();
            $payload = $this->validateSettingsPayload($settings->draft_payload ?: []);

            $settings->fill([
                'draft_payload' => $payload,
                'published_payload' => $payload,
                'published_at' => now(),
                'published_by' => $actor->id,
            ])->save();

            $this->recordRevision(SiteContentRevision::TYPE_SETTINGS, $settings->id, SiteContentRevision::EVENT_PUBLISHED, $payload, $actor);

            return $settings->refresh();
        });
    }

    public function getOrCreatePage(string $key): SitePage
    {
        $this->assertAllowedPageKey($key);

        $page = SitePage::where('key', $key)->first();
        if ($page) {
            return $page;
        }

        $default = $this->defaultPage($key);

        return SitePage::create([
            'key' => $key,
            'title' => $default['title'],
            'draft_payload' => $default['payload'],
        ]);
    }

    public function publicPage(string $key): ?SitePage
    {
        $this->assertAllowedPageKey($key);

        return SitePage::where('key', $key)
            ->whereNotNull('published_payload')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function savePageDraft(string $key, array $input, User $actor): SitePage
    {
        return DB::transaction(function () use ($key, $input, $actor): SitePage {
            $page = $this->getOrCreatePage($key);
            $payload = $this->validatePagePayload($key, $this->payloadFromPageInput($input));
            $title = $this->pageTitleFromInput($key, $input, $payload);

            $page->fill([
                'title' => $title,
                'draft_payload' => $payload,
            ])->save();

            $this->recordRevision(SiteContentRevision::TYPE_PAGE, $page->id, SiteContentRevision::EVENT_DRAFT_SAVED, $payload, $actor);

            return $page->refresh();
        });
    }

    public function publishPage(string $key, User $actor): SitePage
    {
        return DB::transaction(function () use ($key, $actor): SitePage {
            $page = $this->getOrCreatePage($key);
            $payload = $this->validatePagePayload($key, $page->draft_payload ?: []);

            $page->fill([
                'title' => $this->pageTitleFromInput($key, ['title' => $page->title], $payload),
                'draft_payload' => $payload,
                'published_payload' => $payload,
                'published_at' => now(),
                'published_by' => $actor->id,
            ])->save();

            $this->recordRevision(SiteContentRevision::TYPE_PAGE, $page->id, SiteContentRevision::EVENT_PUBLISHED, $payload, $actor);

            return $page->refresh();
        });
    }

    /** @return Collection<int, SiteArticle> */
    public function adminArticles(): Collection
    {
        return SiteArticle::query()
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();
    }

    /** @return Collection<int, SiteArticle> */
    public function publicArticles(): Collection
    {
        return SiteArticle::query()
            ->public()
            ->orderBy('sort_order')
            ->orderBy('published_at')
            ->get();
    }

    public function publicArticleBySlug(string $slug): ?SiteArticle
    {
        return SiteArticle::query()
            ->public()
            ->where('slug', $slug)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function createArticle(array $input, User $actor): SiteArticle
    {
        return DB::transaction(function () use ($input, $actor): SiteArticle {
            $payload = $this->validateArticlePayload($input);

            $article = SiteArticle::create($this->articleAttributesFromPayload($payload) + [
                'status' => SiteArticle::STATUS_DRAFT,
                'draft_payload' => $payload,
            ]);

            $this->recordRevision(SiteContentRevision::TYPE_ARTICLE, $article->id, SiteContentRevision::EVENT_DRAFT_SAVED, $payload, $actor);

            return $article->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function saveArticleDraft(SiteArticle $article, array $input, User $actor): SiteArticle
    {
        return DB::transaction(function () use ($article, $input, $actor): SiteArticle {
            $payload = $this->validateArticlePayload($input, $article);
            $attributes = ['draft_payload' => $payload];

            if ($article->status !== SiteArticle::STATUS_PUBLISHED) {
                $attributes += $this->articleAttributesFromPayload($payload);
            }

            $article->fill($attributes)->save();
            $this->recordRevision(SiteContentRevision::TYPE_ARTICLE, $article->id, SiteContentRevision::EVENT_DRAFT_SAVED, $payload, $actor);

            return $article->refresh();
        });
    }

    public function publishArticle(SiteArticle $article, User $actor): SiteArticle
    {
        return DB::transaction(function () use ($article, $actor): SiteArticle {
            $payload = $this->validateArticlePayload($article->draft_payload ?: [], $article);

            $article->fill($this->articleAttributesFromPayload($payload) + [
                'status' => SiteArticle::STATUS_PUBLISHED,
                'draft_payload' => $payload,
                'published_payload' => $payload,
                'published_at' => now(),
                'published_by' => $actor->id,
                'removed_at' => null,
                'removed_by' => null,
            ])->save();

            $this->recordRevision(SiteContentRevision::TYPE_ARTICLE, $article->id, SiteContentRevision::EVENT_PUBLISHED, $payload, $actor);

            return $article->refresh();
        });
    }

    public function removeArticleFromPublic(SiteArticle $article, User $actor): SiteArticle
    {
        return DB::transaction(function () use ($article, $actor): SiteArticle {
            if ($article->status !== SiteArticle::STATUS_REMOVED) {
                $article->fill([
                    'status' => SiteArticle::STATUS_REMOVED,
                    'removed_at' => now(),
                    'removed_by' => $actor->id,
                ])->save();

                $this->recordRevision(
                    SiteContentRevision::TYPE_ARTICLE,
                    $article->id,
                    SiteContentRevision::EVENT_REMOVED,
                    $article->published_payload ?: $article->draft_payload,
                    $actor,
                );
            }

            return $article->refresh();
        });
    }

    public function storeAsset(UploadedFile $file, User $actor): SiteAsset
    {
        $disk = 'public';
        $path = $file->store('site-assets', ['disk' => $disk]);

        return SiteAsset::create([
            'disk' => $disk,
            'path' => $path,
            'url' => Storage::disk($disk)->url($path),
            'mime_type' => (string) ($file->getMimeType() ?: $file->getClientMimeType()),
            'size_bytes' => (int) $file->getSize(),
            'original_filename' => (string) $file->getClientOriginalName(),
            'uploaded_by' => $actor->id,
        ]);
    }

    public function pageStatus(SitePage $page): string
    {
        if (! $page->published_payload) {
            return 'draft';
        }

        return $page->draft_payload === $page->published_payload ? 'published' : 'draft';
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultSettingsPayload(): array
    {
        return [
            'brandName' => 'Brevix AI',
            'logoUrl' => null,
            'primaryColor' => '#3B82F6',
            'accentColor' => '#06B6D4',
            'themePreset' => 'dark-default',
        ];
    }

    /**
     * @return array{title: string, payload: array<string, mixed>}
     */
    public function defaultPage(string $key): array
    {
        $this->assertAllowedPageKey($key);

        if ($key === SitePage::KEY_ABOUT) {
            return [
                'title' => 'About Brevix AI',
                'payload' => [
                    'title' => 'About Brevix AI',
                    'sections' => [
                        [
                            'id' => 'mission',
                            'body' => "Brevix AI is on a mission to protect small businesses from financial fraud and payment anomalies that often go undetected until it's too late. Our AI-powered platform brings enterprise-grade financial risk monitoring capabilities to companies of all sizes - without the enterprise price tag.",
                            'isVisible' => true,
                            'sortOrder' => 1,
                        ],
                        [
                            'id' => 'belief',
                            'body' => 'Founded by a team of finance professionals and engineers, we believe every business deserves the tools to safeguard their finances, stay compliant, and grow with confidence.',
                            'isVisible' => true,
                            'sortOrder' => 2,
                        ],
                    ],
                    'values' => ['Transparency', 'Security', 'Accessibility', 'Continuous Improvement'],
                ],
            ];
        }

        return [
            'title' => 'Home',
            'payload' => [
                'hero' => [
                    'tagline' => 'Introducing Brevix AI',
                    'headline' => "Detect fraud in\nyour books,",
                    'headlineAccent' => 'before it costs you',
                    'subheadline' => 'Your AI-powered financial risk detection platform for small business. Scan transactions, spot suspicious patterns, and receive plain-English alerts - without the enterprise price tag.',
                    'primaryCtaLabel' => 'Get Started Free',
                    'primaryCtaTarget' => '/(auth)/signup',
                    'secondaryCtaLabel' => 'See How It Works',
                    'secondaryCtaTarget' => '#features',
                    'trustItems' => ['Setup in 5 minutes', 'No credit card required'],
                ],
                'footer' => [
                    'brandDescription' => 'AI-powered fraud detection for small businesses. Protect your finances without the enterprise price tag.',
                ],
                'sections' => self::HOME_SECTION_CONTROLS,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function defaultArticles(): array
    {
        return [
            [
                'slug' => 'financial-risk-review-checklist',
                'category' => 'RISK MONITORING',
                'title' => 'Financial Risk Review Checklist',
                'description' => 'A practical review routine for finding duplicate payments, unusual timing, vendor concentration, and evidence gaps before they become operational risk.',
                'readTime' => '8 min read',
                'icon' => '01',
                'accentColor' => '#3B82F6',
                'badge' => 'Free PDF',
                'sortOrder' => 1,
            ],
            [
                'slug' => 'month-end-controls-review-for-saas-founders',
                'category' => 'CONTROLS',
                'title' => 'Month-End Controls Review for SaaS Founders',
                'description' => 'A control-monitoring cadence for reconciliations, approvals, vendor changes, and documentation gaps that should be reviewed before close.',
                'readTime' => '12 min read',
                'icon' => '02',
                'accentColor' => '#06B6D4',
                'badge' => 'Guide',
                'sortOrder' => 2,
            ],
            [
                'slug' => 'using-quickbooks-as-a-financial-risk-data-source',
                'category' => 'DATA SOURCES',
                'title' => 'Using QuickBooks as a Financial Risk Data Source',
                'description' => 'How connected ledger data helps Brevix monitor vendor behavior, payment timing, reconciliation drift, and control gaps without replacing your system of record.',
                'readTime' => '6 min read',
                'icon' => '03',
                'accentColor' => '#F59E0B',
                'badge' => '2025 Update',
                'sortOrder' => 3,
            ],
            [
                'slug' => 'seven-invoice-fraud-patterns-that-target-small-businesses',
                'category' => 'FRAUD PATTERNS',
                'title' => 'The 7 Invoice Fraud Patterns That Target Small Businesses',
                'description' => 'Ghost vendors, duplicate invoices, round-dollar anomalies, and same-day payment spikes - this breakdown explains each pattern, why they work, and how Brevix AI detects them automatically.',
                'readTime' => '10 min read',
                'icon' => '04',
                'accentColor' => '#EF4444',
                'badge' => 'Expert Insight',
                'sortOrder' => 4,
            ],
            [
                'slug' => 'investigation-ready-evidence-for-early-stage-saas',
                'category' => 'INVESTIGATION READINESS',
                'title' => 'Investigation-Ready Evidence for Early-Stage SaaS',
                'description' => 'Which alerts, transaction records, review notes, and supporting files help a team move from a risk signal to a structured investigation workflow.',
                'readTime' => '15 min read',
                'icon' => '05',
                'accentColor' => '#6366F1',
                'badge' => 'Deep Dive',
                'sortOrder' => 5,
            ],
            [
                'slug' => 'separating-noise-from-review-worthy-financial-signals',
                'category' => 'EVIDENCE WORKFLOWS',
                'title' => 'Separating Noise from Review-Worthy Financial Signals',
                'description' => 'A playbook for turning flagged transactions into review packets with source fields, reason codes, and clear next steps for human reviewers.',
                'readTime' => '9 min read',
                'icon' => '06',
                'accentColor' => '#10B981',
                'badge' => 'Playbook',
                'sortOrder' => 6,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateSettingsPayload(array $payload): array
    {
        $payload = Arr::only($payload, ['brandName', 'logoUrl', 'logoAssetId', 'primaryColor', 'accentColor', 'themePreset']);
        $payload['brandName'] = trim((string) ($payload['brandName'] ?? ''));
        $payload['logoUrl'] = $this->nullableString($payload['logoUrl'] ?? null);
        $payload['themePreset'] = $this->nullableString($payload['themePreset'] ?? null);

        $validator = Validator::make($payload, [
            'brandName' => ['required', 'string', 'max:80'],
            'logoUrl' => ['nullable', 'string', 'max:2048'],
            'logoAssetId' => ['sometimes', 'nullable', 'uuid', 'exists:site_assets,id'],
            'primaryColor' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accentColor' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'themePreset' => ['nullable', 'string', 'max:64'],
        ]);

        $validator->after(function ($validator) use ($payload): void {
            if (($payload['logoUrl'] ?? null) && ! $this->isSafeUrlOrPath((string) $payload['logoUrl'])) {
                $validator->errors()->add('logoUrl', 'The logo URL must be a safe URL or local public path.');
            }
        });

        return $validator->validate();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validatePagePayload(string $key, array $payload): array
    {
        return $key === SitePage::KEY_HOME
            ? $this->validateHomePayload($payload)
            : $this->validateAboutPayload($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateHomePayload(array $payload): array
    {
        $validator = Validator::make($payload, [
            'hero' => ['required', 'array'],
            'hero.tagline' => ['required', 'string', 'max:80'],
            'hero.headline' => ['required', 'string', 'max:180'],
            'hero.headlineAccent' => ['required', 'string', 'max:120'],
            'hero.subheadline' => ['required', 'string', 'max:600'],
            'hero.primaryCtaLabel' => ['required', 'string', 'max:80'],
            'hero.primaryCtaTarget' => ['required', 'string', 'max:255'],
            'hero.secondaryCtaLabel' => ['required', 'string', 'max:80'],
            'hero.secondaryCtaTarget' => ['required', 'string', 'max:255'],
            'hero.trustItems' => ['required', 'array', 'min:1', 'max:5'],
            'hero.trustItems.*' => ['required', 'string', 'max:80'],
            'footer' => ['required', 'array'],
            'footer.brandDescription' => ['required', 'string', 'max:320'],
            'sections' => ['sometimes', 'array', 'max:12'],
            'sections.*.id' => ['required', 'string', Rule::in(array_column(self::HOME_SECTION_CONTROLS, 'id'))],
            'sections.*.label' => ['sometimes', 'nullable', 'string', 'max:80'],
            'sections.*.isVisible' => ['required', 'boolean'],
            'sections.*.sortOrder' => ['required', 'integer', 'min:0', 'max:10000'],
        ]);

        $validator->after(function ($validator) use ($payload): void {
            foreach (['hero.primaryCtaTarget', 'hero.secondaryCtaTarget'] as $field) {
                $value = Arr::get($payload, $field);
                if (is_string($value) && ! $this->isSafeDestination($value)) {
                    $validator->errors()->add($field, 'CTA targets must be internal paths, page anchors, or HTTPS URLs.');
                }
            }
        });

        $validated = $validator->validate();
        $validated['hero']['trustItems'] = array_values(array_map(
            fn (string $item): string => trim($item),
            $validated['hero']['trustItems'],
        ));
        $validated['sections'] = $this->normalizeHomeSections($validated['sections'] ?? self::HOME_SECTION_CONTROLS);

        return $validated;
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    private function normalizeHomeSections(array $sections): array
    {
        $provided = collect($sections)->keyBy('id');

        return $this->normalizeSortOrder(collect(self::HOME_SECTION_CONTROLS)
            ->map(function (array $default) use ($provided): array {
                $section = $provided->get($default['id'], []);

                return [
                    'id' => $default['id'],
                    'label' => $default['label'],
                    'isVisible' => (bool) ($section['isVisible'] ?? $default['isVisible']),
                    'sortOrder' => (int) ($section['sortOrder'] ?? $default['sortOrder']),
                ];
            })
            ->all());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateAboutPayload(array $payload): array
    {
        $validator = Validator::make($payload, [
            'title' => ['required', 'string', 'max:160'],
            'sections' => ['required', 'array', 'min:1', 'max:12'],
            'sections.*.id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]+$/', 'max:80'],
            'sections.*.title' => ['sometimes', 'nullable', 'string', 'max:140'],
            'sections.*.body' => ['required', 'string', 'max:2500'],
            'sections.*.isVisible' => ['required', 'boolean'],
            'sections.*.sortOrder' => ['required', 'integer', 'min:0', 'max:10000'],
            'values' => ['required', 'array', 'min:1', 'max:12'],
            'values.*' => ['required', 'string', 'max:80'],
        ]);

        $validator->after(function ($validator) use ($payload): void {
            $visible = collect($payload['sections'] ?? [])
                ->contains(fn (mixed $section): bool => (bool) ($section['isVisible'] ?? false) && trim((string) ($section['body'] ?? '')) !== '');

            if (! $visible) {
                $validator->errors()->add('sections', 'At least one visible About section is required.');
            }
        });

        $validated = $validator->validate();
        $validated['sections'] = $this->normalizeSortOrder($validated['sections']);
        $validated['values'] = array_values(array_map(
            fn (string $value): string => trim($value),
            $validated['values'],
        ));

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validateArticlePayload(array $input, ?SiteArticle $article = null): array
    {
        $payload = [
            'slug' => $this->nullableString($input['slug'] ?? null),
            'title' => trim((string) ($input['title'] ?? '')),
            'category' => trim((string) ($input['category'] ?? '')),
            'description' => trim((string) ($input['description'] ?? '')),
            'body' => $this->nullableString($input['body'] ?? null),
            'badge' => $this->nullableString($input['badge'] ?? null) ?: 'Insight',
            'readTime' => $this->nullableString($input['readTime'] ?? $input['read_time'] ?? null),
            'accentColor' => $this->nullableString($input['accentColor'] ?? $input['accent_color'] ?? null),
            'icon' => $this->nullableString($input['icon'] ?? null),
            'sortOrder' => (int) ($input['sortOrder'] ?? $input['sort_order'] ?? 100),
        ];

        if (! $payload['slug']) {
            $payload['slug'] = Str::slug($payload['title']);
        }

        $uniqueSlug = Rule::unique('site_articles', 'slug');
        if ($article) {
            $uniqueSlug->ignore($article->id);
        }

        return Validator::make($payload, [
            'slug' => ['required', 'string', 'max:140', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $uniqueSlug],
            'title' => ['required', 'string', 'max:180'],
            'category' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:600'],
            'body' => ['nullable', 'string', 'max:20000'],
            'badge' => ['required', 'string', 'max:80'],
            'readTime' => ['nullable', 'string', 'max:60'],
            'accentColor' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'icon' => ['nullable', 'string', 'max:20'],
            'sortOrder' => ['required', 'integer', 'min:0', 'max:10000'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function articleAttributesFromPayload(array $payload): array
    {
        return [
            'slug' => $payload['slug'],
            'title' => $payload['title'],
            'category' => $payload['category'],
            'description' => $payload['description'],
            'badge' => $payload['badge'] ?? null,
            'read_time' => $payload['readTime'] ?? null,
            'accent_color' => $payload['accentColor'] ?? null,
            'sort_order' => $payload['sortOrder'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function payloadFromPageInput(array $input): array
    {
        $payload = $input['payload'] ?? $input;

        if (! is_array($payload)) {
            throw ValidationException::withMessages(['payload' => 'The page payload must be an object.']);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $payload
     */
    private function pageTitleFromInput(string $key, array $input, array $payload): string
    {
        $title = $input['title'] ?? null;

        if (! is_string($title) || trim($title) === '') {
            $title = $key === SitePage::KEY_ABOUT ? (string) ($payload['title'] ?? 'About Brevix AI') : 'Home';
        }

        return trim(substr($title, 0, 160));
    }

    private function assertAllowedPageKey(string $key): void
    {
        if (! $this->isAllowedPageKey($key)) {
            throw ValidationException::withMessages(['key' => 'Unsupported site page key.']);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function normalizeSortOrder(array $items): array
    {
        return collect($items)
            ->sortBy([
                ['sortOrder', 'asc'],
                ['id', 'asc'],
            ])
            ->values()
            ->map(function (array $item, int $index): array {
                $item['sortOrder'] = $index + 1;

                return $item;
            })
            ->all();
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function isSafeDestination(string $destination): bool
    {
        $destination = trim($destination);

        if (str_starts_with($destination, '#')) {
            return preg_match('/^#[A-Za-z0-9_-]+$/', $destination) === 1;
        }

        if (str_starts_with($destination, '/')) {
            return ! str_starts_with($destination, '//') && ! str_contains($destination, '\\');
        }

        return str_starts_with($destination, 'https://') && filter_var($destination, FILTER_VALIDATE_URL) !== false;
    }

    private function isSafeUrlOrPath(string $url): bool
    {
        $url = trim($url);

        if (str_starts_with($url, '/')) {
            return ! str_starts_with($url, '//') && ! str_contains($url, '\\');
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        return str_starts_with($url, 'https://')
            || in_array($host, array_filter(['localhost', '127.0.0.1', $appHost]), true)
            || (app()->environment(['local', 'testing']) && is_string($host) && str_ends_with($host, '.test'));
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function recordRevision(string $type, string $contentId, string $event, ?array $payload, User $actor): void
    {
        SiteContentRevision::create([
            'content_type' => $type,
            'content_id' => $contentId,
            'event' => $event,
            'payload' => $payload,
            'actor_id' => $actor->id,
        ]);
    }
}
