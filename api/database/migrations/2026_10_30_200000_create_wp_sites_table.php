<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** WordPress toolkit (WP-001): one row per WordPress app, refreshed from WP-CLI. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wp_sites', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->uuid('application_id')->unique();
            $t->uuid('server_id');
            $t->string('url')->nullable();
            $t->string('version')->nullable();
            $t->string('php_version')->nullable();
            $t->string('core_update')->nullable();
            $t->json('plugins')->nullable();
            $t->json('themes')->nullable();
            $t->json('settings')->nullable();          // maintenance, debug, indexing, auto_minor, platform_cron, redis
            $t->json('vulnerabilities')->nullable();
            $t->unsignedInteger('updates_count')->default(0);
            $t->unsignedInteger('security_count')->default(0);
            $t->unsignedTinyInteger('score')->nullable();
            $t->boolean('staging')->default(false);
            $t->text('inventory_error')->nullable();
            $t->timestamp('inventoried_at')->nullable();
            $t->timestamp('scanned_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wp_sites');
    }
};
