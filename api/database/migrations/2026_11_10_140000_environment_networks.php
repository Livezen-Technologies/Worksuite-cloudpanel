<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NET/PRJ: a Docker network belonging to an environment rather than only to a
 * server, so a project's services can be put on a network of their own.
 *
 * Step one is additive: these networks are created and joined alongside the
 * shared `proxy` network, which keeps Traefik able to reach every container.
 * Dropping `proxy` is a separate, flagged change — Traefik has to be attached
 * to the environment's network first or every site 502s.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('networks', function (Blueprint $t) {
            $t->foreignUuid('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $t->foreignUuid('environment_id')->nullable()->constrained('environments')->cascadeOnDelete();
            // One network per environment per server; a plain index because an
            // environment legitimately has one on each server its apps run on.
            $t->index(['environment_id', 'server_id']);
        });
    }

    public function down(): void
    {
        Schema::table('networks', function (Blueprint $t) {
            $t->dropIndex(['environment_id', 'server_id']);
            $t->dropConstrainedForeignId('project_id');
            $t->dropConstrainedForeignId('environment_id');
        });
    }
};
