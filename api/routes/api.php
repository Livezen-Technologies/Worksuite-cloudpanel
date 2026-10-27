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

    // Users, roles, settings, integrations, audit
    Route::middleware('perm:users.manage')->group(function () {
        Route::get('users', [Admin\UserController::class, 'index'])->name('users.index');
        Route::post('users/invite', [Admin\UserController::class, 'store'])->name('users.invite');
        Route::patch('users/{id}', [Admin\UserController::class, 'update'])->name('users.update');
        Route::delete('users/{id}', [Admin\UserController::class, 'destroy'])->name('users.destroy');
    });
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
