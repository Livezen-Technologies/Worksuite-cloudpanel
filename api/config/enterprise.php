<?php

/*
| Enterprise provisioning (docs/ENTERPRISE_API.md): Cloud Admin
| (account.worksuitecloud.com/admin) deploys a dedicated WorkSuite Server for an
| enterprise customer through /api/v1/enterprise/deployments, authenticated with a
| panel API token that has the `enterprise.deploy` ability
| (`php artisan enterprise:service-account`).
|
| Everything about the WorkSuite Server image lives here as configuration, so a
| change in the image (part A of the enterprise contract) is a config change,
| not a code change.
*/
return [
    // The image, without a tag; the deployment's `version` is the tag: <VERSION>-<build> (dash) or `latest`
    // (LHCP docker/enterprise/build.sh). No registry: the image is a `build.sh --save` tar.gz that is
    // `docker load`ed on the node, so provisioning waits until the node reports the image.
    'image' => env('ENTERPRISE_IMAGE', 'worksuite-server'),
    'pull' => env('ENTERPRISE_IMAGE_PULL', 'never'),
    // Set false where a registry serves the image and the deploy may pull it itself.
    'require_loaded_image' => (bool) env('ENTERPRISE_REQUIRE_LOADED_IMAGE', true),

    // HTTP inside the web container, and its health path.
    'port' => (int) env('ENTERPRISE_PORT', 8080),
    'health_path' => env('ENTERPRISE_HEALTH_PATH', '/api/health'),

    // Container roles of the same image (worksuite-entrypoint <role>).
    'app_cmd' => ['web'],
    'worker_cmd' => ['cron'],

    // Headless bootstrap inside the web container: the JSON goes to stdin, the result is
    // the JSON on the LAST stdout line. Exit codes: 0 ok, 2 invalid input, 3 already
    // bootstrapped, 4 failed, 5 set up but the licence failed (retry `license:activate`).
    'bootstrap_command' => 'worksuite-entrypoint spark enterprise:bootstrap --json -',
    'status_command' => 'worksuite-entrypoint spark enterprise:status --json',
    // Re-reads the licence from the licensing server (Cloud Admin calls it after an issue/extend/suspend).
    'licence_refresh_command' => env('ENTERPRISE_LICENCE_REFRESH_COMMAND', 'worksuite-entrypoint spark license:refresh'),
    // Capabilities the image's root entrypoint needs back from the agent's cap_drop ALL: it creates and
    // chowns directories in the uid-33 writable volume, then drops to www-data (same set as DatabaseService).
    'cap_add' => ['CHOWN', 'DAC_OVERRIDE', 'FOWNER', 'SETGID', 'SETUID'],
    // GET /enterprise/versions: when the node inventory is older than this (s), the node's images
    // are listed directly through the agent (`docker images`, host-mode cron.exec) and cached.
    'images_stale_after' => 300,
    'images_command' => "docker images --format '{{.Repository}}:{{.Tag}}'",
    'bootstrap_exit_codes' => ['ok' => [0, 3], 'licence_failed' => [5]],
    // Numeric, non-root user the commands run as inside the container (the agent refuses root).
    'exec_user' => env('ENTERPRISE_EXEC_USER', '33:33'),
    'exec_timeout' => 600,
    'workdir' => env('ENTERPRISE_WORKDIR', '/var/www/html'),
    // How often an active deployment's `enterprise:status` is refreshed (minutes).
    'status_every_minutes' => 15,

    // Persistent named volumes shared by web and cron: suffix => mount path.
    // writable holds the encryption key: it must persist across redeploys.
    'volumes' => [
        'writable' => '/var/www/html/writable',
        'uploads' => '/var/www/html/public/uploads',
    ],

    // MariaDB and Redis run as managed databases (stock images, config/platform.php database_templates).
    'database' => ['engine' => 'mariadb', 'version' => '10.11', 'name' => 'worksuite', 'user' => 'root', 'port' => 3306],
    'redis' => ['engine' => 'redis', 'version' => '7-alpine', 'port' => 6379],

    // Environment variable names the image reads (left: what the panel provides).
    'env' => [
        'base_url' => 'APP_URL',
        'db_host' => 'DB_HOST',
        'db_port' => 'DB_PORT',
        'db_name' => 'DB_NAME',
        'db_user' => 'DB_USER',
        'db_password' => 'DB_PASSWORD',
        'redis_host' => 'REDIS_HOST',
        'redis_port' => 'REDIS_PORT',
        'redis_password' => 'REDIS_PASSWORD',
        'fpm_max_children' => 'FPM_MAX_CHILDREN',
        'deployment_id' => 'WORKSUITE_ENTERPRISE_ID',
        // The panel's public notice endpoint for this app (in-app scheduled-maintenance banner).
        'maintenance_notice_url' => 'WS_MAINTENANCE_NOTICE_URL',
    ],

    // Part F settings tab: the variable the image reads its timezone from (editable), and keys that
    // stay read-only on top of the ones above (the panel provides them).
    'timezone_env' => env('ENTERPRISE_TIMEZONE_ENV', 'TZ'),
    'reserved_env' => ['APP_KEY', 'ENCRYPTION_KEY', 'CI_ENVIRONMENT', 'LICENSE_KEY'],

    // Public address: <slug>.<domain>.
    'domain' => env('ENTERPRISE_DOMAIN', 'worksuite.lk'),
    // Slugs never handed out (they are our own hosts).
    'reserved_slugs' => ['www', 'account', 'admin', 'api', 'app', 'panel', 'mail', 'download', 'developer', 'docs', 'status', 'cms', 'desk', 'demo', 'staging'],

    // Nodes carrying this tag take enterprise deployments; when none does, any app node does.
    'node_tag' => env('ENTERPRISE_NODE_TAG', 'enterprise'),

    // Sizes: RAM (MB) / CPU per container, PHP-FPM children and the disk the deployment is expected to use.
    'sizes' => [
        'small' => ['label' => 'Small', 'app_ram_mb' => 1024, 'app_cpus' => 1.0, 'worker_ram_mb' => 512, 'worker_cpus' => 0.5, 'db_ram_mb' => 1024, 'redis_ram_mb' => 256, 'fpm_max_children' => 10, 'disk_gb' => 20],
        'medium' => ['label' => 'Medium', 'app_ram_mb' => 2048, 'app_cpus' => 2.0, 'worker_ram_mb' => 1024, 'worker_cpus' => 1.0, 'db_ram_mb' => 2048, 'redis_ram_mb' => 512, 'fpm_max_children' => 24, 'disk_gb' => 50],
        'large' => ['label' => 'Large', 'app_ram_mb' => 4096, 'app_cpus' => 4.0, 'worker_ram_mb' => 2048, 'worker_cpus' => 2.0, 'db_ram_mb' => 4096, 'redis_ram_mb' => 1024, 'fpm_max_children' => 48, 'disk_gb' => 100],
    ],

    // Custom limits (resize): bounds for the web container.
    'custom_limits' => ['cpus' => [0.5, 16], 'ram_mb' => [512, 65536], 'disk_gb' => [10, 2000], 'mb_per_fpm_child' => 80],

    // The staff organisation the service account belongs to (Livezen Technologies).
    'organization_id' => env('ENTERPRISE_ORGANIZATION_ID', '01a1810a-f02c-737b-a05e-381aa9e9f667'),

    // Outbound webhooks: attempts before a delivery is parked as failed, and the backoff base (s).
    'webhook_max_attempts' => 12,
    'webhook_backoff' => 30,
];
