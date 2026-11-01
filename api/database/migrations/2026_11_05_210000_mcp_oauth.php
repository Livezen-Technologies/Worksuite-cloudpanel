<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** UAI: OAuth 2.1 for MCP clients (dynamic registration, PKCE codes, rotating refresh tokens). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_clients', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('client_id', 64)->unique();
            $t->string('name', 120);
            $t->json('redirect_uris');
            $t->timestamps();
        });
        Schema::create('oauth_codes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code_hash', 64)->unique();
            $t->string('client_id', 64);
            $t->uuid('user_id');
            $t->uuid('organization_id');
            $t->string('redirect_uri', 2000);
            $t->string('code_challenge', 128);
            $t->boolean('write')->default(false);
            $t->timestamp('expires_at');
            $t->timestamp('used_at')->nullable();
            $t->timestamps();
        });
        Schema::create('oauth_refresh_tokens', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('token_hash', 64)->unique();
            $t->string('client_id', 64);
            $t->uuid('user_id');
            $t->uuid('organization_id');
            $t->uuid('access_token_id')->nullable();
            $t->boolean('write')->default(false);
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_refresh_tokens');
        Schema::dropIfExists('oauth_codes');
        Schema::dropIfExists('oauth_clients');
    }
};
