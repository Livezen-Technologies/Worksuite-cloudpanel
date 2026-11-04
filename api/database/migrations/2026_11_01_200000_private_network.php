<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PNET-1..5: WireGuard mesh membership per server and admin VPN peers. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $t) {
            $t->string('mesh_ip', 15)->nullable();
            $t->string('mesh_public_key', 44)->nullable();
            $t->timestamp('mesh_joined_at')->nullable();
            $t->json('mesh_status')->nullable();
        });
        Schema::create('vpn_peers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('name', 60);
            $t->string('public_key', 44);
            $t->string('address', 15);
            $t->timestamp('last_handshake_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vpn_peers');
        Schema::table('servers', fn (Blueprint $t) => $t->dropColumn(['mesh_ip', 'mesh_public_key', 'mesh_joined_at', 'mesh_status']));
    }
};
