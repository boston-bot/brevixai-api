<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class FetchIrmDocuments extends Command
{
    protected $signature = 'irm:fetch
                            {--dry-run : List discovered zip URLs without downloading}
                            {--limit= : Maximum number of zip files to process}
                            {--prefix=irm : S3 key prefix for uploaded XML files}';

    protected $description = 'Download IRS IRM zip archives, extract XML files, and upload them to S3';

    private const INDEX_URL = 'https://www.irs.gov/downloads/irm';
    private const ZIP_BASE  = 'https://www.irs.gov/pub/irm/';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $limit  = $this->option('limit') ? (int) $this->option('limit') : null;
        $prefix = rtrim($this->option('prefix'), '/');

        $this->info('Discovering IRM zip files...');
        $urls = $this->discoverZipUrls($limit);

        if (empty($urls)) {
            $this->error('No zip URLs found. The IRS page structure may have changed.');
            return self::FAILURE;
        }

        $this->info(sprintf('Found %d zip file(s).', count($urls)));

        if ($dryRun) {
            foreach ($urls as $url) {
                $this->line($url);
            }
            return self::SUCCESS;
        }

        $processed = 0;
        $failed    = 0;
        $uploaded  = 0;
        $bytes     = 0;

        $this->withProgressBar($urls, function (string $url) use ($prefix, &$processed, &$failed, &$uploaded, &$bytes) {
            try {
                [$xmlCount, $xmlBytes] = $this->processZip($url, $prefix);
                $uploaded  += $xmlCount;
                $bytes     += $xmlBytes;
                $processed++;
            } catch (\Throwable $e) {
                Log::error('irm:fetch failed for zip', ['url' => $url, 'error' => $e->getMessage()]);
                $failed++;
            }
        });

        $this->newLine(2);
        $this->table(
            ['Zips processed', 'Zips failed', 'XMLs uploaded', 'Total bytes'],
            [[$processed, $failed, $uploaded, number_format($bytes)]]
        );

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function discoverZipUrls(?int $limit): array
    {
        $urls = [];
        $page = 1;

        while (true) {
            $response = Http::timeout(30)
                ->withHeaders(['User-Agent' => 'BrevixAI IRM Ingestion/1.0'])
                ->get(self::INDEX_URL, ['page' => $page]);

            if (! $response->successful()) {
                $this->warn("Page {$page} returned HTTP {$response->status()}, stopping discovery.");
                break;
            }

            $found = $this->extractZipLinks($response->body());

            if (empty($found)) {
                break;
            }

            foreach ($found as $url) {
                $urls[] = $url;
                if ($limit !== null && count($urls) >= $limit) {
                    return array_unique($urls);
                }
            }

            $page++;
            sleep(1);
        }

        return array_unique($urls);
    }

    private function extractZipLinks(string $html): array
    {
        preg_match_all('/<a[^>]+href=["\']([^"\']+\.zip)["\'][^>]*>/i', $html, $matches);

        $urls = [];
        foreach ($matches[1] as $href) {
            // Normalize: accept absolute IRS pub URLs or relative /pub/irm paths
            if (str_starts_with($href, self::ZIP_BASE)) {
                $urls[] = $href;
            } elseif (str_starts_with($href, '/pub/irm/') && str_ends_with($href, '.zip')) {
                $urls[] = 'https://www.irs.gov' . $href;
            }
        }

        return $urls;
    }

    /**
     * Download a zip, extract XMLs, upload to S3.
     *
     * @return array{int, int} [xml_count, total_bytes]
     */
    private function processZip(string $url, string $prefix): array
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'irm_');

        try {
            Http::timeout(120)
                ->withHeaders(['User-Agent' => 'BrevixAI IRM Ingestion/1.0'])
                ->sink($tmpFile)
                ->get($url);

            $zip = new ZipArchive();
            if ($zip->open($tmpFile) !== true) {
                throw new \RuntimeException("Failed to open zip: {$url}");
            }

            $zipBasename = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME);
            $xmlCount    = 0;
            $totalBytes  = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (! str_ends_with(strtolower($name), '.xml')) {
                    continue;
                }

                $content = $zip->getFromIndex($i);
                if ($content === false) {
                    continue;
                }

                $s3Key = "{$prefix}/{$zipBasename}/" . basename($name);
                Storage::disk('s3')->put($s3Key, $content);

                $xmlCount++;
                $totalBytes += strlen($content);
            }

            $zip->close();

            return [$xmlCount, $totalBytes];
        } finally {
            @unlink($tmpFile);
        }
    }
}
