<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** CFT-1..6: one Cloudflare Tunnel per server, its hostnames and health. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloudflare_tunnels', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->foreignUuid('server_id')->unique()->constrained('servers')->cascadeOnDelete();
            $t->foreignUuid('dns_provider_id')->constrained('dns_providers')->cascadeOnDelete();
            $t->string('tunnel_id', 64);
            $t->string('name');
            $t->text('token'); // encrypted
            $t->string('status', 20)->default('inactive'); // healthy|degraded|down|inactive
            $t->unsignedSmallInteger('connections')->default(0);
            $t->json('hostnames')->nullable(); // [{hostname, zone_id, record_id}]
            $t->text('error')->nullable();
            $t->timestamp('last_synced_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloudflare_tunnels');
    }
};
