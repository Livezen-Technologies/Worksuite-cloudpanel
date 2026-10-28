<?php

/*
 * Role / permission matrix (SRS 3.1 platform roles, 3.2 customer roles).
 * Grant value: 'all' | 'own_org' | 'assigned' | 'read', optionally suffixed
 * with '+approval' when the action needs a second approver (subject to the
 * security.require_approval_destructive setting).
 */
return [
    'permissions' => [
        'resources.view' => ['View resources', 'General'],
        'servers.view' => ['View servers & agents', 'Servers'],
        'servers.manage' => ['Manage servers & agents', 'Servers'],
        'deploy' => ['Deploy apps', 'Applications'],
        'containers.operate' => ['Start/stop containers', 'Containers'],
        'terminal' => ['Open terminal', 'Containers'],
        'production.delete' => ['Delete production resources', 'Safety'],
        'network_storage.manage' => ['Manage networks & storage', 'Infrastructure'],
        'billing.manage' => ['Billing', 'Billing'],
        'logs.view' => ['Logs', 'Logs & audit'],
        'audit.view' => ['Audit log', 'Logs & audit'],
        'users.manage' => ['Manage users & roles', 'Administration'],
        'settings.manage' => ['Manage settings & integrations', 'Administration'],
        'approvals.decide' => ['Approve requests', 'Administration'],
        'app.manage' => ['Customer panel: host and manage apps, domains, backups', 'Customer panel'],
        'app.billing' => ['Customer panel: billing', 'Customer panel'],
        'app.team' => ['Customer panel: team & tokens', 'Customer panel'],
    ],

    'roles' => [
        'super_admin' => ['label' => 'Super Admin', 'scope' => 'platform', 'grants' => '*'],
        'infra_admin' => ['label' => 'Infrastructure Admin', 'scope' => 'platform', 'grants' => [
            'resources.view' => 'all', 'servers.view' => 'all', 'servers.manage' => 'all', 'deploy' => 'all',
            'containers.operate' => 'all', 'terminal' => 'all+approval', 'network_storage.manage' => 'all',
            'logs.view' => 'all', 'audit.view' => 'all', 'approvals.decide' => 'all',
        ]],
        'owner' => ['label' => 'Organisation Owner', 'scope' => 'both', 'grants' => [
            'resources.view' => 'own_org', 'servers.view' => 'own_org', 'servers.manage' => 'own_org', 'deploy' => 'own_org',
            'containers.operate' => 'own_org', 'terminal' => 'own_org+approval', 'production.delete' => 'own_org+approval',
            'network_storage.manage' => 'own_org', 'billing.manage' => 'own_org', 'logs.view' => 'own_org', 'audit.view' => 'own_org',
            'users.manage' => 'own_org', 'settings.manage' => 'own_org', 'approvals.decide' => 'own_org',
            'app.manage' => 'own_org', 'app.billing' => 'own_org', 'app.team' => 'own_org',
        ]],
        'devops' => ['label' => 'DevOps Engineer', 'scope' => 'platform', 'grants' => [
            'resources.view' => 'all', 'servers.view' => 'all', 'servers.manage' => 'all', 'deploy' => 'all',
            'containers.operate' => 'all', 'terminal' => 'all+approval', 'production.delete' => 'all+approval',
            'logs.view' => 'all', 'audit.view' => 'all', 'approvals.decide' => 'all',
        ]],
        'developer' => ['label' => 'Developer', 'scope' => 'both', 'grants' => [
            'resources.view' => 'assigned', 'deploy' => 'assigned', 'containers.operate' => 'assigned', 'logs.view' => 'assigned',
            'app.manage' => 'own_org',
        ]],
        'support' => ['label' => 'Support Engineer', 'scope' => 'platform', 'grants' => [
            'resources.view' => 'all', 'servers.view' => 'all', 'deploy' => 'all', 'terminal' => 'all+approval',
            'logs.view' => 'all', 'audit.view' => 'all',
        ]],
        'billing' => ['label' => 'Billing Manager', 'scope' => 'both', 'grants' => [
            'billing.manage' => 'all', 'logs.view' => 'read', 'audit.view' => 'read', 'app.billing' => 'own_org',
        ]],
        'viewer' => ['label' => 'Viewer / Auditor', 'scope' => 'platform', 'grants' => [
            'resources.view' => 'read', 'servers.view' => 'read', 'logs.view' => 'read', 'audit.view' => 'read',
        ]],
    ],

    'customer_roles' => ['owner', 'developer', 'billing'],
];
