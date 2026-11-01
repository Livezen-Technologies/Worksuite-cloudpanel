<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** GIT: deploy keys, provider connections and a log of webhook deliveries. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ssh_keys', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->string('name', 80);
            $t->text('public_key');
            $t->text('private_key'); // encrypted
            $t->string('fingerprint', 80);
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'name']);
        });
        Schema::create('git_connections', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->string('provider', 20); // github|gitlab|gitea|bitbucket
            $t->string('base_url')->nullable(); // self-hosted GitLab / Gitea
            $t->string('username', 120)->nullable(); // Bitbucket app passwords need it
            $t->text('token'); // encrypted
            $t->string('account', 200)->nullable();
            $t->string('status', 20)->default('connected'); // connected|failed
            $t->string('error', 500)->nullable();
            $t->timestamp('checked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('webhook_deliveries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->uuid('project_id')->nullable();
            $t->string('provider', 20)->nullable();
            $t->string('event', 60);
            $t->string('repo', 300)->nullable();
            $t->string('ref', 200)->nullable();
            $t->string('outcome', 20); // deployed|preview|ignored|rejected
            $t->string('detail', 500)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('git_connections');
        Schema::dropIfExists('ssh_keys');
    }
};
