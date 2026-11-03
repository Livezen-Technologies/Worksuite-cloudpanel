<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRJ-NET: when the ingress Traefik was last confirmed to be on this network.
 *
 * Without it the reconcile would re-dispatch an attach for every environment
 * network every fifteen minutes for ever. Null means "not attached as far as
 * the panel knows", which is the safe default: an unnecessary attach is
 * harmless and idempotent, while a missing one is what breaks routing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->timestamp('ingress_attached_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->dropColumn('ingress_attached_at');
        });
    }
};
