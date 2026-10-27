<?php

use App\Http\Controllers\Agent\AgentController;
use Illuminate\Support\Facades\Route;

/* Agent protocol, base /agent/v1 (outside the user API). */
Route::get('install.sh', [AgentController::class, 'installScript'])->name('agent.install');
Route::get('download/dockermgr-agent-linux-{arch}', [AgentController::class, 'download'])->whereIn('arch', ['amd64', 'arm64'])->name('agent.download');
Route::post('enroll', [AgentController::class, 'enroll'])->middleware('throttle:auth')->name('agent.enroll');
Route::middleware(['agent', 'throttle:agent'])->group(function () {
    Route::post('heartbeat', [AgentController::class, 'heartbeat'])->name('agent.heartbeat');
    Route::post('commands/{id}/result', [AgentController::class, 'result'])
        ->where('id', '[0-9a-fA-F-]{36}')->name('agent.command-result');
});
