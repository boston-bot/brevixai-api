<?php

namespace App\Console\Commands;

use App\Services\RecommendationExpirationService;
use Illuminate\Console\Command;

class ExpireRecommendations extends Command
{
    protected $signature = 'recommendations:expire';

    protected $description = 'Expire stale pending alert and case recommendations';

    public function handle(RecommendationExpirationService $expirationService): int
    {
        $expirationDays = (int) config('recommendations.expiration_days', 30);

        if ($expirationDays < 1) {
            $this->error('Recommendation expiration days must be at least 1.');

            return self::FAILURE;
        }

        $result = $expirationService->expirePending($expirationDays);

        $this->info("Expired {$result['total_expired']} recommendation(s).");
        $this->line("Alert recommendations expired: {$result['alert_expired']}");
        $this->line("Case recommendations expired: {$result['case_expired']}");
        $this->line("Expiration window: {$result['expiration_days']} day(s)");
        $this->line("Expired recommendations created before: {$result['cutoff']}");

        return self::SUCCESS;
    }
}
