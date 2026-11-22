<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * XNODE-3b: the panel's internal CA for the edge → app-node hop (kind=ca, one row) and one
 * server certificate per app node (kind=node). Private keys are stored with the `encrypted`
 * cast (APP_KEY); certificates are public. `ports` is what the node's ws-edge-tls terminator
 * was created with, `live_ports` what the agent confirmed (the edges switch to https for a
 * port only once it is live there).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_tls_certs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('kind', 8);
            $t->uuid('server_id')->nullable()->unique();
            $t->text('cert_pem');
            $t->text('key_encrypted');
            $t->string('serial', 40);
            $t->string('san_ip', 64)->nullable();
            $t->timestamp('not_after');
            $t->json('ports')->nullable();
            $t->json('live_ports')->nullable();
            $t->timestamps();
            $t->index('kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_tls_certs');
    }
};
