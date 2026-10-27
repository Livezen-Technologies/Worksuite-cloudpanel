<?php

use App\Http\Controllers\Relay\RelayController;
use Illuminate\Support\Facades\Route;

/* Internal endpoints for the terminal relay (agent/cmd/relay), base /internal/relay, X-Relay-Secret. */
$uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
Route::post('agents/verify', [RelayController::class, 'verifyAgent'])->name('relay.agents.verify');
Route::post('terminal-sessions/{id}/connect', [RelayController::class, 'connect'])->where('id', $uuid)->name('relay.terminal.connect');
Route::get('terminal-sessions/{id}', [RelayController::class, 'status'])->where('id', $uuid)->name('relay.terminal.status');
Route::post('terminal-sessions/{id}/closed', [RelayController::class, 'closed'])->where('id', $uuid)->name('relay.terminal.closed');
Route::post('terminal-sessions/{id}/recording', [RelayController::class, 'recording'])->where('id', $uuid)->name('relay.terminal.recording');
