<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-domain IP access mode. "open" (default): everyone, minus the block list. "allowlist": only the
 * allow list (plus the always-allowed admin ranges) reaches the site; Traefik answers 403 to the rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', fn (Blueprint $t) => $t->string('ip_access', 16)->default('open'));
    }

    public function down(): void
    {
        Schema::table('domains', fn (Blueprint $t) => $t->dropColumn('ip_access'));
    }
};
