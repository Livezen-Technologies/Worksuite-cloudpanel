<?php

/*
| SVC-1: the third-party services the panel can be connected to, by category.
| Each field: [key, label, kind] where kind is text | secret | select:<a,b> | url.
| Secrets are stored encrypted and never returned; "test" names a connection check.
*/
return [
    'categories' => [
        'payments' => 'Payments',
        'dns' => 'DNS and CDN',
        'login' => 'Sign-in apps',
        'captcha' => 'Captcha and bot protection',
        'email' => 'Email delivery',
        'git' => 'Git providers',
        'cloud' => 'Cloud and VPS providers',
        'storage' => 'Object storage',
        'notify' => 'Notifications',
        'monitoring' => 'Monitoring',
    ],
    'services' => [
        'stripe' => ['Stripe', 'payments', 'Cards, wallets and subscriptions', [['publishable_key', 'Publishable key', 'text'], ['secret_key', 'Secret key', 'secret'], ['webhook_secret', 'Webhook signing secret', 'secret'], ['mode', 'Mode', 'select:test,live']], 'stripe'],
        'paypal' => ['PayPal', 'payments', 'PayPal and Venmo checkout', [['client_id', 'Client ID', 'text'], ['client_secret', 'Client secret', 'secret'], ['webhook_id', 'Webhook ID', 'text'], ['mode', 'Mode', 'select:sandbox,live']], 'paypal'],
        'payhere' => ['PayHere', 'payments', 'Sri Lankan cards and bank transfers', [['merchant_id', 'Merchant ID', 'text'], ['merchant_secret', 'Merchant secret', 'secret'], ['mode', 'Mode', 'select:sandbox,live']], null],
        'cloudflare' => ['Cloudflare', 'dns', 'DNS zones, proxy and tunnels', [['api_token', 'API token', 'secret'], ['account_id', 'Account ID', 'text']], 'cloudflare'],
        'route53' => ['Amazon Route 53', 'dns', 'DNS zones on AWS', [['access_key_id', 'Access key ID', 'text'], ['secret_access_key', 'Secret access key', 'secret'], ['region', 'Region', 'text']], null],
        'google_login' => ['Google', 'login', 'Sign in with Google', [['client_id', 'Client ID', 'text'], ['client_secret', 'Client secret', 'secret'], ['allowed_domains', 'Allowed email domains (optional)', 'text']], null],
        'github_login' => ['GitHub', 'login', 'Sign in with GitHub', [['client_id', 'Client ID', 'text'], ['client_secret', 'Client secret', 'secret']], null],
        'microsoft_login' => ['Microsoft', 'login', 'Sign in with Microsoft Entra ID', [['tenant_id', 'Tenant ID', 'text'], ['client_id', 'Client ID', 'text'], ['client_secret', 'Client secret', 'secret']], null],
        'oidc' => ['OpenID Connect', 'login', 'Any OIDC provider (Okta, Keycloak, Authentik…)', [['issuer', 'Issuer URL', 'url'], ['client_id', 'Client ID', 'text'], ['client_secret', 'Client secret', 'secret']], 'oidc'],
        'turnstile' => ['Cloudflare Turnstile', 'captcha', 'Privacy-friendly captcha', [['site_key', 'Site key', 'text'], ['secret_key', 'Secret key', 'secret']], null],
        'recaptcha' => ['Google reCAPTCHA v3', 'captcha', 'Score-based bot check', [['site_key', 'Site key', 'text'], ['secret_key', 'Secret key', 'secret'], ['min_score', 'Minimum score (0–1)', 'text']], null],
        'hcaptcha' => ['hCaptcha', 'captcha', 'Captcha with accessibility mode', [['site_key', 'Site key', 'text'], ['secret_key', 'Secret key', 'secret']], null],
        'postmark' => ['Postmark', 'email', 'Transactional email', [['server_token', 'Server token', 'secret'], ['from', 'From address', 'text']], 'postmark'],
        'sendgrid' => ['SendGrid', 'email', 'Transactional email', [['api_key', 'API key', 'secret'], ['from', 'From address', 'text']], null],
        'mailgun' => ['Mailgun', 'email', 'Transactional email', [['api_key', 'API key', 'secret'], ['domain', 'Sending domain', 'text'], ['region', 'Region', 'select:us,eu']], null],
        'ses' => ['Amazon SES', 'email', 'Transactional email on AWS', [['access_key_id', 'Access key ID', 'text'], ['secret_access_key', 'Secret access key', 'secret'], ['region', 'Region', 'text']], null],
        'github' => ['GitHub', 'git', 'Repositories and webhooks', [['token', 'Personal access token', 'secret']], 'github'],
        'gitlab' => ['GitLab', 'git', 'Repositories and webhooks', [['url', 'GitLab URL', 'url'], ['token', 'Access token', 'secret']], null],
        'gitea' => ['Gitea', 'git', 'Self-hosted Git', [['url', 'Gitea URL', 'url'], ['token', 'Access token', 'secret']], null],
        'hetzner' => ['Hetzner Cloud', 'cloud', 'Servers and volumes', [['api_token', 'API token', 'secret']], 'hetzner'],
        'digitalocean' => ['DigitalOcean', 'cloud', 'Droplets and volumes', [['api_token', 'API token', 'secret']], 'digitalocean'],
        'vultr' => ['Vultr', 'cloud', 'Instances', [['api_key', 'API key', 'secret']], null],
        'linode' => ['Akamai Linode', 'cloud', 'Linodes', [['api_token', 'API token', 'secret']], null],
        'aws' => ['Amazon Web Services', 'cloud', 'EC2 and more', [['access_key_id', 'Access key ID', 'text'], ['secret_access_key', 'Secret access key', 'secret'], ['region', 'Region', 'text']], null],
        's3' => ['S3-compatible storage', 'storage', 'AWS S3, Backblaze B2, Wasabi, MinIO', [['endpoint', 'Endpoint URL', 'url'], ['bucket', 'Bucket', 'text'], ['region', 'Region', 'text'], ['access_key_id', 'Access key ID', 'text'], ['secret_access_key', 'Secret access key', 'secret']], null],
        'slack' => ['Slack', 'notify', 'Alerts to a channel', [['webhook_url', 'Incoming webhook URL', 'secret']], 'slack'],
        'telegram' => ['Telegram', 'notify', 'Alerts to a chat', [['bot_token', 'Bot token', 'secret'], ['chat_id', 'Chat ID', 'text']], 'telegram'],
        'twilio' => ['Twilio', 'notify', 'SMS alerts', [['account_sid', 'Account SID', 'text'], ['auth_token', 'Auth token', 'secret'], ['from', 'From number', 'text']], null],
        'sentry' => ['Sentry', 'monitoring', 'Error tracking for the panel', [['dsn', 'DSN', 'secret']], null],
    ],
];
