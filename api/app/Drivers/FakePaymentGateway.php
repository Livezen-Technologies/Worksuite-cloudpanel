<?php

namespace App\Drivers;

use App\Contracts\PaymentGateway;
use App\Models\Organization;
use App\Models\Plan;
use Illuminate\Support\Str;

class FakePaymentGateway implements PaymentGateway
{
    public function ensureCustomer(Organization $organization): string
    {
        if (! $organization->payment_customer_id) {
            $organization->forceFill(['payment_customer_id' => 'cus_fake_'.Str::random(12)])->save();
        }

        return $organization->payment_customer_id;
    }

    public function attachPaymentMethod(Organization $organization, string $token): array
    {
        $this->ensureCustomer($organization);
        $brand = str_contains($token, 'mastercard') ? 'mastercard' : 'visa';

        return ['brand' => $brand, 'last4' => $brand === 'visa' ? '4242' : '4444', 'exp_month' => 12, 'exp_year' => (int) now()->addYears(3)->format('Y')];
    }

    public function subscribe(Organization $organization, Plan $plan): string
    {
        $this->ensureCustomer($organization);

        return 'sub_fake_'.$plan->code.'_'.Str::random(8);
    }
}
