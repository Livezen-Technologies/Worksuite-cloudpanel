<?php

/** Backups (BAK-001..006, UBAK-002). */
return [
    // Root of local restic repositories on the target server (one repository per org + policy).
    'local_root' => env('BACKUP_LOCAL_ROOT', '/var/lib/dockermgr/backups'),
    // BAK-004 key escrow: repository passwords are also stored encrypted with this separate key
    // (base64, 32 bytes) so backups stay recoverable if APP_KEY is lost. Keep it offline / in a vault.
    'recovery_key' => env('BACKUP_RECOVERY_KEY'),
    // restic check --read-data-subset after each run.
    'check_subset' => env('BACKUP_CHECK_SUBSET', '5%'),
    // Scheduled restore tests (BAK-005) for app backups without a policy.
    'restore_test_schedule' => env('BACKUP_RESTORE_TEST_SCHEDULE', '0 4 * * 0'),
    // Exports (download, UBAK-002).
    'export_disk' => env('BACKUP_EXPORT_DISK', 'local'),        // where local-destination exports land (Laravel disk)
    'export_ttl_hours' => (int) env('BACKUP_EXPORT_TTL_HOURS', 24),
    'download_ttl_minutes' => (int) env('BACKUP_DOWNLOAD_TTL_MINUTES', 15),
    // Base URL the agent uses to reach the API for uploads (defaults to PANEL_URL).
    'agent_api_url' => env('AGENT_API_URL', env('PANEL_URL', env('APP_URL', 'http://localhost'))),
];
