<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Migration wizard (MIG): moving accounts from another control panel. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_migrations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->uuid('server_id');
            $t->string('source');                      // cpanel
            $t->text('archive_url');                   // encrypted
            $t->string('archive_sha256')->nullable();
            $t->string('status')->default('fetching'); // fetching, ready, migrating, migrated, failed
            $t->json('inventory')->nullable();         // user, sites, databases, files, bytes
            $t->json('sites')->nullable();             // the mapping, with per-site status
            $t->json('timeline')->nullable();
            $t->text('error')->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_migrations');
    }
};
