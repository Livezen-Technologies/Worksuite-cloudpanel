<?php

/*
| Partner provisioning (docs/PARTNER_API.md): WorkSuite Cloud (worksuitecloud.com)
| deploys and manages WorkSuite Business and WorkSuite CMS here on its customers'
| behalf, server to server, with a scoped HMAC credential per partner client
| (`php artisan partner:client`). Partner customers never get a panel login; the
| service user that owns each partner organisation holds the customer `owner`
| role only, so infrastructure staff permissions stay with panel staff.
*/
return [
    // Seconds a signed request stays valid either side of now; nonces are remembered this long.
    'signature_tolerance' => (int) env('PARTNER_SIGNATURE_TOLERANCE', 300),

    // The hidden plan partner organisations are placed on; WorkSuite Cloud's entitlements are the real limit.
    'plan_code' => 'partner',
    'plan' => [
        'name' => 'Partner (WorkSuite Cloud)', 'vcpu' => 4, 'ram_mb' => 8192, 'storage_gb' => 500, 'price_cents' => 0, 'sort' => 100,
        'limits' => ['apps' => 50, 'ram_mb' => 131072, 'storage_gb' => 10000, 'bandwidth_gb' => 10000, 'backups_per_day' => 48, 'backup_retention_days' => 90],
    ],

    // Product → app template (config/platform.php app_templates).
    'products' => [
        'business' => 'worksuite-business',
        'cms' => 'worksuite-cms',
    ],

    // Storage allocation (GB) → instance size; the smallest size that holds it.
    'sizes' => ['small' => 5, 'standard' => 10, 'pro' => 40],

    'scopes' => ['deployments:read', 'deployments:write', 'deployments:operate', 'deployments:lifecycle'],

    // Outbound webhooks: attempts before a delivery is parked as failed, and the backoff base (s).
    'webhook_max_attempts' => 12,
    'webhook_backoff' => 30,
];
