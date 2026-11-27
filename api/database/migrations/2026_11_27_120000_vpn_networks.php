<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VPN phase 1: client-access VPN networks, separate from the server mesh (10.88.0.0/16). A network is
 * a record until it is deployed to its gateway server; nothing is installed by creating one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vpn_networks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('organization_id')->index();
            $t->string('name', 80);
            $t->string('protocol', 16)->default('wireguard'); // wireguard|openvpn
            $t->foreignUuid('gateway_server_id')->nullable()->constrained('servers')->nullOnDelete();
            $t->string('cidr', 43);
            $t->unsignedInteger('port');
            $t->string('transport', 8)->default('udp'); // udp|tcp (openvpn only)
            $t->string('routing', 8)->default('split'); // split|full
            $t->boolean('private_dns')->default(true);
            $t->string('status', 20)->default('draft'); // draft|deploying|active|failed|stopped
            $t->timestamps();
            $t->unique(['organization_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vpn_networks');
    }
};
