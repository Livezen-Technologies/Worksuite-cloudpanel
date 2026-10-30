<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Customer;
use App\Http\Controllers\HookController;
use App\Http\Controllers\JobController;
use Illuminate\Support\Facades\Route;

/*
| Business API, base /api/v1 (docs/API.md). Every authenticated route is
| organisation-scoped (tenant), permission-checked (perm), idempotent on POST
| and audited on mutation.
*/

$uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
Route::pattern('id', $uuid);

Route::middleware('throttle:auth')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register'])->name('auth.register');
    Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('auth/passkey/options', [\App\Http\Controllers\PasskeyController::class, 'loginOptions'])->name('auth.passkey-options');
    Route::post('auth/passkey/login', [\App\Http\Controllers\PasskeyController::class, 'login'])->name('auth.passkey-login');
});
Route::post('hooks/scheduled-jobs/{id}/{token}', [HookController::class, 'scheduledJob'])->middleware('throttle:api')->name('hooks.scheduled-job');
Route::get('backup-download/{id}', [\App\Http\Controllers\SignedFileController::class, 'backupDownload'])->middleware('signed')->name('backup.download');
Route::get('file-downloads/{id}', [\App\Http\Controllers\SignedFileController::class, 'fileDownload'])->middleware('signed')->name('files.transfer');
Route::get('invoices/{id}/pdf', [\App\Http\Controllers\SignedFileController::class, 'invoicePdf'])->middleware('signed')->name('invoice.pdf');
Route::post('webhooks/stripe', [\App\Http\Controllers\StripeWebhookController::class, 'handle'])->middleware('throttle:api')->name('webhooks.stripe');

Route::middleware(['auth:sanctum', 'tenant', 'throttle:api', 'idempotent', 'audit'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('me', [AuthController::class, 'me'])->name('me.show');
    Route::post('me/mfa/enable', [AuthController::class, 'mfaEnable'])->name('me.mfa-enable');
    Route::post('me/mfa/confirm', [AuthController::class, 'mfaConfirm'])->name('me.mfa-confirm');
    Route::post('me/mfa/disable', [AuthController::class, 'mfaDisable'])->name('me.mfa-disable');
    Route::get('me/passkeys', [\App\Http\Controllers\PasskeyController::class, 'index'])->name('me.passkeys.index');
    Route::post('me/passkeys/options', [\App\Http\Controllers\PasskeyController::class, 'registrationOptions'])->name('me.passkeys.options');
    Route::post('me/passkeys', [\App\Http\Controllers\PasskeyController::class, 'store'])->name('me.passkeys.store');
    Route::patch('me/passkeys/{id}', [\App\Http\Controllers\PasskeyController::class, 'update'])->name('me.passkeys.update');
    Route::delete('me/passkeys/{id}', [\App\Http\Controllers\PasskeyController::class, 'destroy'])->name('me.passkeys.destroy');

    Route::get('jobs', [JobController::class, 'index'])->name('jobs.index');
    Route::get('jobs/{id}', [JobController::class, 'show'])->name('jobs.show');
    Route::get('search', [Admin\DashboardController::class, 'search'])->name('search');

    // API tokens (own) and approvals (decisions are checked in the service).
    Route::get('api-tokens', [Admin\ApiTokenController::class, 'index'])->name('api-tokens.index');
    Route::post('api-tokens', [Admin\ApiTokenController::class, 'store'])->name('api-tokens.store');
    Route::delete('api-tokens/{id}', [Admin\ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');
    Route::get('approvals', [Admin\ApprovalController::class, 'index'])->name('approvals.index');
    Route::post('approvals/{id}/approve', [Admin\ApprovalController::class, 'approve'])->name('approvals.approve');
    Route::post('approvals/{id}/reject', [Admin\ApprovalController::class, 'reject'])->name('approvals.reject');

    $view = 'perm:resources.view|servers.view';

    Route::get('dashboard', [Admin\DashboardController::class, 'dashboard'])->middleware($view)->name('dashboard');
    Route::get('metrics', [Admin\DashboardController::class, 'metrics'])->middleware($view)->name('metrics');

    // Servers & agents
    Route::middleware('perm:servers.view')->group(function () {
        Route::get('servers', [Admin\ServerController::class, 'index'])->name('servers.index');
        Route::get('servers/{id}', [Admin\ServerController::class, 'show'])->name('servers.show');
        Route::get('servers/{id}/verification', [Admin\ServerController::class, 'verification'])->name('servers.verification');
        Route::get('servers/{id}/containers', [Admin\ServerController::class, 'containers'])->name('servers.containers');
    });
    Route::middleware('perm:servers.manage')->group(function () {
        Route::post('servers', [Admin\ServerController::class, 'store'])->name('servers.store');
        Route::patch('servers/{id}', [Admin\ServerController::class, 'update'])->name('servers.update');
        Route::post('servers/enrollment', [Admin\ServerController::class, 'enrollment'])->name('servers.enrollment');
        Route::post('servers/{id}/maintenance', [Admin\ServerController::class, 'maintenance'])->name('servers.maintenance');
        Route::post('servers/{id}/agent/{action}', [Admin\ServerController::class, 'agent'])->whereIn('action', ['restart', 'update'])->name('servers.agent');
    });
    Route::delete('servers/{id}', [Admin\ServerController::class, 'destroy'])->middleware('perm:servers.manage|production.delete')->name('servers.destroy');

    // Containers
    Route::middleware('perm:resources.view')->group(function () {
        Route::get('containers', [Admin\ContainerController::class, 'index'])->name('containers.index');
        Route::get('containers/{id}', [Admin\ContainerController::class, 'show'])->name('containers.show');
        Route::post('containers/prune-preview', [Admin\ContainerController::class, 'prunePreview'])->name('containers.prune-preview');
    });
    Route::get('containers/{id}/logs', [Admin\ContainerController::class, 'logs'])->middleware('perm:logs.view')->name('containers.logs');
    Route::post('containers', [Admin\ContainerController::class, 'store'])->middleware('perm:deploy|containers.operate')->name('containers.store');
    Route::middleware('perm:containers.operate|production.delete')->group(function () {
        Route::post('containers/{id}/actions', [Admin\ContainerController::class, 'action'])->name('containers.action');
        Route::post('containers/bulk-actions', [Admin\ContainerController::class, 'bulk'])->name('containers.bulk');
        Route::post('containers/prune', [Admin\ContainerController::class, 'prune'])->name('containers.prune');
    });
    Route::post('containers/{id}/terminal-sessions', [Admin\ContainerController::class, 'terminal'])->middleware('perm:terminal')->name('containers.terminal');
    Route::delete('terminal-sessions/{id}', [Admin\ContainerController::class, 'endTerminal'])->middleware('perm:terminal')->name('terminal-sessions.end');
    Route::get('terminal-sessions', [Admin\TerminalSessionController::class, 'index'])->middleware('perm:logs.view|terminal')->name('terminal-sessions.index');
    Route::get('terminal-sessions/{id}/recording', [Admin\TerminalSessionController::class, 'recording'])->middleware('perm:logs.view')->name('terminal-sessions.recording');

    // Applications & deployments
    Route::middleware('perm:resources.view')->group(function () {
        Route::get('applications', [Admin\ApplicationController::class, 'index'])->name('applications.index');
        Route::get('applications/{id}', [Admin\ApplicationController::class, 'show'])->name('applications.show');
        Route::get('applications/{id}/releases', [Admin\ApplicationController::class, 'releases'])->name('applications.releases');
        Route::get('deployments', [Admin\DeploymentController::class, 'index'])->name('deployments.index');
        Route::get('deployments/{id}', [Admin\DeploymentController::class, 'show'])->name('deployments.show');
    });
    Route::middleware('perm:deploy')->group(function () {
        Route::post('applications', [Admin\ApplicationController::class, 'store'])->name('applications.store');
        Route::patch('applications/{id}', [Admin\ApplicationController::class, 'update'])->name('applications.update');
        Route::post('applications/{id}/rollback', [Admin\ApplicationController::class, 'rollback'])->name('applications.rollback');
        Route::post('deployments', [Admin\DeploymentController::class, 'store'])->name('deployments.store');
    });
    Route::delete('applications/{id}', [Admin\ApplicationController::class, 'destroy'])->middleware('perm:deploy|production.delete')->name('applications.destroy');
    Route::post('deployments/{id}/approve', [Admin\DeploymentController::class, 'approve'])->middleware('perm:approvals.decide')->name('deployments.approve');

    // Compose
    Route::middleware('perm:resources.view')->group(function () {
        Route::get('compose-projects', [Admin\ComposeProjectController::class, 'index'])->name('compose-projects.index');
        Route::get('compose-projects/{id}', [Admin\ComposeProjectController::class, 'show'])->name('compose-projects.show');
        Route::get('compose-projects/{id}/revisions', [Admin\ComposeProjectController::class, 'revisions'])->name('compose-projects.revisions');
        Route::post('compose-projects/{id}/validate', [Admin\ComposeProjectController::class, 'validateYaml'])->name('compose-projects.validate');
        Route::post('compose-projects/{id}/diff', [Admin\ComposeProjectController::class, 'diff'])->name('compose-projects.diff');
    });
    Route::middleware('perm:deploy')->group(function () {
        Route::post('compose-projects', [Admin\ComposeProjectController::class, 'store'])->name('compose-projects.store');
        Route::patch('compose-projects/{id}', [Admin\ComposeProjectController::class, 'update'])->name('compose-projects.update');
        Route::put('compose-projects/{id}', [Admin\ComposeProjectController::class, 'update']);
        Route::delete('compose-projects/{id}', [Admin\ComposeProjectController::class, 'destroy'])->name('compose-projects.destroy');
        Route::post('compose-projects/{id}/deploy', [Admin\ComposeProjectController::class, 'deploy'])->name('compose-projects.deploy');
        Route::post('compose-projects/{id}/actions', [Admin\ComposeProjectController::class, 'action'])->name('compose-projects.action');
        Route::post('compose-projects/{id}/rollback', [Admin\ComposeProjectController::class, 'rollback'])->name('compose-projects.rollback');
    });

    // Images & registries
    Route::get('images', [Admin\ImageController::class, 'index'])->middleware('perm:resources.view')->name('images.index');
    Route::post('images/prune-preview', [Admin\ImageController::class, 'prunePreview'])->middleware('perm:resources.view')->name('images.prune-preview');
    Route::middleware('perm:deploy|containers.operate')->group(function () {
        Route::post('images/{id}/scan', [Admin\ImageController::class, 'scan'])->name('images.scan');
        Route::post('images/prune', [Admin\ImageController::class, 'prune'])->name('images.prune');
        Route::post('images/pull', [Admin\ImageController::class, 'pull'])->name('images.pull');
    });
    $crud = function (string $uri, string $controller, string $viewPerm, string $managePerm) {
        Route::get($uri, [$controller, 'index'])->middleware("perm:{$viewPerm}")->name("{$uri}.index");
        Route::get("{$uri}/{id}", [$controller, 'show'])->middleware("perm:{$viewPerm}")->name("{$uri}.show");
        Route::post($uri, [$controller, 'store'])->middleware("perm:{$managePerm}")->name("{$uri}.store");
        Route::match(['put', 'patch'], "{$uri}/{id}", [$controller, 'update'])->middleware("perm:{$managePerm}")->name("{$uri}.update");
        Route::delete("{$uri}/{id}", [$controller, 'destroy'])->middleware("perm:{$managePerm}")->name("{$uri}.destroy");
    };
    $crud('registries', Admin\RegistryController::class, 'resources.view', 'deploy');

    // Networks, volumes, storage
    Route::post('networks/check', [Admin\NetworkController::class, 'check'])->middleware('perm:resources.view')->name('networks.check');
    $crud('networks', Admin\NetworkController::class, 'resources.view', 'network_storage.manage');
    Route::get('volumes', [Admin\VolumeController::class, 'index'])->middleware('perm:resources.view')->name('volumes.index');
    Route::get('volumes/{id}', [Admin\VolumeController::class, 'show'])->middleware('perm:resources.view')->name('volumes.show');
    Route::post('volumes', [Admin\VolumeController::class, 'store'])->middleware('perm:network_storage.manage')->name('volumes.store');
    Route::match(['put', 'patch'], 'volumes/{id}', [Admin\VolumeController::class, 'update'])->middleware('perm:network_storage.manage')->name('volumes.update');
    Route::delete('volumes/{id}', [Admin\VolumeController::class, 'destroy'])->middleware('perm:network_storage.manage|production.delete')->name('volumes.destroy');
    // File manager (FM): every call returns a job whose result holds the listing or file
    Route::get('volumes/{id}/files', [Admin\FileController::class, 'index'])->middleware('perm:resources.view')->name('files.index');
    Route::get('volumes/{id}/files/content', [Admin\FileController::class, 'show'])->middleware('perm:deploy|network_storage.manage')->name('files.show');
    Route::put('volumes/{id}/files/content', [Admin\FileController::class, 'write'])->middleware('perm:deploy|network_storage.manage')->name('files.write');
    Route::post('volumes/{id}/files/folders', [Admin\FileController::class, 'mkdir'])->middleware('perm:deploy|network_storage.manage')->name('files.mkdir');
    Route::post('volumes/{id}/files/rename', [Admin\FileController::class, 'rename'])->middleware('perm:deploy|network_storage.manage')->name('files.rename');
    Route::post('volumes/{id}/files/upload', [Admin\FileController::class, 'upload'])->middleware('perm:deploy|network_storage.manage')->name('files.upload');
    Route::post('volumes/{id}/files/download', [Admin\FileController::class, 'download'])->middleware('perm:deploy|network_storage.manage')->name('files.download');
    Route::post('volumes/{id}/files/copy', [Admin\FileController::class, 'copy'])->middleware('perm:deploy|network_storage.manage')->name('files.copy');
    Route::post('volumes/{id}/files/move', [Admin\FileController::class, 'move'])->middleware('perm:deploy|network_storage.manage')->name('files.move');
    Route::post('volumes/{id}/files/compress', [Admin\FileController::class, 'compress'])->middleware('perm:deploy|network_storage.manage')->name('files.compress');
    Route::post('volumes/{id}/files/extract', [Admin\FileController::class, 'extract'])->middleware('perm:deploy|network_storage.manage')->name('files.extract');
    Route::post('volumes/{id}/files/trash', [Admin\FileController::class, 'trash'])->middleware('perm:deploy|network_storage.manage')->name('files.trash');
    Route::get('volumes/{id}/files/trash', [Admin\FileController::class, 'trashIndex'])->middleware('perm:deploy|network_storage.manage')->name('files.trash-index');
    Route::post('volumes/{id}/files/trash/restore', [Admin\FileController::class, 'restore'])->middleware('perm:deploy|network_storage.manage')->name('files.restore');
    Route::delete('volumes/{id}/files/trash', [Admin\FileController::class, 'purge'])->middleware('perm:deploy|network_storage.manage')->name('files.purge');
    Route::post('volumes/{id}/files/chmod', [Admin\FileController::class, 'chmod'])->middleware('perm:deploy|network_storage.manage')->name('files.chmod');
    Route::post('volumes/{id}/files/chown', [Admin\FileController::class, 'chown'])->middleware('perm:network_storage.manage')->name('files.chown');
    Route::get('volumes/{id}/files/search', [Admin\FileController::class, 'search'])->middleware('perm:resources.view')->name('files.search');
    Route::post('volumes/{id}/files/preview', [Admin\FileController::class, 'preview'])->middleware('perm:deploy|network_storage.manage')->name('files.preview');
    Route::post('volumes/{id}/files/scan', [Admin\FileController::class, 'scan'])->middleware('perm:deploy|network_storage.manage')->name('files.scan');
    Route::get('volumes/{id}/files/history', [Admin\FileController::class, 'history'])->middleware('perm:deploy|network_storage.manage')->name('files.history');
    Route::get('volumes/{id}/files/history/version', [Admin\FileController::class, 'historyShow'])->middleware('perm:deploy|network_storage.manage')->name('files.history-show');
    Route::post('volumes/{id}/files/history/restore', [Admin\FileController::class, 'historyRestore'])->middleware('perm:deploy|network_storage.manage')->name('files.history-restore');
    Route::delete('volumes/{id}/files', [Admin\FileController::class, 'destroy'])->middleware('perm:deploy|network_storage.manage')->name('files.destroy');
    Route::get('storage-pools', [Admin\VolumeController::class, 'pools'])->middleware('perm:resources.view')->name('storage-pools.index');

    // Domains & databases
    $crud('domains', Admin\DomainController::class, 'resources.view', 'deploy');
    Route::post('domains/{id}/verify', [Admin\DomainController::class, 'verify'])->middleware('perm:deploy')->name('domains.verify');
    Route::post('domains/{id}/certificate', [Admin\DomainController::class, 'certificate'])->middleware('perm:deploy')->name('domains.certificate');
    $crud('databases', Admin\DatabaseController::class, 'resources.view', 'deploy');
    Route::post('databases/{id}/backup', [Admin\DatabaseController::class, 'backup'])->middleware('perm:deploy')->name('databases.backup');
    Route::post('databases/{id}/restore', [Admin\DatabaseController::class, 'restore'])->middleware('perm:deploy')->name('databases.restore');

    // Backups
    $crud('backup-policies', Admin\BackupPolicyController::class, 'resources.view', 'deploy|network_storage.manage');
    Route::get('backup-jobs', [Admin\BackupJobController::class, 'index'])->middleware('perm:resources.view')->name('backup-jobs.index');
    Route::get('backup-artifacts', [Admin\BackupJobController::class, 'artifacts'])->middleware('perm:resources.view')->name('backup-artifacts.index');
    Route::get('backup-artifacts/{id}/download', [Admin\BackupJobController::class, 'download'])->middleware('perm:deploy|network_storage.manage')->name('backup-artifacts.download');
    Route::post('backup-artifacts/{id}/restore-test', [Admin\BackupJobController::class, 'restoreTest'])->middleware('perm:deploy|network_storage.manage')->name('backup-artifacts.restore-test');
    Route::post('backup-jobs', [Admin\BackupJobController::class, 'store'])->middleware('perm:deploy|network_storage.manage')->name('backup-jobs.store');
    Route::post('restore-jobs', [Admin\BackupJobController::class, 'restore'])->middleware('perm:deploy|network_storage.manage')->name('restore-jobs.store');

    // Monitoring & automation
    $crud('alert-rules', Admin\AlertRuleController::class, 'resources.view|servers.view', 'settings.manage|servers.manage');
    Route::get('alerts', [Admin\AlertController::class, 'index'])->middleware($view)->name('alerts.index');
    Route::post('alerts/{id}/ack', [Admin\AlertController::class, 'ack'])->middleware('perm:servers.manage|deploy|containers.operate')->name('alerts.ack');
    $crud('scheduled-jobs', Admin\ScheduledJobController::class, 'resources.view|servers.view', 'servers.manage');
    Route::post('scheduled-jobs/{id}/run', [Admin\ScheduledJobController::class, 'run'])->middleware('perm:servers.manage')->name('scheduled-jobs.run');
    $crud('maintenance-windows', Admin\MaintenanceWindowController::class, 'resources.view|servers.view', 'servers.manage');

    // Uptime reports and downtime history (MON-00x).
    Route::get('uptime', [Admin\UptimeController::class, 'index'])->middleware($view)->name('uptime.index');
    Route::get('uptime/{id}', [Admin\UptimeController::class, 'show'])->middleware($view)->name('uptime.show');

    // Firewall (FW-001). Domain management governs it, as it changes the proxy.
    $crud('firewall-rules', Admin\FirewallRuleController::class, 'resources.view|servers.view', 'deploy|settings.manage');
    // DNS zones (SRS 4.18)
    Route::get('dns/providers', [Admin\DnsController::class, 'providers'])->middleware('perm:resources.view|servers.view')->name('dns.providers');
    Route::post('dns/providers', [Admin\DnsController::class, 'connect'])->middleware('perm:deploy|settings.manage')->name('dns.providers.store');
    Route::delete('dns/providers/{id}', [Admin\DnsController::class, 'disconnect'])->middleware('perm:deploy|settings.manage')->name('dns.providers.destroy');
    Route::get('dns/zones', [Admin\DnsController::class, 'index'])->middleware('perm:resources.view|servers.view')->name('dns.zones.index');
    Route::post('dns/zones', [Admin\DnsController::class, 'store'])->middleware('perm:deploy|settings.manage')->name('dns.zones.store');
    Route::get('dns/zones/{id}', [Admin\DnsController::class, 'show'])->middleware('perm:resources.view|servers.view')->name('dns.zones.show');
    Route::delete('dns/zones/{id}', [Admin\DnsController::class, 'destroy'])->middleware('perm:deploy|settings.manage')->name('dns.zones.destroy');
    Route::post('dns/zones/{id}/check-nameservers', [Admin\DnsController::class, 'checkNameservers'])->middleware('perm:resources.view|servers.view')->name('dns.zones.check-ns');
    Route::post('dns/zones/{id}/sync', [Admin\DnsController::class, 'sync'])->middleware('perm:deploy|settings.manage')->name('dns.zones.sync');
    Route::get('dns/zones/{id}/dnssec', [Admin\DnsController::class, 'dnssec'])->middleware('perm:resources.view|servers.view')->name('dns.zones.dnssec');
    Route::match(['put', 'patch'], 'dns/zones/{id}/dnssec', [Admin\DnsController::class, 'setDnssec'])->middleware('perm:deploy|settings.manage')->name('dns.zones.dnssec.update');
    Route::get('dns/zones/{id}/settings', [Admin\DnsController::class, 'settings'])->middleware('perm:resources.view|servers.view')->name('dns.zones.settings');
    Route::match(['put', 'patch'], 'dns/zones/{id}/settings', [Admin\DnsController::class, 'updateSettings'])->middleware('perm:deploy|settings.manage')->name('dns.zones.settings.update');
    Route::get('dns/zones/{id}/export', [Admin\DnsController::class, 'export'])->middleware('perm:resources.view|servers.view')->name('dns.zones.export');
    Route::post('dns/zones/{id}/import', [Admin\DnsController::class, 'import'])->middleware('perm:deploy|settings.manage')->name('dns.zones.import');
    Route::post('dns/zones/{id}/records', [Admin\DnsController::class, 'storeRecord'])->middleware('perm:deploy|settings.manage')->name('dns.records.store');
    Route::match(['put', 'patch'], 'dns/zones/{id}/records/{recordId}', [Admin\DnsController::class, 'updateRecord'])->middleware('perm:deploy|settings.manage')->name('dns.records.update');
    Route::delete('dns/zones/{id}/records/{recordId}', [Admin\DnsController::class, 'destroyRecord'])->middleware('perm:deploy|settings.manage')->name('dns.records.destroy');
    // Antivirus (SRS 4.25)
    Route::get('antivirus', [Admin\AntivirusController::class, 'summary'])->middleware('perm:resources.view|servers.view')->name('antivirus.summary');
    Route::get('antivirus/threats', [Admin\AntivirusController::class, 'index'])->middleware('perm:resources.view|servers.view')->name('antivirus.threats');
    Route::post('antivirus/scan', [Admin\AntivirusController::class, 'scan'])->middleware('perm:deploy|network_storage.manage')->name('antivirus.scan');
    Route::post('antivirus/threats/{id}/{action}', [Admin\AntivirusController::class, 'action'])->middleware('perm:deploy|network_storage.manage')->name('antivirus.action');
    Route::get('firewall', [Admin\FirewallController::class, 'show'])->middleware('perm:resources.view|servers.view')->name('firewall.show');
    Route::get('firewall/events', [Admin\FirewallController::class, 'events'])->middleware('perm:resources.view|servers.view')->name('firewall.events');
    Route::match(['put', 'patch'], 'firewall/countries', [Admin\FirewallController::class, 'countries'])->middleware('perm:deploy|settings.manage')->name('firewall.countries');
    Route::post('firewall/paths', [Admin\FirewallController::class, 'storePath'])->middleware('perm:deploy|settings.manage')->name('firewall.paths.store');
    Route::match(['put', 'patch'], 'firewall/paths/{id}', [Admin\FirewallController::class, 'updatePath'])->middleware('perm:deploy|settings.manage')->name('firewall.paths.update');
    Route::delete('firewall/paths/{id}', [Admin\FirewallController::class, 'destroyPath'])->middleware('perm:deploy|settings.manage')->name('firewall.paths.destroy');

    // Users, roles, settings, integrations, audit
    Route::middleware('perm:users.manage')->group(function () {
        Route::get('users', [Admin\UserController::class, 'index'])->name('users.index');
        Route::post('users/invite', [Admin\UserController::class, 'store'])->name('users.invite');
        Route::patch('users/{id}', [Admin\UserController::class, 'update'])->name('users.update');
        Route::delete('users/{id}', [Admin\UserController::class, 'destroy'])->name('users.destroy');
    });
    // Hosting packages (plans) and hosting customers (organisations).
    Route::get('plans', [Admin\PlanController::class, 'index'])->middleware('perm:billing.manage|billing')->name('plans.index');
    Route::get('plans/{id}', [Admin\PlanController::class, 'show'])->middleware('perm:billing.manage|billing')->name('plans.show');
    Route::post('plans', [Admin\PlanController::class, 'store'])->middleware('perm:billing.manage')->name('plans.store');
    Route::patch('plans/{id}', [Admin\PlanController::class, 'update'])->middleware('perm:billing.manage')->name('plans.update');
    Route::delete('plans/{id}', [Admin\PlanController::class, 'destroy'])->middleware('perm:billing.manage')->name('plans.destroy');

    Route::get('customers', [Admin\CustomerController::class, 'index'])->middleware('perm:users.manage|billing.manage')->name('customers.index');
    Route::post('customers', [Admin\CustomerController::class, 'store'])->middleware('perm:users.manage')->name('customers.store');
    Route::patch('customers/{id}', [Admin\CustomerController::class, 'update'])->middleware('perm:users.manage')->name('customers.update');
    Route::post('customers/{id}/plan', [Admin\CustomerController::class, 'setPlan'])->middleware('perm:billing.manage')->name('customers.plan');

    Route::get('roles', [Admin\RoleController::class, 'index'])->middleware('perm:users.manage|audit.view')->name('roles.index');
    Route::get('roles/{name}', [Admin\RoleController::class, 'show'])->middleware('perm:users.manage|audit.view')->where('name', '[a-z_]+')->name('roles.show');
    Route::get('settings', [Admin\SettingsController::class, 'show'])->middleware('perm:settings.manage')->name('settings.show');
    Route::patch('settings', [Admin\SettingsController::class, 'update'])->middleware('perm:settings.manage')->name('settings.update');
    $crud('notification-channels', Admin\NotificationChannelController::class, 'settings.manage', 'settings.manage');
    Route::post('notification-channels/{id}/test', [Admin\NotificationChannelController::class, 'test'])->middleware('perm:settings.manage')->name('notification-channels.test');
    $crud('integrations', Admin\IntegrationController::class, 'settings.manage', 'settings.manage');
    Route::get('audit-logs', [Admin\AuditLogController::class, 'index'])->middleware('perm:audit.view')->name('audit-logs.index');
    Route::get('audit-logs/verify', [Admin\AuditLogController::class, 'verify'])->middleware('perm:audit.view')->name('audit-logs.verify');

    // Customer panel
    Route::prefix('app')->name('app.')->group(function () {
        Route::middleware('perm:app.manage')->group(function () {
            // The customer's own incidents and uptime, scoped by the tenant
            // global scope rather than by a filter.
            Route::get('incidents', [Customer\IncidentController::class, 'index'])->name('incidents.index');
            Route::get('uptime', [Customer\IncidentController::class, 'uptime'])->name('uptime.index');
            Route::get('apps', [Customer\AppController::class, 'index'])->name('apps.index');
            Route::post('apps', [Customer\AppController::class, 'store'])->name('apps.store');
            Route::post('apps/validate-source', [Customer\AppController::class, 'validateSource'])->name('apps.validate-source');
            Route::get('apps/{id}', [Customer\AppController::class, 'show'])->name('apps.show');
            Route::patch('apps/{id}', [Customer\AppController::class, 'update'])->name('apps.update');
            Route::delete('apps/{id}', [Customer\AppController::class, 'destroy'])->name('apps.destroy');
            Route::post('apps/{id}/restart', [Customer\AppController::class, 'restart'])->name('apps.restart');
            Route::post('apps/{id}/redeploy', [Customer\AppController::class, 'redeploy'])->name('apps.redeploy');
            Route::post('apps/{id}/rollback', [Customer\AppController::class, 'rollback'])->name('apps.rollback');
            Route::get('apps/{id}/logs', [Customer\AppController::class, 'logs'])->name('apps.logs');
            Route::get('apps/{id}/releases', [Customer\AppController::class, 'releases'])->name('apps.releases');
            Route::get('apps/{id}/metrics', [Customer\AppController::class, 'metrics'])->name('apps.metrics');

            // File manager for the customer's own apps (FM, User · Files); no chown, no security scan yet (USEC).
            Route::get('files', [Customer\FileController::class, 'apps'])->name('files.apps');
            Route::get('apps/{id}/files', [Customer\FileController::class, 'index'])->name('files.index');
            Route::get('apps/{id}/files/content', [Customer\FileController::class, 'show'])->name('files.show');
            Route::put('apps/{id}/files/content', [Customer\FileController::class, 'write'])->name('files.write');
            Route::post('apps/{id}/files/folders', [Customer\FileController::class, 'mkdir'])->name('files.mkdir');
            Route::post('apps/{id}/files/rename', [Customer\FileController::class, 'rename'])->name('files.rename');
            Route::delete('apps/{id}/files', [Customer\FileController::class, 'destroy'])->name('files.destroy');
            Route::post('apps/{id}/files/upload', [Customer\FileController::class, 'upload'])->name('files.upload');
            Route::post('apps/{id}/files/download', [Customer\FileController::class, 'download'])->name('files.download');
            Route::post('apps/{id}/files/copy', [Customer\FileController::class, 'copy'])->name('files.copy');
            Route::post('apps/{id}/files/move', [Customer\FileController::class, 'move'])->name('files.move');
            Route::post('apps/{id}/files/compress', [Customer\FileController::class, 'compress'])->name('files.compress');
            Route::post('apps/{id}/files/extract', [Customer\FileController::class, 'extract'])->name('files.extract');
            Route::post('apps/{id}/files/trash', [Customer\FileController::class, 'trash'])->name('files.trash');
            Route::get('apps/{id}/files/trash', [Customer\FileController::class, 'trashIndex'])->name('files.trash-index');
            Route::post('apps/{id}/files/trash/restore', [Customer\FileController::class, 'restore'])->name('files.restore');
            Route::delete('apps/{id}/files/trash', [Customer\FileController::class, 'purge'])->name('files.purge');
            Route::post('apps/{id}/files/chmod', [Customer\FileController::class, 'chmod'])->name('files.chmod');
            Route::get('apps/{id}/files/search', [Customer\FileController::class, 'search'])->name('files.search');
            Route::post('apps/{id}/files/preview', [Customer\FileController::class, 'preview'])->name('files.preview');
            Route::get('apps/{id}/files/history', [Customer\FileController::class, 'history'])->name('files.history');
            Route::get('apps/{id}/files/history/version', [Customer\FileController::class, 'historyShow'])->name('files.history-show');
            Route::post('apps/{id}/files/history/restore', [Customer\FileController::class, 'historyRestore'])->name('files.history-restore');

            Route::get('domains', [Customer\DomainController::class, 'index'])->name('domains.index');
            Route::post('domains', [Customer\DomainController::class, 'store'])->name('domains.store');
            Route::get('domains/{id}', [Customer\DomainController::class, 'show'])->name('domains.show');
            Route::delete('domains/{id}', [Customer\DomainController::class, 'destroy'])->name('domains.destroy');
            Route::post('domains/{id}/verify', [Customer\DomainController::class, 'verify'])->name('domains.verify');

            Route::get('backups', [Customer\BackupController::class, 'index'])->name('backups.index');
            Route::post('backups', [Customer\BackupController::class, 'store'])->name('backups.store');
            Route::post('backups/{id}/restore', [Customer\BackupController::class, 'restore'])->name('backups.restore');
            Route::get('backups/{id}/download', [Customer\BackupController::class, 'download'])->name('backups.download');

            Route::get('templates', [Customer\TemplateController::class, 'index'])->name('templates.index');
        });
        Route::middleware('perm:app.billing')->group(function () {
            Route::get('billing', [Customer\BillingController::class, 'show'])->name('billing.show');
            Route::get('billing/plans', [Customer\BillingController::class, 'plans'])->name('billing.plans');
            Route::get('billing/invoices', [Customer\BillingController::class, 'invoices'])->name('billing.invoices');
            Route::get('billing/invoices/{id}/pdf', [Customer\BillingController::class, 'invoicePdf'])->name('billing.invoice-pdf');
        });
        Route::middleware('perm:app.billing')->group(function () {
            Route::post('billing/plan', [Customer\BillingController::class, 'changePlan'])->middleware('perm:app.team')->name('billing.plan');
            Route::post('billing/payment-method', [Customer\BillingController::class, 'paymentMethod'])->name('billing.payment-method');
            Route::post('billing/portal', [Customer\BillingController::class, 'portal'])->name('billing.portal');
        });
        Route::get('account', [Customer\AccountController::class, 'show'])->name('account.show');
        Route::patch('account', [Customer\AccountController::class, 'update'])->name('account.update');
        Route::post('account/team', [Customer\AccountController::class, 'team'])->middleware('perm:app.team')->name('account.team');
        Route::middleware('perm:app.manage|app.team')->group(function () {
            Route::get('account/tokens', [Customer\AccountController::class, 'tokens'])->name('account.tokens');
            Route::post('account/tokens', [Customer\AccountController::class, 'createToken'])->name('account.tokens.store');
            Route::delete('account/tokens/{id}', [Customer\AccountController::class, 'deleteToken'])->name('account.tokens.destroy');
        });
    });
});
