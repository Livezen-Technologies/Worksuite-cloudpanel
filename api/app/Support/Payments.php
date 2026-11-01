<?php

namespace App\Support;

use App\Contracts\PaymentGateway;
use App\Drivers\PayPalPaymentGateway;
use App\Drivers\StripePaymentGateway;
use App\Exceptions\ApiException;
use App\Models\Organization;

/**
 * Which payment providers customers may choose, and the gateway for each organisation.
 * The default (PAYMENT_DRIVER) is always offered; Stripe and PayPal are offered too when
 * their credentials are configured, so customers can pay by card or with PayPal.
 */
class Payments
{
    public const LABELS = ['stripe' => 'Card (Stripe)', 'paypal' => 'PayPal', 'fake' => 'Test payments'];

    /** @return string[] */
    public function available(): array
    {
        $names = [app(PaymentGateway::class)->name()];
        if (config('billing.stripe.secret')) {
            $names[] = 'stripe';
        }
        $pp = PayPalPaymentGateway::config();
        if (! empty($pp['client_id']) && ! empty($pp['secret'])) {
            $names[] = 'paypal';
        }

        return array_values(array_unique($names));
    }

    public function make(string $name): PaymentGateway
    {
        $default = app(PaymentGateway::class);
        if ($default->name() === $name) {
            return $default;
        }

        return match ($name) {
            'stripe' => new StripePaymentGateway(config('billing.stripe')),
            'paypal' => new PayPalPaymentGateway(PayPalPaymentGateway::config()),
            default => throw ApiException::validation(['provider' => ['That payment provider is not available.']]),
        };
    }

    /** The organisation's chosen provider while it is still configured, otherwise the default. */
    public function for(?Organization $org): PaymentGateway
    {
        $chosen = $org?->payment_provider;

        return $chosen && in_array($chosen, $this->available(), true) ? $this->make($chosen) : app(PaymentGateway::class);
    }

    /** Provider that issued a subscription id: Stripe "sub_…", PayPal "I-…"; fake ids belong to none. */
    public static function ownerOf(?string $subscriptionId): ?string
    {
        return match (true) {
            ! $subscriptionId, str_starts_with($subscriptionId, 'sub_fake_') => null,
            str_starts_with($subscriptionId, 'sub_') => 'stripe',
            str_starts_with($subscriptionId, 'I-') => 'paypal',
            default => null,
        };
    }
}
