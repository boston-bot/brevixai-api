<?php

namespace App\Services;

use App\Models\Subscription;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Event;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;

class StripeService
{
    private StripeClient $stripe;

    public function __construct(string $secretKey, private readonly string $webhookSecret)
    {
        $this->stripe = new StripeClient($secretKey);
    }

    /**
     * Returns an existing Stripe customer ID or creates a new customer and persists
     * the ID to the local subscription record.
     */
    public function createOrRetrieveCustomer(string $companyId, string $email, string $companyName): string
    {
        $subscription = Subscription::where('company_id', $companyId)->first();

        if ($subscription?->stripe_customer_id) {
            // Verify the stored ID is real — stale mock IDs from dev/testing would fail otherwise
            try {
                $this->stripe->customers->retrieve($subscription->stripe_customer_id);
                return $subscription->stripe_customer_id;
            } catch (\Stripe\Exception\InvalidRequestException) {
                // Customer doesn't exist in Stripe — fall through to create a new one
            }
        }

        $customer = $this->stripe->customers->create([
            'email' => $email,
            'name' => $companyName,
            'metadata' => ['company_id' => $companyId],
        ]);

        Subscription::updateOrCreate(
            ['company_id' => $companyId],
            ['stripe_customer_id' => $customer->id, 'updated_at' => now()]
        );

        return $customer->id;
    }

    /**
     * Attaches a payment method to the customer and sets it as their default.
     */
    public function attachPaymentMethod(string $customerId, string $paymentMethodId): void
    {
        $this->stripe->paymentMethods->attach($paymentMethodId, ['customer' => $customerId]);

        $this->stripe->customers->update($customerId, [
            'invoice_settings' => ['default_payment_method' => $paymentMethodId],
        ]);
    }

    /**
     * Creates a Stripe subscription. Expands latest_invoice.payment_intent so the
     * caller can return a client_secret for 3D-Secure confirmation if needed.
     */
    public function createSubscription(string $customerId, string $priceId): StripeSubscription
    {
        return $this->stripe->subscriptions->create([
            'customer' => $customerId,
            'items' => [['price' => $priceId]],
            'payment_behavior' => 'default_incomplete',
            'payment_settings' => ['save_default_payment_method' => 'on_subscription'],
            'expand' => ['latest_invoice.payment_intent'],
        ]);
    }

    /**
     * Cancels a Stripe subscription immediately.
     */
    public function cancelSubscription(string $stripeSubscriptionId): void
    {
        $this->stripe->subscriptions->cancel($stripeSubscriptionId);
    }

    /**
     * Verifies and constructs a Stripe webhook event from the raw request payload.
     *
     * @throws SignatureVerificationException
     */
    public function constructWebhookEvent(string $payload, string $sigHeader): Event
    {
        return \Stripe\Webhook::constructEvent($payload, $sigHeader, $this->webhookSecret);
    }
}
