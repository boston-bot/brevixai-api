<?php

namespace App\Services;

use App\Models\Subscription;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PlanPolicyService
{
    public const FREE_UPLOAD_MAX_BYTES = 25 * 1024 * 1024;
    public const STANDARD_UPLOAD_MAX_BYTES = 100 * 1024 * 1024;

    /** @return array{tier: string, status: string|null} */
    public function subscriptionForCompany(string $companyId): array
    {
        if (! Schema::hasTable('subscriptions')) {
            return ['tier' => 'starter', 'status' => 'active'];
        }

        $subscription = Subscription::where('company_id', $companyId)->first();

        return [
            'tier' => $this->normalizeTier((string) ($subscription->tier ?? 'starter')),
            'status' => $subscription->status ?? null,
        ];
    }

    public function normalizeTier(string $tier): string
    {
        return match ($tier) {
            'accounting', 'accounting-firm' => 'risk-advisory',
            default => $tier,
        };
    }

    public function dailyChatLimit(string $companyId): int
    {
        return match ($this->subscriptionForCompany($companyId)['tier']) {
            'free' => 5,
            'starter' => 25,
            'growth' => 50,
            'risk-advisory' => 200,
            default => 0,
        };
    }

    public function uploadMaxFileSizeBytes(string $companyId): int
    {
        return $this->subscriptionForCompany($companyId)['tier'] === 'free'
            ? self::FREE_UPLOAD_MAX_BYTES
            : self::STANDARD_UPLOAD_MAX_BYTES;
    }

    public function businessProfileLimit(string $companyId): ?int
    {
        return match ($this->subscriptionForCompany($companyId)['tier']) {
            'free', 'starter' => 1,
            default => null,
        };
    }

    /**
     * @throws Exception
     */
    public function ensureUploadFileSizeAllowed(string $companyId, ?int $fileSizeBytes): void
    {
        if ($fileSizeBytes === null) {
            return;
        }

        $maxBytes = $this->uploadMaxFileSizeBytes($companyId);
        if ($fileSizeBytes > $maxBytes) {
            throw new Exception(
                sprintf('File exceeds the %d MB upload limit for your current plan.', (int) floor($maxBytes / 1024 / 1024)),
                422
            );
        }
    }

    /**
     * @throws Exception
     */
    public function ensureCanCreateBusinessProfile(string $companyId): void
    {
        if (! Schema::hasTable('business_profiles')) {
            return;
        }

        $limit = $this->businessProfileLimit($companyId);
        if ($limit === null) {
            return;
        }

        $activeCount = DB::table('business_profiles')
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->count();

        if ($activeCount >= $limit) {
            throw new Exception('Your current plan is limited to one active business profile.', 422);
        }
    }
}
