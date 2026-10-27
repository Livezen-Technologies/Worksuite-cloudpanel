<?php

return [
    // Free customer subdomains are <app>.<domain> (UHOST-005).
    'domain' => env('PLATFORM_DOMAIN', 'livezen.app'),
    'ingress_ip' => env('PLATFORM_INGRESS_IP', '203.0.113.10'),
    'panel_url' => env('PANEL_URL', env('APP_URL', 'http://localhost')),
    'terminal_ws_url' => env('TERMINAL_WS_URL', 'wss://localhost/ws/terminal'),

    'offline_after_seconds' => 90,
    'heartbeat_interval' => 15,
    'enrollment_ttl_minutes' => 15,
    'terminal_ttl_minutes' => 15,
    'approval_ttl_hours' => 24,
    'idempotency_ttl_hours' => 24,
    'deleted_app_retention_days' => 7,
    'release_history' => 20,
    'agent_binary_dir' => storage_path('app/agent'),
    'agent_install_script' => env('AGENT_INSTALL_SCRIPT', base_path('../agent/packaging/install.sh')),
    'agent_latest_version' => env('AGENT_LATEST_VERSION', '1.0.0'),

    // Driver selection; real drivers need credentials (see README).
    'drivers' => [
        'dns' => env('DNS_DRIVER', 'native'),            // native|fake
        'certificates' => env('CERT_DRIVER', 'fake'),   // fake
        'scanner' => env('SCANNER_DRIVER', 'fake'),     // fake
        'payments' => env('PAYMENT_DRIVER', 'fake'),    // fake
        'source' => env('SOURCE_VALIDATOR', 'http'),    // http|fake
    ],

    'default_settings' => [
        'general' => [
            'default_region' => 'eu-central',
            'audit_retention_days' => 365,
            'heartbeat_interval' => 15,
            'refresh_interval' => 10,
        ],
        'security' => [
            'require_mfa' => false,
            'require_approval_destructive' => true,
            'require_production_deploy_approval' => false,
            'secure_container_defaults' => true,
            'mandatory_backup_encryption' => true,
            'allow_host_network' => false,
            'block_critical_cves' => false,
        ],
    ],

    // SRS MON-004 default alert rules, seeded per organisation.
    'default_alert_rules' => [
        ['name' => 'CPU above 90% for 10 min', 'metric' => 'cpu', 'operator' => '>', 'threshold' => 90, 'duration_minutes' => 10, 'severity' => 'critical'],
        ['name' => 'RAM above 90% for 5 min', 'metric' => 'ram', 'operator' => '>', 'threshold' => 90, 'duration_minutes' => 5, 'severity' => 'critical'],
        ['name' => 'Disk above 85%', 'metric' => 'disk', 'operator' => '>', 'threshold' => 85, 'duration_minutes' => 0, 'severity' => 'warning'],
        ['name' => 'Agent heartbeat timeout', 'metric' => 'heartbeat', 'operator' => '>', 'threshold' => 90, 'duration_minutes' => 0, 'severity' => 'critical'],
        ['name' => 'Unhealthy container', 'metric' => 'container_unhealthy', 'operator' => '>', 'threshold' => 0, 'duration_minutes' => 0, 'severity' => 'warning'],
        ['name' => 'Backup failed', 'metric' => 'backup_failed', 'operator' => '>', 'threshold' => 0, 'duration_minutes' => 0, 'severity' => 'critical'],
        ['name' => 'SSL certificate expires in under 14 days', 'metric' => 'ssl_expiry', 'operator' => '<', 'threshold' => 14, 'duration_minutes' => 0, 'severity' => 'warning'],
    ],

    'plans' => [
        ['code' => 'small', 'name' => 'Small', 'vcpu' => 0.5, 'ram_mb' => 512, 'storage_gb' => 5, 'price_cents' => 500, 'sort' => 1,
            'limits' => ['apps' => 3, 'ram_mb' => 1536, 'storage_gb' => 15, 'bandwidth_gb' => 100, 'backups_per_day' => 2, 'backup_retention_days' => 7]],
        ['code' => 'standard', 'name' => 'Standard', 'vcpu' => 1, 'ram_mb' => 1024, 'storage_gb' => 10, 'price_cents' => 1200, 'sort' => 2,
            'limits' => ['apps' => 10, 'ram_mb' => 10240, 'storage_gb' => 100, 'bandwidth_gb' => 500, 'backups_per_day' => 5, 'backup_retention_days' => 14]],
        ['code' => 'pro', 'name' => 'Pro', 'vcpu' => 2, 'ram_mb' => 4096, 'storage_gb' => 40, 'price_cents' => 3500, 'sort' => 3,
            'limits' => ['apps' => 30, 'ram_mb' => 65536, 'storage_gb' => 1000, 'bandwidth_gb' => 2000, 'backups_per_day' => 24, 'backup_retention_days' => 30]],
    ],

    'database_templates' => [
        'postgres' => ['image' => 'postgres', 'versions' => ['16', '15'], 'port' => 5432, 'data' => '/var/lib/postgresql/data', 'password_env' => 'POSTGRES_PASSWORD', 'user' => '999:999'],
        'mysql' => ['image' => 'mysql', 'versions' => ['8.4', '8.0'], 'port' => 3306, 'data' => '/var/lib/mysql', 'password_env' => 'MYSQL_ROOT_PASSWORD', 'user' => '999:999'],
        'mariadb' => ['image' => 'mariadb', 'versions' => ['11', '10.11'], 'port' => 3306, 'data' => '/var/lib/mysql', 'password_env' => 'MARIADB_ROOT_PASSWORD', 'user' => '999:999'],
        'redis' => ['image' => 'redis', 'versions' => ['7'], 'port' => 6379, 'data' => '/data', 'password_env' => 'REDIS_PASSWORD', 'user' => '999:999'],
        'mongodb' => ['image' => 'mongo', 'versions' => ['7'], 'port' => 27017, 'data' => '/data/db', 'password_env' => 'MONGO_INITDB_ROOT_PASSWORD', 'user' => '999:999'],
    ],

    // One-click customer templates (UHOST source "template").
    'app_templates' => [
        'wordpress' => ['name' => 'WordPress', 'description' => 'Blog and website CMS', 'image' => 'wordpress:6', 'port' => 80, 'volume_path' => '/var/www/html', 'size' => 'standard', 'env' => [['key' => 'WORDPRESS_DB_HOST', 'value' => '', 'secret' => false]]],
        'n8n' => ['name' => 'n8n', 'description' => 'Workflow automation', 'image' => 'n8nio/n8n:1', 'port' => 5678, 'volume_path' => '/home/node/.n8n', 'size' => 'standard', 'env' => []],
        'ghost' => ['name' => 'Ghost', 'description' => 'Publishing platform', 'image' => 'ghost:5', 'port' => 2368, 'volume_path' => '/var/lib/ghost/content', 'size' => 'small', 'env' => []],
        'postgresql' => ['name' => 'PostgreSQL', 'description' => 'Relational database', 'image' => 'postgres:16', 'port' => 5432, 'volume_path' => '/var/lib/postgresql/data', 'size' => 'small', 'env' => [['key' => 'POSTGRES_PASSWORD', 'value' => '', 'secret' => true]]],
        'redis' => ['name' => 'Redis', 'description' => 'In-memory key-value store', 'image' => 'redis:7', 'port' => 6379, 'volume_path' => '/data', 'size' => 'small', 'env' => []],
        'uptime-kuma' => ['name' => 'Uptime Kuma', 'description' => 'Uptime monitoring', 'image' => 'louislam/uptime-kuma:1', 'port' => 3001, 'volume_path' => '/app/data', 'size' => 'small', 'env' => []],
    ],
];
