<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** CFT: close ports 80/443 while the tunnel is healthy. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cloudflare_tunnels', function (Blueprint $t) {
            $t->boolean('close_ports')->default(false);
            $t->boolean('ports_closed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('cloudflare_tunnels', fn (Blueprint $t) => $t->dropColumn(['close_ports', 'ports_closed']));
    }
};
