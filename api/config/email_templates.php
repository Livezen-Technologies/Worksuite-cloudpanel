<?php

/*
 * EML-1/2: the transactional emails. Each has default copy using {{variables}}; admins can
 * override any field (EML-3). Groups marked mandatory (security, billing) cannot be turned
 * off by users (EML-5). Every template may also use the global variables:
 * {{platform_name}}, {{user_name}}, {{panel_url}}.
 */
return [
    'groups' => [
        'account' => ['name' => 'Account', 'mandatory' => false],
        'security' => ['name' => 'Security', 'mandatory' => true],
        'deployments' => ['name' => 'Deployments', 'mandatory' => false],
        'domains' => ['name' => 'Domains', 'mandatory' => false],
        'backups' => ['name' => 'Backups', 'mandatory' => false],
        'alerts' => ['name' => 'Alerts', 'mandatory' => false],
        'billing' => ['name' => 'Billing', 'mandatory' => true],
    ],

    'templates' => [
        // Account
        'welcome' => ['group' => 'account', 'name' => 'Welcome', 'vars' => [],
            'subject' => 'Welcome to {{platform_name}}', 'preview' => 'Your account is ready.',
            'heading' => 'Welcome, {{user_name}}', 'body' => "Your {{platform_name}} account is ready. Deploy your first app in a few minutes.",
            'button_label' => 'Open the panel', 'button_url' => '{{panel_url}}'],
        'invite' => ['group' => 'account', 'name' => 'Team invitation', 'vars' => ['inviter', 'organization', 'role', 'accept_url'],
            'subject' => '{{inviter}} invited you to {{organization}}', 'preview' => 'Join {{organization}} on {{platform_name}}.',
            'heading' => 'You have been invited', 'body' => "{{inviter}} invited you to join {{organization}} as {{role}}.",
            'button_label' => 'Accept the invitation', 'button_url' => '{{accept_url}}', 'details' => ['Organisation' => '{{organization}}', 'Role' => '{{role}}']],
        'verify_email' => ['group' => 'account', 'name' => 'Verify email', 'vars' => ['verify_url'],
            'subject' => 'Confirm your email address', 'preview' => 'One click to confirm.',
            'heading' => 'Confirm your email', 'body' => 'Confirm this address so we can reach you about your account.',
            'button_label' => 'Confirm email', 'button_url' => '{{verify_url}}'],

        // Security
        'password_reset' => ['group' => 'security', 'name' => 'Password reset', 'vars' => ['reset_url', 'expires_minutes'],
            'subject' => 'Reset your password', 'preview' => 'The link works for {{expires_minutes}} minutes.',
            'heading' => 'Reset your password', 'body' => "Someone asked to reset the password for your account. The link works for {{expires_minutes}} minutes.\n\nIf it was not you, ignore this email; your password stays the same.",
            'button_label' => 'Choose a new password', 'button_url' => '{{reset_url}}'],
        'new_sign_in' => ['group' => 'security', 'name' => 'New sign-in', 'vars' => ['ip', 'location', 'device', 'time'],
            'subject' => 'New sign-in to your account', 'preview' => 'From {{device}} in {{location}}.',
            'heading' => 'New sign-in', 'body' => "Your account was signed in from a new device.\n\nIf this was not you, change your password and turn on two-factor authentication now.",
            'button_label' => 'Review security', 'button_url' => '{{panel_url}}/account', 'details' => ['Time' => '{{time}}', 'Device' => '{{device}}', 'Location' => '{{location}}', 'IP address' => '{{ip}}']],
        'two_factor_disabled' => ['group' => 'security', 'name' => 'Two-factor turned off', 'vars' => ['time'],
            'subject' => 'Two-factor authentication was turned off', 'preview' => 'Your account is less protected.',
            'heading' => 'Two-factor authentication is off', 'body' => "Two-factor authentication was turned off for your account at {{time}}.\n\nIf you did not do this, secure your account now.",
            'button_label' => 'Turn it back on', 'button_url' => '{{panel_url}}/account'],

        // Deployments
        'deploy_succeeded' => ['group' => 'deployments', 'name' => 'Deployment succeeded', 'vars' => ['app', 'version', 'url', 'duration'],
            'subject' => '{{app}} v{{version}} is live', 'preview' => 'Deployed in {{duration}}.',
            'heading' => '{{app}} is live', 'body' => 'Release v{{version}} of {{app}} deployed successfully.',
            'button_label' => 'Open the app', 'button_url' => '{{url}}', 'details' => ['App' => '{{app}}', 'Version' => 'v{{version}}', 'Duration' => '{{duration}}']],
        'deploy_failed' => ['group' => 'deployments', 'name' => 'Deployment failed', 'vars' => ['app', 'stage', 'error', 'deployment_url'],
            'subject' => 'Deployment of {{app}} failed', 'preview' => 'Failed at {{stage}}.',
            'heading' => 'Deployment failed', 'body' => "The deployment of {{app}} failed at the {{stage}} stage. The previous release keeps running.\n\n{{error}}",
            'button_label' => 'See the logs', 'button_url' => '{{deployment_url}}'],
        'preview_ready' => ['group' => 'deployments', 'name' => 'Preview ready', 'vars' => ['app', 'branch', 'url'],
            'subject' => 'Preview of {{app}} ({{branch}}) is ready', 'preview' => 'Check it before merging.',
            'heading' => 'Your preview is ready', 'body' => 'A preview of {{app}} from {{branch}} is running.',
            'button_label' => 'Open the preview', 'button_url' => '{{url}}'],

        // Domains
        'domain_connected' => ['group' => 'domains', 'name' => 'Domain connected', 'vars' => ['domain', 'app'],
            'subject' => '{{domain}} is connected', 'preview' => 'HTTPS is on.',
            'heading' => '{{domain}} is live', 'body' => '{{domain}} now points at {{app}} with a valid HTTPS certificate.',
            'button_label' => 'Visit the site', 'button_url' => 'https://{{domain}}'],
        'certificate_expiring' => ['group' => 'domains', 'name' => 'Certificate expiring', 'vars' => ['domain', 'days', 'reason'],
            'subject' => 'The certificate for {{domain}} expires in {{days}} days', 'preview' => 'Renewal has not succeeded yet.',
            'heading' => 'Certificate expiring', 'body' => "We could not renew the HTTPS certificate for {{domain}}. It expires in {{days}} days.\n\n{{reason}}",
            'button_label' => 'Check the domain', 'button_url' => '{{panel_url}}/domains'],

        // Backups
        'backup_failed' => ['group' => 'backups', 'name' => 'Backup failed', 'vars' => ['target', 'error', 'time'],
            'subject' => 'Backup of {{target}} failed', 'preview' => '{{error}}',
            'heading' => 'Backup failed', 'body' => "The backup of {{target}} at {{time}} did not complete.\n\n{{error}}",
            'button_label' => 'Open backups', 'button_url' => '{{panel_url}}/backups'],
        'restore_complete' => ['group' => 'backups', 'name' => 'Restore complete', 'vars' => ['target', 'snapshot'],
            'subject' => '{{target}} was restored', 'preview' => 'From the snapshot of {{snapshot}}.',
            'heading' => 'Restore complete', 'body' => '{{target}} was restored from the snapshot taken {{snapshot}}.',
            'button_label' => 'Open the panel', 'button_url' => '{{panel_url}}'],

        // Alerts
        'server_unreachable' => ['group' => 'alerts', 'name' => 'Server unreachable', 'vars' => ['server', 'since'],
            'subject' => '{{server}} is unreachable', 'preview' => 'No heartbeat since {{since}}.',
            'heading' => 'Server unreachable', 'body' => 'We have not heard from {{server}} since {{since}}. Apps on it may be down.',
            'button_label' => 'Open the server', 'button_url' => '{{panel_url}}/servers'],
        'alert' => ['group' => 'alerts', 'name' => 'Alert', 'vars' => ['title', 'message', 'severity'],
            'subject' => '[{{severity}}] {{title}}', 'preview' => '{{message}}',
            'heading' => '{{title}}', 'body' => '{{message}}',
            'button_label' => 'Open monitoring', 'button_url' => '{{panel_url}}/monitoring'],
        'wordpress_updates' => ['group' => 'alerts', 'name' => 'WordPress updates', 'vars' => ['site', 'count', 'security'],
            'subject' => '{{count}} WordPress updates for {{site}}', 'preview' => '{{security}} fix known vulnerabilities.',
            'heading' => 'Updates waiting', 'body' => '{{site}} has {{count}} updates waiting; {{security}} fix known vulnerabilities. Updates take a restore point first and roll back if the site breaks.',
            'button_label' => 'Review updates', 'button_url' => '{{panel_url}}/wordpress'],
        'malware_found' => ['group' => 'alerts', 'name' => 'Malware found', 'vars' => ['site', 'path', 'signature', 'action'],
            'subject' => 'Malware found on {{site}}', 'preview' => '{{signature}}',
            'heading' => 'Malware found', 'body' => "A scan found an infected file on {{site}}. It was {{action}}.",
            'button_label' => 'Review the threat', 'button_url' => '{{panel_url}}/antivirus', 'details' => ['File' => '{{path}}', 'Signature' => '{{signature}}', 'Action' => '{{action}}']],

        // Billing
        'invoice' => ['group' => 'billing', 'name' => 'Invoice', 'vars' => ['number', 'amount', 'period', 'invoice_url'],
            'subject' => 'Invoice {{number}} for {{amount}}', 'preview' => 'For {{period}}.',
            'heading' => 'Your invoice', 'body' => 'Your invoice for {{period}} is ready.',
            'button_label' => 'Download the PDF', 'button_url' => '{{invoice_url}}', 'details' => ['Invoice' => '{{number}}', 'Period' => '{{period}}', 'Amount' => '{{amount}}']],
        'payment_failed' => ['group' => 'billing', 'name' => 'Payment failed', 'vars' => ['amount', 'reason', 'retry_date'],
            'subject' => 'Payment of {{amount}} failed', 'preview' => 'We will try again on {{retry_date}}.',
            'heading' => 'Payment failed', 'body' => "We could not take {{amount}}: {{reason}}.\n\nWe will try again on {{retry_date}}. Update your payment method to avoid interruption.",
            'button_label' => 'Update payment method', 'button_url' => '{{panel_url}}/billing'],
        'plan_limit' => ['group' => 'billing', 'name' => 'Plan limit reached', 'vars' => ['resource', 'used', 'limit', 'plan'],
            'subject' => 'You reached the {{resource}} limit of your plan', 'preview' => '{{used}} of {{limit}} used.',
            'heading' => 'Plan limit reached', 'body' => 'You are using {{used}} of {{limit}} {{resource}} on the {{plan}} plan. Upgrade to add more.',
            'button_label' => 'Compare plans', 'button_url' => '{{panel_url}}/billing'],
    ],
];
