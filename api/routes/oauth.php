<?php

use App\Http\Controllers\McpOAuthController;
use Illuminate\Support\Facades\Route;

// UAI: OAuth for MCP clients. Served at the site root, where clients look for it.
Route::get('.well-known/oauth-protected-resource/{path?}', [McpOAuthController::class, 'protectedResource'])->where('path', '.*')->name('oauth.protected-resource');
Route::get('.well-known/oauth-authorization-server/{path?}', [McpOAuthController::class, 'authorizationServer'])->where('path', '.*')->name('oauth.metadata');
Route::post('oauth/register', [McpOAuthController::class, 'register'])->middleware('throttle:10,1')->name('oauth.register');
Route::get('oauth/authorize', [McpOAuthController::class, 'authorize'])->middleware('throttle:30,1')->name('oauth.authorize');
Route::post('oauth/token', [McpOAuthController::class, 'token'])->middleware('throttle:30,1')->name('oauth.token');
