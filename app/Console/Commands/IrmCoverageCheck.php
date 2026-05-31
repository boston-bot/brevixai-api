<?php

namespace App\Console\Commands;

use App\Models\IrmDocument;
use App\Models\IrmSection;
use Illuminate\Console\Command;

class IrmCoverageCheck extends Command
{
    protected $signature = 'irm:coverage-check {--json : Output counts as JSON}';

    protected $description = 'Verify that the seeded IRM corpus contains all required procedural prefixes.';

    /** @var list<string> */
    private const REQUIRED_PREFIXES = ['4.19', '5.7', '5.11', '5.12', '5.14', '5.19', '8.25', '20.1'];

    public function handle(): int
    {
        $rows = [];
        $missing = [];

        foreach (self::REQUIRED_PREFIXES as $prefix) {
            $pattern = strtolower($prefix) . '.%';

            $docCount = IrmDocument::query()
                ->whereRaw('LOWER(irm_reference) LIKE ? OR LOWER(irm_reference) = ?', [$pattern, strtolower($prefix)])
                ->count();

            $sectionCount = IrmSection::query()
                ->whereRaw('LOWER(irm_reference) LIKE ?', [$pattern])
                ->count();

            $present = $docCount > 0 || $sectionCount > 0;
            $rows[$prefix] = [
                'prefix' => $prefix,
                'documents' => $docCount,
                'sections' => $sectionCount,
                'present' => $present,
            ];

            if (! $present) {
                $missing[] = $prefix;
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode(['prefixes' => array_values($rows), 'missing' => $missing], JSON_PRETTY_PRINT));
        } else {
            $this->table(
                ['Prefix', 'Documents', 'Sections', 'Present'],
                array_map(
                    fn (array $r): array => [$r['prefix'], $r['documents'], $r['sections'], $r['present'] ? 'YES' : 'NO'],
                    $rows
                )
            );

            if ($missing) {
                $this->error('Missing required IRM prefixes: ' . implode(', ', $missing));
            } else {
                $this->info('All required IRM prefixes are present.');
            }
        }

        return $missing ? self::FAILURE : self::SUCCESS;
    }
}
