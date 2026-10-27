<?php

namespace App\Drivers;

use App\Contracts\PaymentGateway;
use App\Exceptions\ApiException;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Stripe\Event;
use Stripe\StripeClient;

/**
 * Stripe (UBIL-003). One Customer per organisation, one Subscription per plan (Price ids in
 * config/billing.php), cards are collected by Stripe Checkout / the Customer Portal, so card data
 * never touches our servers. Usage lines of the monthly invoice become Stripe invoice items that
 * Stripe adds to the next subscription invoice; webhooks update subscription and invoice status.
 */
class StripePaymentGateway implements PaymentGateway
{
    private ?StripeClient $client = null;

    public function __construct(private array $config) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function client(): StripeClient
    {
        if (! $this->config['secret']) {
            throw new \RuntimeException('STRIPE_SECRET is not configured.');
        }

        return $this->client ??= new StripeClient(['api_key' => $this->config['secret'], 'stripe_version' => '2024-06-20']);
    }

    public function ensureCustomer(Organization $organization): string
    {
        if (! $organization->payment_customer_id || str_starts_with($organization->payment_customer_id, 'cus_fake_')) {
            $customer = $this->client()->customers->create(array_filter([
                'name' => $organization->name, 'email' => $organization->billing_email,
                'metadata' => ['organization_id' => $organization->id],
            ]));
            $organization->forceFill(['payment_customer_id' => $customer->id])->save();
        }

        return $organization->payment_customer_id;
    }

    public function attachPaymentMethod(Organization $organization, string $token): array
    {
        $customer = $this->ensureCustomer($organization);
        $pm = $this->client()->paymentMethods->attach($token, ['customer' => $customer]);
        $this->client()->customers->update($customer, ['invoice_settings' => ['default_payment_method' => $pm->id]]);

        return self::cardSummary($pm);
    }

    public static function cardSummary($pm): array
    {
        $card = $pm->card ?? null;

        return ['brand' => $card->brand ?? 'card', 'last4' => $card->last4 ?? '', 'exp_month' => $card->exp_month ?? null, 'exp_year' => $card->exp_year ?? null];
    }

    public function subscribe(Organization $organization, Plan $plan): string
    {
        $price = $this->config['prices'][$plan->code] ?? null;
        if (! $price) {
            throw ApiException::conflict("No Stripe price is configured for the {$plan->name} plan (STRIPE_PRICE_".strtoupper($plan->code).').');
        }
        $customer = $this->ensureCustomer($organization);
        $current = Subscription::where('organization_id', $organization->id)->whereNotNull('provider_subscription_id')
            ->where('provider_subscription_id', 'like', 'sub\_%')->where('provider_subscription_id', 'not like', 'sub_fake_%')->latest()->value('provider_subscription_id');
        if ($current) {
            $sub = $this->client()->subscriptions->retrieve($current);
            if (! in_array($sub->status, ['canceled', 'incomplete_expired'], true)) {
                $this->client()->subscriptions->update($current, [
                    'items' => [['id' => $sub->items->data[0]->id, 'price' => $price]],
                    'proration_behavior' => 'create_prorations', 'metadata' => ['organization_id' => $organization->id, 'plan' => $plan->code],
                ]);

                return $current;
            }
        }
        $sub = $this->client()->subscriptions->create([
            'customer' => $customer, 'items' => [['price' => $price]],
            'payment_behavior' => 'default_incomplete', 'metadata' => ['organization_id' => $organization->id, 'plan' => $plan->code],
        ]);

        return $sub->id;
    }

    public function portalUrl(Organization $organization, string $returnUrl): string
    {
        $customer = $this->ensureCustomer($organization);
        if ($organization->payment_method) {
            return $this->client()->billingPortal->sessions->create(['customer' => $customer, 'return_url' => $returnUrl])->url;
        }

        // No card yet: Checkout in setup mode collects one on Stripe's page.
        return $this->client()->checkout->sessions->create([
            'mode' => 'setup', 'customer' => $customer, 'currency' => strtolower(config('billing.currency')),
            'payment_method_types' => ['card'], 'success_url' => $returnUrl.'?checkout=success', 'cancel_url' => $returnUrl,
            'metadata' => ['organization_id' => $organization->id],
        ])->url;
    }

    public function collect(Organization $organization, Invoice $invoice): array
    {
        $customer = $this->ensureCustomer($organization);
        $currency = strtolower($invoice->currency);
        $items = array_values(array_filter($invoice->line_items ?? [], fn ($l) => ($l['kind'] ?? '') !== 'plan' && $l['amount_cents'] > 0));
        // The plan line is charged by the subscription; usage lines (and their tax) become invoice items.
        $usage = (int) array_sum(array_column($items, 'amount_cents'));
        foreach ($items as $line) {
            $this->client()->invoiceItems->create([
                'customer' => $customer, 'amount' => $line['amount_cents'], 'currency' => $currency,
                'description' => $line['description'], 'metadata' => ['dmp_invoice_id' => $invoice->id, 'dmp_invoice_number' => $invoice->number],
            ]);
        }
        if ($usage > 0 && $invoice->tax_rate > 0) {
            $this->client()->invoiceItems->create([
                'customer' => $customer, 'amount' => (int) round($usage * $invoice->tax_rate), 'currency' => $currency,
                'description' => config('billing.tax_label').' on usage', 'metadata' => ['dmp_invoice_id' => $invoice->id],
            ]);
        }

        return ['status' => 'open', 'provider_invoice_id' => null];
    }

    /** Verifies the Stripe-Signature header; throws on mismatch. */
    public function constructEvent(string $payload, string $signature): Event
    {
        if (! $this->config['webhook_secret']) {
            throw new \RuntimeException('STRIPE_WEBHOOK_SECRET is not configured.');
        }

        return \Stripe\Webhook::constructEvent($payload, $signature, $this->config['webhook_secret'], $this->config['webhook_tolerance'] ?? 300);
    }

    /** Applies a verified event once (payment_events.event_id is unique). Returns false for duplicates. */
    public function handle(Event $event): bool
    {
        $object = $event->data->object;
        $customerId = is_string($object->customer ?? null) ? $object->customer : ($object->customer->id ?? null);
        $org = $customerId ? Organization::where('payment_customer_id', $customerId)->first() : null;
        $fresh = DB::table('payment_events')->insertOrIgnore([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'provider' => 'stripe', 'event_id' => $event->id, 'type' => $event->type,
            'organization_id' => $org?->id, 'created_at' => now(),
        ]);
        if (! $fresh || ! $org) {
            return (bool) $fresh;
        }
        app(Tenant::class)->as($org->id, fn () => match ($event->type) {
            'checkout.session.completed' => $this->checkoutCompleted($org, $object),
            'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted' => $this->subscriptionChanged($object),
            'invoice.paid', 'invoice.payment_succeeded' => $this->invoicePaid($org, $object),
            'invoice.payment_failed' => $this->invoiceFailed($org, $object),
            'payment_method.attached' => $org->update(['payment_method' => self::cardSummary($object)]),
            default => null,
        });

        return true;
    }

    private function checkoutCompleted(Organization $org, $session): void
    {
        if (($session->mode ?? null) === 'setup' && ($session->setup_intent ?? null)) {
            $intent = $this->client()->setupIntents->retrieve(is_string($session->setup_intent) ? $session->setup_intent : $session->setup_intent->id, ['expand' => ['payment_method']]);
            $pm = $intent->payment_method;
            if ($pm) {
                $pmId = is_string($pm) ? $pm : $pm->id;
                $this->client()->customers->update($org->payment_customer_id, ['invoice_settings' => ['default_payment_method' => $pmId]]);
                $org->update(['payment_method' => is_string($pm) ? self::cardSummary($this->client()->paymentMethods->retrieve($pm)) : self::cardSummary($pm)]);
            }
        }
        if (($session->mode ?? null) === 'subscription' && ($session->subscription ?? null)) {
            Subscription::latest()->first()?->update(['provider_subscription_id' => is_string($session->subscription) ? $session->subscription : $session->subscription->id]);
        }
    }

    private function subscriptionChanged($sub): void
    {
        $local = Subscription::where('provider_subscription_id', $sub->id)->first();
        if (! $local) {
            return;
        }
        $local->update(array_filter([
            'status' => match ($sub->status) { 'active', 'trialing' => 'active', 'past_due', 'unpaid' => 'past_due', 'canceled', 'incomplete_expired' => 'canceled', default => $sub->status },
            'current_period_start' => isset($sub->current_period_start) ? Carbon::createFromTimestamp($sub->current_period_start) : null,
            'current_period_end' => isset($sub->current_period_end) ? Carbon::createFromTimestamp($sub->current_period_end) : null,
        ]));
    }

    /** Our invoices referenced by line metadata, plus open ones whose period ended before the paid one. */
    private function matchingInvoices($stripeInvoice)
    {
        $ids = collect($stripeInvoice->lines->data ?? [])->map(fn ($l) => $l->metadata->dmp_invoice_id ?? null)->filter()->unique();
        $periodEnd = isset($stripeInvoice->period_end) ? Carbon::createFromTimestamp($stripeInvoice->period_end)->toDateString() : null;

        return Invoice::where(fn ($q) => $q->whereIn('id', $ids)->orWhere('provider_invoice_id', $stripeInvoice->id)
            ->orWhere(fn ($q) => $periodEnd ? $q->whereIn('status', ['open', 'failed'])->where('period_end', '<=', $periodEnd) : $q->whereRaw('1=0')))->get();
    }

    private function invoicePaid(Organization $org, $stripeInvoice): void
    {
        foreach ($this->matchingInvoices($stripeInvoice) as $invoice) {
            $invoice->update(['status' => 'paid', 'paid_at' => now(), 'provider_invoice_id' => $invoice->provider_invoice_id ?: $stripeInvoice->id]);
        }
        if ($stripeInvoice->subscription ?? null) {
            Subscription::where('provider_subscription_id', $stripeInvoice->subscription)->update(['status' => 'active']);
        }
    }

    private function invoiceFailed(Organization $org, $stripeInvoice): void
    {
        foreach ($this->matchingInvoices($stripeInvoice) as $invoice) {
            $invoice->status === 'paid' || $invoice->update(['status' => 'failed', 'provider_invoice_id' => $invoice->provider_invoice_id ?: $stripeInvoice->id]);
        }
        if ($stripeInvoice->subscription ?? null) {
            Subscription::where('provider_subscription_id', $stripeInvoice->subscription)->update(['status' => 'past_due']);
        }
    }
}
