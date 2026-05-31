<?php

namespace Database\Seeders;

use App\Models\SiteArticle;
use App\Models\SitePage;
use App\Models\SiteSetting;
use App\Services\SiteContentService;
use Illuminate\Database\Seeder;

class SiteContentSeeder extends Seeder
{
    public function run(SiteContentService $siteContent): void
    {
        $now = now();
        $settingsPayload = $siteContent->defaultSettingsPayload();

        $settings = SiteSetting::firstOrNew(['key' => SiteSetting::DEFAULT_KEY]);
        if (! $settings->published_payload) {
            $settings->fill([
                'draft_payload' => $settingsPayload,
                'published_payload' => $settingsPayload,
                'published_at' => $now,
            ])->save();
        }

        foreach ($siteContent->pageKeys() as $key) {
            $default = $siteContent->defaultPage($key);
            $page = SitePage::firstOrNew(['key' => $key]);

            if (! $page->published_payload) {
                $page->fill([
                    'title' => $default['title'],
                    'draft_payload' => $default['payload'],
                    'published_payload' => $default['payload'],
                    'published_at' => $now,
                ])->save();
            }
        }

        foreach ($siteContent->defaultArticles() as $articlePayload) {
            $article = SiteArticle::firstOrNew(['slug' => $articlePayload['slug']]);

            if ($article->published_payload) {
                continue;
            }

            $article->fill([
                'status' => SiteArticle::STATUS_PUBLISHED,
                'title' => $articlePayload['title'],
                'category' => $articlePayload['category'],
                'description' => $articlePayload['description'],
                'badge' => $articlePayload['badge'] ?? null,
                'read_time' => $articlePayload['readTime'] ?? null,
                'accent_color' => $articlePayload['accentColor'] ?? null,
                'sort_order' => $articlePayload['sortOrder'],
                'draft_payload' => $articlePayload,
                'published_payload' => $articlePayload,
                'published_at' => $now,
            ])->save();
        }
    }
}
