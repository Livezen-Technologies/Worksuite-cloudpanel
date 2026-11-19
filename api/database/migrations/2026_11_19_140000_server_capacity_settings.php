<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capacity-aware scheduling: what each node may give to workloads, the usage
 * above which it takes no new ones, and whether automatic placement may use it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $t) {
            $t->boolean('auto_deploy')->default(true);          // eligible for automatic placement
            $t->boolean('deploy_lock')->default(false);         // no new deployments at all, even pinned ones
            $t->float('reserved_cpu')->default(0);              // cores kept for the system
            $t->unsignedInteger('reserved_ram_mb')->default(0);
            $t->unsignedInteger('reserved_disk_gb')->default(0);
            $t->float('cpu_overcommit')->default(1);            // allocatable cores = cores x overcommit - reserved
            $t->unsignedSmallInteger('max_cpu_pct')->nullable(); // placement thresholds; null = platform default
            $t->unsignedSmallInteger('max_ram_pct')->nullable();
            $t->unsignedSmallInteger('max_disk_pct')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', fn (Blueprint $t) => $t->dropColumn(['auto_deploy', 'deploy_lock', 'reserved_cpu', 'reserved_ram_mb', 'reserved_disk_gb', 'cpu_overcommit', 'max_cpu_pct', 'max_ram_pct', 'max_disk_pct']));
    }
};
