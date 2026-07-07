<?php

namespace Database\Seeders;

use App\Models\Fraud\InvestigationPlaybook;
use App\Models\Fraud\PlaybookSource;
use App\Models\Fraud\PlaybookVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Loads the curated fraud playbook corpus from database/data/fraud_playbooks.json.
 * Idempotent: upserts by title so re-running refreshes content without duplicates.
 */
class InvestigationPlaybookSeeder extends Seeder
{
    public const CORPUS_PATH = 'database/data/fraud_playbooks.json';

    public function run(): void
    {
        $corpus = json_decode(File::get(base_path(self::CORPUS_PATH)), true);

        $source = PlaybookSource::firstOrCreate(
            ['name' => $corpus['source']['name']],
            ['description' => $corpus['source']['description'] ?? null]
        );

        foreach ($corpus['playbooks'] as $entry) {
            $playbook = InvestigationPlaybook::updateOrCreate(
                ['title' => $entry['title']],
                [
                    'source_id' => $source->id,
                    'category' => $entry['category'],
                    'description' => $entry['description'] ?? null,
                    'symptoms' => $entry['symptoms'] ?? [],
                    'red_flags' => $entry['red_flags'] ?? [],
                    'tests' => $entry['tests'] ?? [],
                    'document_requests' => $entry['document_requests'] ?? [],
                    'intent_key' => $entry['intent_key'] ?? null,
                    'is_active' => true,
                ]
            );

            PlaybookVersion::firstOrCreate(
                [
                    'playbook_id' => $playbook->id,
                    'version_number' => $corpus['version'],
                ],
                ['content_snapshot' => $entry]
            );
        }
    }
}
