<?php

use App\Http\Controllers\Partner\DeploymentController;
use Illuminate\Support\Facades\Route;

/*
| Partner API, base /partner/v1 (docs/PARTNER_API.md). HMAC-signed by a
| partner client, scope-checked per route, Idempotency-Key on every POST.
| Deployments are addressed by the partner's own id ({ext}).
*/
Route::pattern('ext', '[A-Za-z0-9_-]{1,64}');

Route::middleware('partner:deployments:read')->group(function () {
    Route::get('summary', [DeploymentController::class, 'summary'])->name('partner.summary');
    Route::get('deployments', [DeploymentController::class, 'index'])->name('partner.deployments.index');
    Route::get('deployments/{ext}', [DeploymentController::class, 'show'])->name('partner.deployments.show');
    Route::get('deployments/{ext}/logs', [DeploymentController::class, 'logs'])->name('partner.deployments.logs');
});
Route::middleware('partner:deployments:write')->group(function () {
    Route::post('deployments', [DeploymentController::class, 'store'])->name('partner.deployments.store');
    Route::post('deployments/{ext}/domain', [DeploymentController::class, 'domain'])->name('partner.deployments.domain');
    Route::post('deployments/{ext}/domain/verify', [DeploymentController::class, 'verifyDomain'])->name('partner.deployments.domain-verify');
    Route::delete('deployments/{ext}/domain', [DeploymentController::class, 'removeDomain'])->name('partner.deployments.domain-remove');
});
Route::post('deployments/{ext}/actions', [DeploymentController::class, 'action'])->middleware('partner:deployments:operate')->name('partner.deployments.action');
Route::post('deployments/{ext}/lifecycle', [DeploymentController::class, 'lifecycle'])->middleware('partner:deployments:lifecycle')->name('partner.deployments.lifecycle');
