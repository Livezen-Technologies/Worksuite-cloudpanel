<?php

namespace App\Contracts;

use App\Models\Organization;
use App\Models\Plan;

/** Payment provider boundary. Card data never reaches this application. */
interface PaymentGateway
{
    public function ensureCustomer(Organization $organization): string;

    /** Attach a provider-side payment method token; returns a display summary only. */
    public function attachPaymentMethod(Organization $organization, string $token): array;

    public function subscribe(Organization $organization, Plan $plan): string;
}
