<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    /**
     * GET /api/subscriptions
     */
    public function show(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        if (!$companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $subscription = Subscription::firstOrCreate(
            ['company_id' => $companyId],
            ['tier' => 'starter', 'status' => 'active']
        );

        return response()->json([
            'tier' => $subscription->tier,
            'status' => $subscription->status,
            'currentPeriodEnd' => $subscription->current_period_end,
        ]);
    }

    /**
     * POST /api/subscriptions/checkout
     */
    public function checkout(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        if (!$companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $validated = $request->validate([
            'tier' => ['required', 'string', Rule::in(['starter', 'growth', 'accounting', 'accounting-firm'])],
            'paymentMethod' => ['required', 'array'],
            'paymentMethod.cardName' => ['required', 'string', 'max:255'],
            'paymentMethod.lastFour' => ['required', 'digits:4'],
            'paymentMethod.expiry' => ['required', 'string', 'regex:/^\d{2}\/\d{2}$/'],
        ]);

        $tier = $this->normalizeTier($validated['tier']);

        $subscription = Subscription::updateOrCreate(
            ['company_id' => $companyId],
            [
                'tier' => $tier,
                'status' => 'active',
                'updated_at' => now(),
            ]
        );

        return response()->json([
            'status' => 'succeeded',
            'tier' => $subscription->tier,
            'subscription' => [
                'companyId' => $subscription->company_id,
                'tier' => $subscription->tier,
                'status' => $subscription->status,
                'currentPeriodEnd' => $subscription->current_period_end,
            ],
        ]);
    }

    /**
     * POST /api/subscriptions/cancel
     */
    public function cancel(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        if (!$companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $subscription = Subscription::updateOrCreate(
            ['company_id' => $companyId],
            [
                'tier' => 'starter',
                'status' => 'canceled',
                'updated_at' => now(),
            ]
        );

        return response()->json([
            'status' => 'canceled',
            'tier' => $subscription->tier,
        ]);
    }

    private function normalizeTier(string $tier): string
    {
        return $tier === 'accounting-firm' ? 'accounting' : $tier;
    }
}
