<?php

use App\Http\Controllers\Agent\AgentController;
use Illuminate\Support\Facades\Route;

/* Agent protocol, base /agent/v1 (outside the user API). */
Route::get('install.sh', [AgentController::class, 'installScript'])->name('agent.install');
Route::get('download/dockermgr-agent-linux-{arch}', [AgentController::class, 'download'])->whereIn('arch', ['amd64', 'arm64'])->name('agent.download');
// backup.export upload for local destinations: the signed relative URL is the capability.
Route::put('backup-exports/{id}', [\App\Http\Controllers\SignedFileController::class, 'backupUpload'])
    ->where('id', '[0-9a-fA-F-]{36}')->middleware('signed:relative')->name('agent.backup-export');
// File manager transfers (FM-002, FM-003): the signed relative URL is the capability.
Route::put('file-transfers/{id}', [\App\Http\Controllers\SignedFileController::class, 'fileTransferPut'])
    ->where('id', '[0-9a-fA-F-]{36}')->middleware('signed:relative')->name('agent.file-transfer-put');
Route::get('file-transfers/{id}/{n}', [\App\Http\Controllers\SignedFileController::class, 'fileTransferGet'])
    ->where(['id' => '[0-9a-fA-F-]{36}', 'n' => '[0-9]{1,4}'])->middleware('signed:relative')->name('agent.file-transfer-get');
Route::get('ca.crt', [\App\Http\Controllers\Agent\CertificateController::class, 'ca'])->name('agent.ca');
Route::post('enroll', [AgentController::class, 'enroll'])->middleware('throttle:auth')->name('agent.enroll');
Route::middleware(['agent', 'throttle:agent'])->group(function () {
    Route::post('heartbeat', [AgentController::class, 'heartbeat'])->name('agent.heartbeat');
    Route::post('certificate/renew', [\App\Http\Controllers\Agent\CertificateController::class, 'renew'])->name('agent.certificate-renew');
    Route::post('commands/{id}/result', [AgentController::class, 'result'])
        ->where('id', '[0-9a-fA-F-]{36}')->name('agent.command-result');
});
