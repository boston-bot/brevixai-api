<?php

namespace App\Http\Middleware;

use App\Services\PlanPolicyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates paid-only surfaces (Rex chat) behind an active Pro subscription.
 * Returns a structured 403 with a stable code so the frontend can show an
 * upgrade prompt instead of a generic error.
 */
class EnsureActivePaidSubscription
{
    public function __construct(private readonly PlanPolicyService $planPolicy)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $companyId = $request->user()?->company_id;

        if (! $companyId) {
            return $this->denied();
        }

        $subscription = $this->planPolicy->subscriptionForCompany((string) $companyId);

        if ($subscription['tier'] !== 'pro' || ($subscription['status'] ?? null) !== 'active') {
            return $this->denied();
        }

        return $next($request);
    }

    private function denied(): Response
    {
        return response()->json([
            'error' => 'Rex requires the Pro plan.',
            'code' => 'SUBSCRIPTION_REQUIRED',
        ], 403);
    }
}
