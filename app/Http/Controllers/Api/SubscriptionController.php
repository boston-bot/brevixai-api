<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\PlanPolicyService;
use App\Services\StripeService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly StripeService $stripeService,
        private readonly PlanPolicyService $planPolicy,
    ) {}

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
            ['tier' => 'free', 'status' => 'active']
        );

        return response()->json([
            'tier' => $subscription->tier,
            'status' => $subscription->status,
            'currentPeriodEnd' => $subscription->current_period_end,
        ]);
    }

    /**
     * POST /api/subscriptions/checkout
     *
     * Expects { tier, paymentMethodId } where paymentMethodId is a pm_xxx token
     * collected by Stripe.js on the frontend — never raw card numbers.
     */
    public function checkout(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        if (!$companyId) {
            return response()->json(['error' => 'No company associated with account'], 403);
        }

        $validated = $request->validate([
            'tier' => ['required', 'string', Rule::in(['free', 'starter', 'growth', 'risk-advisory', 'accounting', 'accounting-firm'])],
            'paymentMethodId' => ['required', 'string'],
        ]);

        $tier = $this->normalizeTier($validated['tier']);

        if (in_array($tier, ['free', 'starter'], true)) {
            return response()->json(['error' => "No payment required for the {$tier} tier"], 422);
        }

        $priceId = config("services.stripe.price_ids.{$tier}");
        if (!$priceId) {
            return response()->json(['error' => "No Stripe price configured for tier: {$tier}"], 422);
        }

        try {
            $user = $request->user();
            $company = $user->company;

            $customerId = $this->stripeService->createOrRetrieveCustomer(
                $companyId,
                $user->email,
                $company->name ?? $companyId
            );

            $this->stripeService->attachPaymentMethod($customerId, $validated['paymentMethodId']);

            $stripeSub = $this->stripeService->createSubscription($customerId, $priceId);

            DB::beginTransaction();

            $subscription = Subscription::updateOrCreate(
                ['company_id' => $companyId],
                [
                    'tier' => $tier,
                    'status' => $stripeSub->status === 'active' ? 'active' : $stripeSub->status,
                    'stripe_customer_id' => $customerId,
                    'stripe_subscription_id' => $stripeSub->id,
                    'current_period_end' => $stripeSub->current_period_end
                        ? \Carbon\Carbon::createFromTimestamp($stripeSub->current_period_end)
                        : null,
                    'updated_at' => now(),
                ]
            );

            DB::commit();

            $response = [
                'tier' => $subscription->tier,
                'subscription' => [
                    'companyId' => $subscription->company_id,
                    'tier' => $subscription->tier,
                    'status' => $subscription->status,
                    'currentPeriodEnd' => $subscription->current_period_end,
                ],
            ];

            // Return client_secret so the frontend can confirm 3DS if required
            if ($stripeSub->status === 'incomplete') {
                $paymentIntent = $stripeSub->latest_invoice?->payment_intent;
                $response['status'] = 'requires_action';
                $response['clientSecret'] = $paymentIntent?->client_secret;
            } else {
                $response['status'] = 'succeeded';
            }

            return response()->json($response);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
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

        $subscription = Subscription::where('company_id', $companyId)->first();

        if (!$subscription?->stripe_subscription_id) {
            return response()->json(['error' => 'No active Stripe subscription found'], 404);
        }

        try {
            $this->stripeService->cancelSubscription($subscription->stripe_subscription_id);

            $subscription->update([
                'tier' => 'starter',
                'status' => 'canceled',
                'updated_at' => now(),
            ]);

            return response()->json([
                'status' => 'canceled',
                'tier' => $subscription->tier,
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function normalizeTier(string $tier): string
    {
        return $this->planPolicy->normalizeTier($tier);
    }
}
