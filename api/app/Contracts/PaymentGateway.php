<?php

namespace App\Contracts;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;

/** Payment provider boundary (UBIL-003). Card data never reaches this application. */
interface PaymentGateway
{
    public function name(): string;

    public function ensureCustomer(Organization $organization): string;

    /** Attach a provider-side payment method token; returns a display summary only. */
    public function attachPaymentMethod(Organization $organization, string $token): array;

    public function subscribe(Organization $organization, Plan $plan): string;

    /** Hosted page where the customer manages cards / subscription (Stripe Customer Portal or Checkout). */
    public function portalUrl(Organization $organization, string $returnUrl): string;

    /**
     * Collect a monthly invoice. Returns ['status' => paid|open, 'provider_invoice_id' => ?string].
     *
     * @return array{status: string, provider_invoice_id?: ?string}
     */
    public function collect(Organization $organization, Invoice $invoice): array;
}
