<?php

/** Invoices (UBIL-004) and the payment provider (UBIL-003). */
return [
    'currency' => env('BILLING_CURRENCY', 'EUR'),
    'tax_rate' => (float) env('BILLING_TAX_RATE', 0.19),   // 0.19 = 19 % VAT
    'tax_label' => env('BILLING_TAX_LABEL', 'VAT'),
    'invoice_prefix' => env('BILLING_INVOICE_PREFIX', 'INV'),
    'disk' => env('BILLING_INVOICE_DISK', 'local'),
    'seller' => [
        'name' => env('BILLING_SELLER_NAME', 'LiveZen Hosting'),
        'address' => env('BILLING_SELLER_ADDRESS', ''),
        'vat_id' => env('BILLING_SELLER_VAT_ID', ''),
    ],
    'pdf_ttl_minutes' => 30,
    'hours_per_month' => 730,

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'webhook_tolerance' => 300,
        // Stripe Price ids per plan code (recurring, monthly).
        'prices' => [
            'small' => env('STRIPE_PRICE_SMALL'),
            'standard' => env('STRIPE_PRICE_STANDARD'),
            'pro' => env('STRIPE_PRICE_PRO'),
        ],
        'return_url' => env('STRIPE_RETURN_URL', env('PANEL_URL', 'http://localhost').'/app/billing'),
    ],
];
