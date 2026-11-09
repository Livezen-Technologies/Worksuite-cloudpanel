<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** K8S: existing Kubernetes clusters the panel connects to. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kube_clusters', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $t->string('name', 60);
            $t->string('api_url', 255);
            $t->text('ca_cert')->nullable(); // PEM; empty means the system trust store
            $t->text('token'); // encrypted bearer token
            $t->string('default_namespace', 63)->default('default');
            $t->string('status', 20)->default('connected'); // connected|failed
            $t->string('version', 40)->nullable();
            $t->string('error', 500)->nullable();
            $t->timestamp('checked_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kube_clusters');
    }
};
