<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SWARM: Docker Swarm clusters across servers. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swarm_clusters', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->string('name', 60);
            $t->foreignUuid('manager_server_id')->nullable()->constrained('servers')->nullOnDelete();
            $t->string('advertise_addr', 45)->nullable();
            $t->string('swarm_id', 64)->nullable();
            $t->text('worker_token')->nullable(); // encrypted
            $t->text('manager_token')->nullable(); // encrypted
            $t->string('status', 20)->default('creating'); // creating|active|failed
            $t->string('error', 500)->nullable();
            $t->json('snapshot')->nullable(); // last inspect: nodes and services
            $t->timestamp('refreshed_at')->nullable();
            $t->timestamps();
        });
        Schema::table('servers', function (Blueprint $t) {
            $t->uuid('swarm_cluster_id')->nullable()->index();
            $t->string('swarm_role', 10)->nullable(); // manager|worker
            $t->string('swarm_node_id', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', fn (Blueprint $t) => $t->dropColumn(['swarm_cluster_id', 'swarm_role', 'swarm_node_id']));
        Schema::dropIfExists('swarm_clusters');
    }
};
