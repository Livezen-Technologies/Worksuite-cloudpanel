<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** UVPS: customer virtual servers (container VPS under Sysbox for now). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vps', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->foreignUuid('server_id')->nullable()->constrained('servers')->nullOnDelete();
            $t->uuid('container_id')->nullable();
            $t->string('name', 63);
            $t->string('plan', 20);
            $t->string('os', 30);
            $t->string('region')->nullable();
            $t->unsignedInteger('ssh_port')->nullable();
            $t->string('status', 20)->default('provisioning'); // provisioning|running|stopped|failed
            $t->string('error', 500)->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'name']);
            $t->unique(['server_id', 'ssh_port']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vps');
    }
};
