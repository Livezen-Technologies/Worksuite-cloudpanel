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
    Route::post('auth/forgot-password', [\App\Http\Controllers\AccountRecoveryController::class, 'forgot'])->name('auth.forgot-password');
    Route::post('auth/reset-password', [\App\Http\Controllers\AccountRecoveryController::class, 'reset'])->name('auth.reset-password');
    Route::post('auth/verify-email', [\App\Http\Controllers\AccountRecoveryController::class, 'verify'])->name('auth.verify-email');
});
Route::post('hooks/git/{project}', [HookController::class, 'git'])->middleware('throttle:api')->name('hooks.git');
Route::post('hooks/scheduled-jobs/{id}/{token}', [HookController::class, 'scheduledJob'])->middleware('throttle:api')->name('hooks.scheduled-job');
Route::get('backup-download/{id}', [\App\Http\Controllers\SignedFileController::class, 'backupDownload'])->middleware('signed')->name('backup.download');
Route::match(['get', 'post'], 'shares/{token}', [\App\Http\Controllers\ShareController::class, 'show'])->middleware('throttle:api')->where('token', '[A-Za-z0-9]{40}')->name('files.share-public');
Route::get('file-downloads/{id}', [\App\Http\Controllers\SignedFileController::class, 'fileDownload'])->middleware('signed')->name('files.transfer');
Route::get('invoices/{id}/pdf', [\App\Http\Controllers\SignedFileController::class, 'invoicePdf'])->middleware('signed')->name('invoice.pdf');
Route::post('webhooks/stripe', [\App\Http\Controllers\StripeWebhookController::class, 'handle'])->middleware('throttle:api')->name('webhooks.stripe');

Route::middleware(['auth:sanctum', 'tenant', 'throttle:api', 'idempotent', 'audit'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('me', [AuthController::class, 'me'])->name('me.show');
    Route::post('me/verify-email', [\App\Http\Controllers\AccountRecoveryController::class, 'resend'])->middleware('throttle:3,10')->name('me.verify-email');
    Route::match(['get', 'patch'], 'me/email-preferences', [Admin\EmailTemplateController::class, 'preferences'])->name('me.email-preferences');
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
        Route::get('servers/{id}/cleanup', [Admin\CleanupController::class, 'show'])->name('servers.cleanup');
        Route::get('servers/{id}/tunnel', [Admin\TunnelController::class, 'show'])->name('servers.tunnel');
        Route::get('mesh', [Admin\MeshController::class, 'show'])->name('mesh.show');
    });
    Route::middleware('perm:servers.manage')->group(function () {
        Route::post('servers', [Admin\ServerController::class, 'store'])->name('servers.store');
        Route::patch('servers/{id}', [Admin\ServerController::class, 'update'])->name('servers.update');
        Route::post('servers/enrollment', [Admin\ServerController::class, 'enrollment'])->name('servers.enrollment');
        Route::patch('servers/{id}/cleanup', [Admin\CleanupController::class, 'update'])->name('servers.cleanup.update');
        Route::post('servers/{id}/cleanup', [Admin\CleanupController::class, 'run'])->name('servers.cleanup.run');
        Route::post('servers/{id}/tunnel', [Admin\TunnelController::class, 'enable'])->name('servers.tunnel.enable');
        Route::post('mesh/servers/{id}', [Admin\MeshController::class, 'join'])->name('mesh.join');
        Route::delete('mesh/servers/{id}', [Admin\MeshController::class, 'leave'])->name('mesh.leave');
        Route::post('mesh/apply', [Admin\MeshController::class, 'apply'])->name('mesh.apply');
        Route::post('mesh/servers/{id}/ssh-lock', [Admin\MeshController::class, 'lockSsh'])->name('mesh.ssh-lock');
        Route::post('mesh/servers/{id}/ssh-lock/confirm', [Admin\MeshController::class, 'confirmSsh'])->name('mesh.ssh-lock.confirm');
        Route::delete('mesh/servers/{id}/ssh-lock', [Admin\MeshController::class, 'unlockSsh'])->name('mesh.ssh-lock.destroy');
        Route::post('mesh/vpn-peers', [Admin\MeshController::class, 'addPeer'])->name('mesh.vpn-peers.store');
        Route::delete('mesh/vpn-peers/{id}', [Admin\MeshController::class, 'revokePeer'])->name('mesh.vpn-peers.destroy');
        Route::post('servers/{id}/tunnel/sync', [Admin\TunnelController::class, 'sync'])->name('servers.tunnel.sync');
        Route::post('servers/{id}/tunnel/restart', [Admin\TunnelController::class, 'restart'])->name('servers.tunnel.restart');
        Route::delete('servers/{id}/tunnel', [Admin\TunnelController::class, 'disable'])->name('servers.tunnel.disable');
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
        Route::get('applications/{id}/scaling', [Admin\ScalingController::class, 'show'])->name('applications.scaling');
        Route::get('builds/usage', Admin\BuildUsageController::class)->name('builds.usage');
        Route::get('templates', [Admin\TemplateController::class, 'index'])->name('templates.index');
        Route::get('templates/{template}', [Admin\TemplateController::class, 'show'])->name('templates.show');
        Route::get('template-installs', [Admin\TemplateController::class, 'installs'])->name('template-installs.index');
        Route::get('projects', [Admin\ProjectController::class, 'index'])->name('projects.index');
        Route::get('projects/{id}', [Admin\ProjectController::class, 'show'])->name('projects.show');
        Route::get('environments/{id}', [Admin\ProjectController::class, 'showEnvironment'])->name('environments.show');
        Route::get('deployments', [Admin\DeploymentController::class, 'index'])->name('deployments.index');
        Route::get('deployments/{id}', [Admin\DeploymentController::class, 'show'])->name('deployments.show');
    });
    Route::middleware('perm:deploy')->group(function () {
        Route::post('applications', [Admin\ApplicationController::class, 'store'])->name('applications.store');
        Route::patch('applications/{id}', [Admin\ApplicationController::class, 'update'])->name('applications.update');
        Route::post('applications/{id}/rollback', [Admin\ApplicationController::class, 'rollback'])->name('applications.rollback');
        Route::patch('applications/{id}/scaling', [Admin\ScalingController::class, 'update'])->name('applications.scaling.update');
        Route::post('applications/{id}/canary', [Admin\ScalingController::class, 'canary'])->name('applications.canary');
        Route::patch('applications/{id}/canary', [Admin\ScalingController::class, 'canaryWeight'])->name('applications.canary.weight');
        Route::post('applications/{id}/canary/promote', [Admin\ScalingController::class, 'promoteCanary'])->name('applications.canary.promote');
        Route::delete('applications/{id}/canary', [Admin\ScalingController::class, 'abortCanary'])->name('applications.canary.abort');
        Route::post('deployments', [Admin\DeploymentController::class, 'store'])->name('deployments.store');
        Route::post('templates/{template}/install', [Admin\TemplateController::class, 'install'])->name('templates.install');
        Route::post('template-installs/{id}/update', [Admin\TemplateController::class, 'update'])->name('template-installs.update');
        Route::post('templates-sync', [Admin\TemplateController::class, 'sync'])->name('templates.sync');
        Route::post('projects', [Admin\ProjectController::class, 'store'])->name('projects.store');
        Route::delete('projects/{id}', [Admin\ProjectController::class, 'destroy'])->name('projects.destroy');
        Route::post('projects/{id}/webhook-secret', [Admin\ProjectController::class, 'rotateSecret'])->name('projects.webhook-secret');
        Route::post('projects/{id}/environments', [Admin\ProjectController::class, 'storeEnvironment'])->name('environments.store');
        Route::patch('environments/{id}', [Admin\ProjectController::class, 'updateEnvironment'])->name('environments.update');
        Route::delete('environments/{id}', [Admin\ProjectController::class, 'destroyEnvironment'])->name('environments.destroy');
        Route::post('environments/{id}/duplicate', [Admin\ProjectController::class, 'duplicate'])->name('environments.duplicate');
        Route::post('environments/{id}/promote', [Admin\ProjectController::class, 'promote'])->name('environments.promote');
        Route::post('environments/{id}/wake', [Admin\ProjectController::class, 'wake'])->name('environments.wake');
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
    Route::get('volumes/{id}/files/shares', [Admin\FileController::class, 'shares'])->middleware('perm:deploy|network_storage.manage')->name('files.shares');
    Route::post('volumes/{id}/files/shares', [Admin\FileController::class, 'share'])->middleware('perm:deploy|network_storage.manage')->name('files.share');
    Route::delete('volumes/{id}/files/shares/{shareId}', [Admin\FileController::class, 'revokeShare'])->middleware('perm:deploy|network_storage.manage')->name('files.share-revoke');
    Route::get('volumes/{id}/files/transfers', [Admin\FileController::class, 'transfers'])->middleware('perm:deploy|network_storage.manage')->name('files.transfers');
    Route::post('volumes/{id}/files/transfers/{jobId}/retry', [Admin\FileController::class, 'retryTransfer'])->middleware('perm:deploy|network_storage.manage')->name('files.transfer-retry');
    Route::post('volumes/{id}/files/transfers/{jobId}/cancel', [Admin\FileController::class, 'cancelTransfer'])->middleware('perm:deploy|network_storage.manage')->name('files.transfer-cancel');
    Route::delete('volumes/{id}/files', [Admin\FileController::class, 'destroy'])->middleware('perm:deploy|network_storage.manage')->name('files.destroy');
    Route::get('storage-pools', [Admin\VolumeController::class, 'pools'])->middleware('perm:resources.view')->name('storage-pools.index');

    // Domains & databases
    $crud('domains', Admin\DomainController::class, 'resources.view', 'deploy');
    Route::match(['put', 'patch'], 'domains/{id}/waf', [Admin\DomainController::class, 'waf'])->middleware('perm:deploy|settings.manage')->name('domains.waf');
    Route::post('domains/{id}/verify', [Admin\DomainController::class, 'verify'])->middleware('perm:deploy')->name('domains.verify');
    Route::post('domains/{id}/certificate', [Admin\DomainController::class, 'certificate'])->middleware('perm:deploy')->name('domains.certificate');
    $crud('databases', Admin\DatabaseController::class, 'resources.view', 'deploy');
    Route::post('databases/{id}/backup', [Admin\DatabaseController::class, 'backup'])->middleware('perm:deploy')->name('databases.backup');
    Route::post('databases/{id}/restore', [Admin\DatabaseController::class, 'restore'])->middleware('perm:deploy')->name('databases.restore');

    // Backups
    $crud('backup-policies', Admin\BackupPolicyController::class, 'resources.view', 'deploy|network_storage.manage');
    Route::get('backup-jobs', [Admin\BackupJobController::class, 'index'])->middleware('perm:resources.view')->name('backup-jobs.index');
    Route::get('backup-artifacts', [Admin\BackupJobController::class, 'artifacts'])->middleware('perm:resources.view')->name('backup-artifacts.index');
    Route::get('backups/summary', [Admin\BackupJobController::class, 'summary'])->middleware('perm:resources.view')->name('backups.summary');
    Route::post('backups/run-all', [Admin\BackupJobController::class, 'runAll'])->middleware('perm:deploy|network_storage.manage')->name('backups.run-all');
    Route::post('backup-policies/{id}/probe', [Admin\BackupJobController::class, 'probe'])->middleware('perm:deploy|network_storage.manage')->name('backup-policies.probe');
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
    // CRON-1..9: the cron engine, across every customer for platform admins.
    Route::get('cron', [Admin\CronController::class, 'index'])->middleware('perm:resources.view|servers.view')->name('cron.index');
    Route::post('cron/preview', [Admin\CronController::class, 'preview'])->middleware('perm:resources.view|servers.view')->name('cron.preview');
    Route::post('cron', [Admin\CronController::class, 'store'])->middleware('perm:servers.manage')->name('cron.store');
    Route::patch('cron/{id}', [Admin\CronController::class, 'update'])->middleware('perm:servers.manage')->name('cron.update');
    Route::delete('cron/{id}', [Admin\CronController::class, 'destroy'])->middleware('perm:servers.manage')->name('cron.destroy');
    Route::post('cron/{id}/run', [Admin\CronController::class, 'run'])->middleware(['perm:servers.manage', 'throttle:20,1'])->name('cron.run');
    Route::post('cron/{id}/duplicate', [Admin\CronController::class, 'duplicate'])->middleware('perm:servers.manage')->name('cron.duplicate');
    Route::get('cron/{id}/runs', [Admin\CronController::class, 'runs'])->middleware('perm:resources.view|servers.view')->name('cron.runs');
    Route::get('cron/{id}/runs/{runId}/download', [Admin\CronController::class, 'download'])->middleware('perm:resources.view|servers.view')->name('cron.download');
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
    Route::get('antivirus/integrity', [Admin\AntivirusController::class, 'integrity'])->middleware('perm:resources.view|servers.view')->name('antivirus.integrity');
    Route::post('antivirus/integrity/{id}/{action}', [Admin\AntivirusController::class, 'integrityAction'])->middleware('perm:deploy|network_storage.manage')->whereIn('action', ['baseline', 'check', 'accept'])->name('antivirus.integrity-action');
    Route::match(['get', 'patch'], 'antivirus/servers/{id}', [Admin\AntivirusController::class, 'serverSecurity'])->middleware('perm:settings.manage')->name('antivirus.server');
    // WordPress toolkit (SRS WP)
    // Mail hosting (MAIL-001..005).
    Route::get('mail', [Admin\MailController::class, 'index'])->middleware('perm:resources.view|servers.view')->name('mail.index');
    Route::post('mail/servers', [Admin\MailController::class, 'install'])->middleware('perm:servers.manage')->name('mail.install');
    Route::patch('mail/servers/{id}', [Admin\MailController::class, 'updateServer'])->middleware('perm:servers.manage')->name('mail.servers.update');
    Route::post('mail/servers/{id}/health', [Admin\MailController::class, 'health'])->middleware('perm:resources.view|servers.view')->name('mail.servers.health');
    Route::post('mail/servers/{id}/certs', [Admin\MailController::class, 'certs'])->middleware('perm:servers.manage')->name('mail.servers.certs');
    Route::post('mail/servers/{id}/backups', [Admin\MailController::class, 'backups'])->middleware('perm:servers.manage')->name('mail.servers.backups');
    Route::get('mail/servers/{id}/logs', [Admin\MailController::class, 'logs'])->middleware('perm:logs.view|servers.view')->name('mail.servers.logs');
    Route::post('mail/domains/{id}/dkim/{step}', [Admin\MailController::class, 'dkim'])->middleware('perm:deploy')->whereIn('step', ['rotate', 'complete'])->name('mail.dkim');
    Route::patch('mail/domains/{id}/mailboxes/{box}/autoresponder', [Admin\MailController::class, 'autoresponder'])->middleware('perm:deploy')->name('mail.autoresponder');
    Route::post('mail/domains/{id}/mailboxes/{box}/import', [Admin\MailController::class, 'startImport'])->middleware(['perm:deploy', 'throttle:10,1'])->name('mail.import');
    Route::get('mail/domains/{id}/imports', [Admin\MailController::class, 'imports'])->middleware('perm:resources.view|servers.view')->name('mail.imports');
    Route::post('mail/servers/{id}/webmail', [Admin\MailController::class, 'webmail'])->middleware('perm:servers.manage')->name('mail.webmail');
    Route::get('mail/holds', [Admin\MailGuardController::class, 'holds'])->middleware('perm:resources.view|servers.view')->name('mail.holds');
    Route::get('mail/log', [Admin\MailGuardController::class, 'search'])->middleware('perm:logs.view|servers.view')->name('mail.log');
    Route::delete('mail/domains/{id}/hold', [Admin\MailGuardController::class, 'releaseDomain'])->middleware('perm:deploy')->name('mail.domains.release');
    Route::delete('mail/domains/{id}/mailboxes/{box}/hold', [Admin\MailGuardController::class, 'releaseMailbox'])->middleware('perm:deploy')->name('mail.mailboxes.release');
    Route::patch('mail/domains/{id}/limits', [Admin\MailGuardController::class, 'limits'])->middleware('perm:deploy')->name('mail.domains.limits');
    Route::patch('mail/domains/{id}/mailboxes/{box}/spam', [Admin\MailGuardController::class, 'spam'])->middleware('perm:deploy')->name('mail.mailboxes.spam');
    Route::post('mail/domains', [Admin\MailController::class, 'storeDomain'])->middleware('perm:deploy')->name('mail.domains.store');
    Route::get('mail/domains/{id}', [Admin\MailController::class, 'showDomain'])->middleware('perm:resources.view|servers.view')->name('mail.domains.show');
    Route::patch('mail/domains/{id}', [Admin\MailController::class, 'updateDomain'])->middleware('perm:deploy')->name('mail.domains.update');
    Route::delete('mail/domains/{id}', [Admin\MailController::class, 'destroyDomain'])->middleware('perm:deploy')->name('mail.domains.destroy');
    Route::post('mail/domains/{id}/dns/check', [Admin\MailController::class, 'checkDns'])->middleware('perm:resources.view|servers.view')->name('mail.dns.check');
    Route::post('mail/domains/{id}/dns/publish', [Admin\MailController::class, 'publishDns'])->middleware('perm:deploy')->name('mail.dns.publish');
    Route::post('mail/domains/{id}/mailboxes', [Admin\MailController::class, 'storeMailbox'])->middleware('perm:deploy')->name('mail.mailboxes.store');
    Route::patch('mail/domains/{id}/mailboxes/{box}', [Admin\MailController::class, 'updateMailbox'])->middleware('perm:deploy')->name('mail.mailboxes.update');
    Route::delete('mail/domains/{id}/mailboxes/{box}', [Admin\MailController::class, 'destroyMailbox'])->middleware('perm:deploy')->name('mail.mailboxes.destroy');
    Route::post('mail/domains/{id}/aliases', [Admin\MailController::class, 'storeAlias'])->middleware('perm:deploy')->name('mail.aliases.store');
    Route::delete('mail/domains/{id}/aliases/{alias}', [Admin\MailController::class, 'destroyAlias'])->middleware('perm:deploy')->name('mail.aliases.destroy');
    // Public pages (PUB-1..4).
    Route::get('public-pages', [Admin\PublicPagesController::class, 'show'])->middleware('perm:resources.view|servers.view')->name('public-pages.show');
    Route::patch('public-pages', [Admin\PublicPagesController::class, 'update'])->middleware('perm:settings.manage')->name('public-pages.update');
    Route::post('public-pages/install', [Admin\PublicPagesController::class, 'install'])->middleware('perm:servers.manage')->name('public-pages.install');
    Route::get('public-pages/preview/{key}', [Admin\PublicPagesController::class, 'preview'])->middleware('perm:resources.view|servers.view')->name('public-pages.preview');
    Route::patch('public-pages/sites/{id}', [Admin\PublicPagesController::class, 'site'])->middleware('perm:deploy')->name('public-pages.site');
    Route::get('migrations', [Admin\MigrationController::class, 'index'])->middleware('perm:resources.view|servers.view')->name('migrations.index');
    Route::post('migrations', [Admin\MigrationController::class, 'store'])->middleware('perm:deploy')->name('migrations.store');
    Route::post('migrations/bulk', [Admin\MigrationController::class, 'bulk'])->middleware('perm:deploy')->name('migrations.bulk');
    Route::post('migrations/{id}/{decision}', [Admin\MigrationController::class, 'decide'])->middleware('perm:deploy')->whereIn('decision', ['approve', 'reject'])->name('migrations.decide');
    Route::post('migrations/{id}/resync', [Admin\MigrationController::class, 'resync'])->middleware('perm:deploy')->name('migrations.resync');
    Route::post('migrations/{id}/sites/{site}/{action}', [Admin\MigrationController::class, 'dns'])->middleware('perm:deploy')->whereNumber('site')->whereIn('action', ['cutover', 'rollback'])->name('migrations.dns');
    Route::get('migrations/{id}', [Admin\MigrationController::class, 'show'])->middleware('perm:resources.view|servers.view')->name('migrations.show');
    Route::patch('migrations/{id}/sites', [Admin\MigrationController::class, 'map'])->middleware('perm:deploy')->name('migrations.map');
    Route::post('migrations/{id}/start', [Admin\MigrationController::class, 'start'])->middleware('perm:deploy')->name('migrations.start');
    Route::post('migrations/{id}/sites/{site}/retry', [Admin\MigrationController::class, 'retry'])->middleware('perm:deploy')->whereNumber('site')->name('migrations.retry');
    Route::get('wordpress', [Admin\WordPressController::class, 'index'])->middleware('perm:resources.view|servers.view')->name('wordpress.index');
    Route::post('wordpress/detect', [Admin\WordPressController::class, 'detect'])->middleware('perm:deploy')->name('wordpress.detect');
    Route::post('wordpress/update-all', [Admin\WordPressController::class, 'updateAll'])->middleware('perm:deploy')->name('wordpress.update-all');
    Route::get('wordpress/{id}', [Admin\WordPressController::class, 'show'])->middleware('perm:resources.view|servers.view')->name('wordpress.show');
    Route::post('wordpress/{id}/refresh', [Admin\WordPressController::class, 'refresh'])->middleware('perm:deploy')->name('wordpress.refresh');
    Route::patch('wordpress/{id}/settings', [Admin\WordPressController::class, 'setting'])->middleware('perm:deploy')->name('wordpress.setting');
    Route::post('wordpress/{id}/tools/{tool}', [Admin\WordPressController::class, 'tool'])->middleware('perm:deploy')->whereIn('tool', ['flush_cache', 'verify_checksums', 'search_replace', 'plugin_activate', 'plugin_deactivate', 'theme_activate', 'rotate_salts'])->name('wordpress.tool');
    Route::post('wordpress/{id}/reset-password', [Admin\WordPressController::class, 'resetPassword'])->middleware('perm:deploy')->name('wordpress.reset-password');
    Route::post('wordpress/{id}/update', [Admin\WordPressController::class, 'update'])->middleware('perm:deploy')->name('wordpress.update');
    Route::patch('wordpress/{id}/hardening', [Admin\WordPressController::class, 'hardening'])->middleware('perm:deploy')->name('wordpress.hardening');
    Route::get('wordpress/{id}/restore-points', [Admin\WordPressController::class, 'restorePoints'])->middleware('perm:resources.view|servers.view')->name('wordpress.restore-points');
    Route::post('wordpress/{id}/restore-points', [Admin\WordPressController::class, 'createRestorePoint'])->middleware('perm:deploy')->name('wordpress.restore-points.create');
    Route::post('wordpress/{id}/restore-points/{point}/restore', [Admin\WordPressController::class, 'restore'])->middleware('perm:deploy')->name('wordpress.restore');
    Route::post('wordpress/{id}/staging', [Admin\WordPressController::class, 'createStaging'])->middleware('perm:deploy')->name('wordpress.staging');
    Route::post('wordpress/{id}/staging/{action}', [Admin\WordPressController::class, 'stagingAction'])->middleware('perm:deploy')->whereIn('action', ['refresh', 'push', 'delete'])->name('wordpress.staging.action');
    Route::post('wordpress/{id}/login-link', [Admin\WordPressController::class, 'loginLink'])->middleware('perm:deploy')->name('wordpress.login-link');
    Route::get('antivirus/score', [Admin\AntivirusController::class, 'score'])->middleware('perm:resources.view|servers.view')->name('antivirus.score');
    Route::get('antivirus/threats', [Admin\AntivirusController::class, 'index'])->middleware('perm:resources.view|servers.view')->name('antivirus.threats');
    Route::post('antivirus/scan', [Admin\AntivirusController::class, 'scan'])->middleware('perm:deploy|network_storage.manage')->name('antivirus.scan');
    Route::post('antivirus/threats/{id}/{action}', [Admin\AntivirusController::class, 'action'])->middleware('perm:deploy|network_storage.manage')->name('antivirus.action');
    Route::get('firewall', [Admin\FirewallController::class, 'show'])->middleware('perm:resources.view|servers.view')->name('firewall.show');
    Route::get('firewall/events', [Admin\FirewallController::class, 'events'])->middleware('perm:resources.view|servers.view')->name('firewall.events');
    Route::match(['put', 'patch'], 'firewall/countries', [Admin\FirewallController::class, 'countries'])->middleware('perm:deploy|settings.manage')->name('firewall.countries');
    Route::post('firewall/paths', [Admin\FirewallController::class, 'storePath'])->middleware('perm:deploy|settings.manage')->name('firewall.paths.store');
    Route::match(['put', 'patch'], 'firewall/paths/{id}', [Admin\FirewallController::class, 'updatePath'])->middleware('perm:deploy|settings.manage')->name('firewall.paths.update');
    Route::delete('firewall/paths/{id}', [Admin\FirewallController::class, 'destroyPath'])->middleware('perm:deploy|settings.manage')->name('firewall.paths.destroy');
    Route::patch('firewall/under-attack', [Admin\FirewallController::class, 'underAttack'])->middleware('perm:deploy|settings.manage')->name('firewall.under-attack');
    Route::get('firewall/events/export', [Admin\FirewallController::class, 'exportEvents'])->middleware('perm:resources.view|servers.view')->name('firewall.events.export');
    Route::post('firewall/ips', [Admin\FirewallController::class, 'storeIp'])->middleware('perm:deploy|settings.manage')->name('firewall.ips.store');
    Route::patch('firewall/ips/{id}', [Admin\FirewallController::class, 'updateIp'])->middleware('perm:deploy|settings.manage')->name('firewall.ips.update');
    Route::delete('firewall/ips/{id}', [Admin\FirewallController::class, 'destroyIp'])->middleware('perm:deploy|settings.manage')->name('firewall.ips.destroy');

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
    // Email templates (EML-1..5).
    Route::get('email-templates', [Admin\EmailTemplateController::class, 'index'])->middleware('perm:settings.manage')->name('email-templates.index');
    Route::patch('email-templates/brand', [Admin\EmailTemplateController::class, 'brand'])->middleware('perm:settings.manage')->name('email-templates.brand');
    Route::get('email-templates/{key}', [Admin\EmailTemplateController::class, 'show'])->middleware('perm:settings.manage')->name('email-templates.show');
    Route::patch('email-templates/{key}', [Admin\EmailTemplateController::class, 'update'])->middleware('perm:settings.manage')->name('email-templates.update');
    Route::post('email-templates/{key}/reset', [Admin\EmailTemplateController::class, 'reset'])->middleware('perm:settings.manage')->name('email-templates.reset');
    Route::post('email-templates/{key}/preview', [Admin\EmailTemplateController::class, 'preview'])->middleware('perm:settings.manage')->name('email-templates.preview');
    Route::post('email-templates/{key}/test', [Admin\EmailTemplateController::class, 'test'])->middleware(['perm:settings.manage', 'throttle:6,1'])->name('email-templates.test');
    Route::post('settings/smtp/test', [Admin\SettingsController::class, 'testSmtp'])->middleware(['perm:settings.manage', 'throttle:6,1'])->name('settings.smtp-test');
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
            // UCRON: scheduled jobs in the customer's apps or calling a URL.
            Route::get('cron', [Customer\CronController::class, 'index'])->name('cron.index');
            Route::post('cron', [Customer\CronController::class, 'store'])->name('cron.store');
            Route::patch('cron/{id}', [Customer\CronController::class, 'update'])->name('cron.update');
            Route::delete('cron/{id}', [Customer\CronController::class, 'destroy'])->name('cron.destroy');
            Route::post('cron/{id}/run', [Customer\CronController::class, 'run'])->middleware('throttle:20,1')->name('cron.run');
            Route::get('cron/{id}/runs', [Customer\CronController::class, 'runs'])->name('cron.runs');
            Route::post('cron/preview', [Customer\CronController::class, 'preview'])->name('cron.preview');
            Route::post('cron/{id}/duplicate', [Customer\CronController::class, 'duplicate'])->name('cron.duplicate');
            Route::get('cron/{id}/runs/{runId}/download', [Customer\CronController::class, 'download'])->name('cron.download');
            // USEC: the customer's own security page.
            Route::get('security', [Customer\SecurityController::class, 'index'])->name('security.index');
            Route::post('security/scan', [Customer\SecurityController::class, 'scan'])->middleware('throttle:6,1')->name('security.scan');
            Route::post('security/threats/{id}/{action}', [Customer\SecurityController::class, 'act'])->whereIn('action', ['quarantine', 'ignore'])->name('security.act');
            // FW-015: protected paths and country rules on the customer's own domains.
            Route::get('firewall', [Customer\FirewallController::class, 'index'])->name('firewall.index');
            Route::post('firewall/domains/{domain}/paths', [Customer\FirewallController::class, 'storePath'])->name('firewall.paths.store');
            Route::patch('firewall/domains/{domain}/paths/{id}', [Customer\FirewallController::class, 'updatePath'])->name('firewall.paths.update');
            Route::delete('firewall/domains/{domain}/paths/{id}', [Customer\FirewallController::class, 'destroyPath'])->name('firewall.paths.destroy');
            Route::patch('firewall/domains/{domain}/countries', [Customer\FirewallController::class, 'countries'])->name('firewall.countries');
            // UMAIL: email on the customer's domains.
            Route::get('mail', [Customer\MailController::class, 'index'])->name('mail.index');
            Route::post('mail/domains', [Customer\MailController::class, 'storeDomain'])->name('mail.domains.store');
            Route::post('mail/domains/{id}/dns/check', [Customer\MailController::class, 'checkDns'])->name('mail.dns.check');
            Route::post('mail/domains/{id}/mailboxes', [Customer\MailController::class, 'storeMailbox'])->name('mail.mailboxes.store');
            Route::patch('mail/domains/{id}/mailboxes/{box}', [Customer\MailController::class, 'updateMailbox'])->name('mail.mailboxes.update');
            Route::delete('mail/domains/{id}/mailboxes/{box}', [Customer\MailController::class, 'destroyMailbox'])->name('mail.mailboxes.destroy');
            Route::patch('mail/domains/{id}/mailboxes/{box}/autoresponder', [Customer\MailController::class, 'autoresponder'])->name('mail.autoresponder');
            Route::post('mail/domains/{id}/mailboxes/{box}/import', [Customer\MailController::class, 'startImport'])->middleware('throttle:10,1')->name('mail.import');
            Route::get('mail/domains/{id}/imports', [Customer\MailController::class, 'imports'])->name('mail.imports');
            Route::post('mail/domains/{id}/aliases', [Customer\MailController::class, 'storeAlias'])->name('mail.aliases.store');
            Route::get('mail/holds', [Customer\MailGuardController::class, 'holds'])->name('mail.holds');
            Route::get('mail/log', [Customer\MailGuardController::class, 'search'])->name('mail.log');
            Route::patch('mail/domains/{id}/mailboxes/{box}/spam', [Customer\MailGuardController::class, 'spam'])->name('mail.mailboxes.spam');
            Route::delete('mail/domains/{id}/aliases/{alias}', [Customer\MailController::class, 'destroyAlias'])->name('mail.aliases.destroy');
            // UMIG: migration requests; an administrator approves and runs them.
            Route::get('migrations', [Customer\MigrationController::class, 'index'])->name('migrations.index');
            Route::post('migrations', [Customer\MigrationController::class, 'store'])->name('migrations.store');
            Route::post('apps', [Customer\AppController::class, 'store'])->name('apps.store');
            Route::post('apps/validate-source', [Customer\AppController::class, 'validateSource'])->name('apps.validate-source');
            Route::get('apps/{id}', [Customer\AppController::class, 'show'])->name('apps.show');
            Route::get('apps/{id}/scaling', [Customer\ScalingController::class, 'show'])->name('apps.scaling');
            Route::get('builds/usage', Customer\BuildUsageController::class)->name('builds.usage');
            Route::patch('apps/{id}/scaling', [Customer\ScalingController::class, 'update'])->name('apps.scaling.update');
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
            Route::get('apps/{id}/files/shares', [Customer\FileController::class, 'shares'])->name('files.shares');
            Route::post('apps/{id}/files/shares', [Customer\FileController::class, 'share'])->name('files.share');
            Route::delete('apps/{id}/files/shares/{shareId}', [Customer\FileController::class, 'revokeShare'])->name('files.share-revoke');
            Route::get('apps/{id}/files/transfers', [Customer\FileController::class, 'transfers'])->name('files.transfers');
            Route::post('apps/{id}/files/transfers/{jobId}/retry', [Customer\FileController::class, 'retryTransfer'])->name('files.transfer-retry');
            Route::post('apps/{id}/files/transfers/{jobId}/cancel', [Customer\FileController::class, 'cancelTransfer'])->name('files.transfer-cancel');

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
