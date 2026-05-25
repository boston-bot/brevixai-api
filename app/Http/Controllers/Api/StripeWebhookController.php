<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\StripeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;

class StripeWebhookController extends Controller
{
    public function __construct(private readonly StripeService $stripeService) {}

    /**
     * POST /api/webhooks/stripe
     *
     * Stripe sends billing lifecycle events here. Signature is verified before
     * any processing. Always returns 200 for recognised events so Stripe doesn't retry.
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature', '');

        try {
            $event = $this->stripeService->constructWebhookEvent($payload, $sigHeader);
        } catch (SignatureVerificationException) {
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $stripeObject = $event->data->object;

        match ($event->type) {
            'customer.subscription.updated' => $this->syncSubscription($stripeObject),
            'customer.subscription.deleted' => $this->handleDeletion($stripeObject),
            'invoice.paid'                  => $this->handleInvoicePaid($stripeObject),
            'invoice.payment_failed'        => $this->handlePaymentFailed($stripeObject),
            default                         => null,
        };

        return response()->json(['received' => true]);
    }

    private function syncSubscription(\Stripe\Subscription $stripeSub): void
    {
        $subscription = Subscription::where('stripe_subscription_id', $stripeSub->id)->first();

        if (!$subscription) {
            return;
        }

        $subscription->update([
            'status' => $stripeSub->status,
            'current_period_end' => $stripeSub->current_period_end
                ? Carbon::createFromTimestamp($stripeSub->current_period_end)
                : null,
            'updated_at' => now(),
        ]);
    }

    private function handleDeletion(\Stripe\Subscription $stripeSub): void
    {
        $subscription = Subscription::where('stripe_subscription_id', $stripeSub->id)->first();

        if (!$subscription) {
            return;
        }

        $subscription->update([
            'tier' => 'starter',
            'status' => 'canceled',
            'updated_at' => now(),
        ]);
    }

    private function handleInvoicePaid(\Stripe\Invoice $invoice): void
    {
        if (!$invoice->subscription) {
            return;
        }

        $subscription = Subscription::where('stripe_subscription_id', $invoice->subscription)->first();

        if (!$subscription) {
            return;
        }

        $subscription->update([
            'status' => 'active',
            'current_period_end' => $invoice->period_end
                ? Carbon::createFromTimestamp($invoice->period_end)
                : $subscription->current_period_end,
            'updated_at' => now(),
        ]);
    }

    private function handlePaymentFailed(\Stripe\Invoice $invoice): void
    {
        if (!$invoice->subscription) {
            return;
        }

        $subscription = Subscription::where('stripe_subscription_id', $invoice->subscription)->first();

        if (!$subscription) {
            return;
        }

        $subscription->update([
            'status' => 'past_due',
            'updated_at' => now(),
        ]);
    }
}
