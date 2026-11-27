<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** VPN phase 2: what a deployed VPN network is on its gateway. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vpn_networks', function (Blueprint $t) {
            $t->string('interface', 15)->nullable();
            $t->string('public_key', 64)->nullable();
            $t->text('last_error')->nullable();
            $t->json('gateway_status')->nullable();
            $t->timestamp('deployed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vpn_networks', fn (Blueprint $t) => $t->dropColumn(['interface', 'public_key', 'last_error', 'gateway_status', 'deployed_at']));
    }
};
