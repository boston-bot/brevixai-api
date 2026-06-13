<?php

namespace App\Console\Commands;

use App\Services\InvestigationBackfillService;
use Illuminate\Console\Command;
use Throwable;

class BackfillInvestigations extends Command
{
    protected $signature = 'investigations:backfill
        {--company= : Limit backfill to a workspace/company UUID}
        {--business-profile= : Limit backfill to a business profile UUID}
        {--limit= : Maximum rows to read from each legacy source table}
        {--dry-run : Count rows without writing canonical records}';

    protected $description = 'Backfill canonical investigations and findings from legacy case and source tables';

    public function handle(InvestigationBackfillService $backfill): int
    {
        $limit = $this->option('limit');

        try {
            $result = $backfill->run(
                companyId: $this->option('company') ?: null,
                businessProfileId: $this->option('business-profile') ?: null,
                limit: $limit === null ? null : max((int) $limit, 1),
                dryRun: (bool) $this->option('dry-run'),
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($this->option('dry-run') ? 'Investigation backfill dry run complete.' : 'Investigation backfill complete.');
        foreach ($result as $key => $count) {
            $this->line(str_replace('_', ' ', ucfirst($key)).": {$count}");
        }

        return self::SUCCESS;
    }
}
