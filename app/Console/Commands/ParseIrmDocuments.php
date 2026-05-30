<?php

namespace App\Console\Commands;

use App\Models\IrmDocument;
use App\Models\IrmSection;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use SimpleXMLElement;

class ParseIrmDocuments extends Command
{
    protected $signature = 'irm:parse
                            {--limit= : Maximum number of XML files to process}
                            {--prefix=irm : S3 key prefix to scan for XML files}
                            {--force : Re-parse files that have already been imported}';

    protected $description = 'Parse IRM XML files from S3 and store structured records in the database';

    public function handle(): int
    {
        $limit  = $this->option('limit') ? (int) $this->option('limit') : null;
        $prefix = rtrim($this->option('prefix'), '/');
        $force  = $this->option('force');

        $this->info("Listing XML files under s3://{$prefix} ...");
        $files = Storage::disk('s3')->allFiles($prefix);
        $files = array_values(array_filter($files, fn ($f) => str_ends_with(strtolower($f), '.xml')));

        if (empty($files)) {
            $this->warn('No XML files found. Run irm:fetch first.');
            return self::SUCCESS;
        }

        if ($limit !== null) {
            $files = array_slice($files, 0, $limit);
        }

        $this->info(sprintf('Processing %d XML file(s)...', count($files)));

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $failed  = 0;

        $this->withProgressBar($files, function (string $s3Key) use ($force, &$created, &$updated, &$skipped, &$failed) {
            try {
                $result = $this->parseFile($s3Key, $force);
                match ($result) {
                    'created' => $created++,
                    'updated' => $updated++,
                    'skipped' => $skipped++,
                };
            } catch (\Throwable $e) {
                Log::error('irm:parse failed', ['key' => $s3Key, 'error' => $e->getMessage()]);
                $failed++;
            }
        });

        $this->newLine(2);
        $this->table(
            ['Created', 'Updated', 'Skipped', 'Failed'],
            [[$created, $updated, $skipped, $failed]]
        );

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function parseFile(string $s3Key, bool $force): string
    {
        $content = Storage::disk('s3')->get($s3Key);
        $hash    = hash('sha256', $content);

        $existing = IrmDocument::where('s3_key', $s3Key)->first();

        if ($existing && ! $force && $existing->file_hash === $hash) {
            return 'skipped';
        }

        libxml_use_internal_errors(true);
        $xml = new SimpleXMLElement($content, LIBXML_NOCDATA);

        // Register IRM namespace for xpath
        $namespaces = $xml->getNamespaces(true);
        $irmNs = $namespaces[''] ?? $namespaces['irm'] ?? null;

        $docMeta = $this->extractDocumentMeta($xml, $s3Key, $hash);

        if ($existing) {
            $existing->update($docMeta);
            $existing->sections()->delete();
            $doc = $existing;
        } else {
            $doc = IrmDocument::create($docMeta);
        }

        $this->extractSections($xml, $doc);

        $doc->update(['last_synced_at' => now()]);

        return $existing ? 'updated' : 'created';
    }

    private function extractDocumentMeta(SimpleXMLElement $xml, string $s3Key, string $hash): array
    {
        // Locate <part>, <chapter>, <section> within <irm>
        $irm     = $xml->irm ?? $xml->children()->irm ?? null;
        $part    = $irm?->part ?? null;
        $chapter = $part?->chapter ?? null;
        $section = $chapter?->section ?? null;

        $partNo    = (int) ($part ? (string) $part['partno'] : 0);
        $chapterNo = (int) ($chapter ? (string) $chapter['chapno'] : 0);
        $sectionNo = (int) ($section ? (string) $section['sectno'] : 0);

        $reference = implode('.', array_filter([$partNo, $chapterNo, $sectionNo]));
        $title     = $section ? (string) ($section->sectiontitle ?? '') : '';

        // Effective date and audience from <mt>
        $mt        = $xml->mt ?? null;
        $effDate   = $mt ? $this->parseIrmDate((string) ($mt->effdate ?? '')) : null;
        $audience  = $mt ? trim((string) ($mt->audience ?? '')) : null;

        $catalogNo = isset($xml['catno']) ? (string) $xml['catno'] : null;

        return [
            'irm_reference'  => $reference ?: pathinfo($s3Key, PATHINFO_FILENAME),
            'part_number'    => $partNo,
            'chapter_number' => $chapterNo,
            'section_number' => $sectionNo,
            'title'          => $title ?: pathinfo($s3Key, PATHINFO_FILENAME),
            'catalog_number' => $catalogNo,
            'effective_date' => $effDate,
            'audience'       => $audience ?: null,
            's3_key'         => $s3Key,
            'file_hash'      => $hash,
        ];
    }

    private function extractSections(SimpleXMLElement $xml, IrmDocument $doc): void
    {
        $irm     = $xml->irm ?? null;
        $part    = $irm?->part ?? null;
        $chapter = $part?->chapter ?? null;
        $section = $chapter?->section ?? null;

        if (! $section) {
            return;
        }

        $partNo    = (int) ($part ? (string) $part['partno'] : $doc->part_number);
        $chapterNo = (int) ($chapter ? (string) $chapter['chapno'] : $doc->chapter_number);
        $sectionNo = (int) ($section ? (string) $section['sectno'] : $doc->section_number);

        $subsectionIndex = 0;

        foreach ($section->subsection1 as $sub1) {
            $subsectionIndex++;
            $ref1 = "{$partNo}.{$chapterNo}.{$sectionNo}.{$subsectionIndex}";
            $this->insertSubsection($sub1, $doc->id, $ref1, 1);

            $sub2Index = 0;
            foreach ($sub1->subsection2 as $sub2) {
                $sub2Index++;
                $ref2 = "{$ref1}.{$sub2Index}";
                $this->insertSubsection($sub2, $doc->id, $ref2, 2);

                $sub3Index = 0;
                foreach ($sub2->subsection3 as $sub3) {
                    $sub3Index++;
                    $ref3 = "{$ref2}.{$sub3Index}";
                    $this->insertSubsection($sub3, $doc->id, $ref3, 3);
                }
            }
        }
    }

    private function insertSubsection(SimpleXMLElement $node, int $docId, string $reference, int $depth): void
    {
        $xmlId   = isset($node['id']) ? (string) $node['id'] : null;
        $title   = isset($node->title) ? trim((string) $node->title) : null;
        $date    = isset($node->date) ? $this->parseIrmDate((string) $node->date) : null;
        $body    = $this->extractBodyText($node);

        if (empty(trim($body))) {
            return;
        }

        IrmSection::create([
            'irm_document_id' => $docId,
            'xml_id'          => $xmlId,
            'irm_reference'   => $reference,
            'depth'           => $depth,
            'title'           => $title,
            'effective_date'  => $date,
            'body_text'       => $body,
        ]);
    }

    private function extractBodyText(SimpleXMLElement $node): string
    {
        $parts = [];

        foreach ($node->children() as $child) {
            $tag = $child->getName();

            if (in_array($tag, ['title', 'date', 'toc', 'subsection1', 'subsection2', 'subsection3'], true)) {
                continue;
            }

            $parts[] = $this->nodeToText($child);
        }

        return trim(implode("\n", array_filter($parts)));
    }

    private function nodeToText(SimpleXMLElement $node): string
    {
        $tag  = $node->getName();
        $text = '';

        if (in_array($tag, ['para', 'blockpara'], true)) {
            $text = trim($this->innerText($node));
        } elseif (in_array($tag, ['bulletlist', 'alphalist'], true)) {
            $items = [];
            foreach ($node->li as $li) {
                $items[] = '- ' . trim($this->innerText($li));
            }
            $text = implode("\n", $items);
        } elseif ($tag === 'table') {
            $text = $this->tableToText($node);
        } else {
            // Catch-all: grab all text content
            $text = trim($this->innerText($node));
        }

        return $text;
    }

    private function tableToText(SimpleXMLElement $table): string
    {
        $rows = [];

        foreach ($table->xpath('.//row') ?: [] as $row) {
            $cells = [];
            foreach ($row->entry as $entry) {
                $cells[] = trim($this->innerText($entry));
            }
            if ($cells) {
                $rows[] = implode(' | ', $cells);
            }
        }

        return implode("\n", $rows);
    }

    private function innerText(SimpleXMLElement $node): string
    {
        // dom_import_simplexml gives us access to textContent
        $dom = dom_import_simplexml($node);
        return $dom ? $dom->textContent : (string) $node;
    }

    private function parseIrmDate(string $raw): ?string
    {
        // IRM dates look like "(05-20-2026)" or "May 20, 2026"
        $raw = trim($raw, "() \t\n\r");

        if (empty($raw)) {
            return null;
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
