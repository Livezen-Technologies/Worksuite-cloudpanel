<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PNET-7: SSH reachable only over the private network, per server. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', fn (Blueprint $t) => $t->json('mesh_ssh_lock')->nullable()); // {allow[], port, pending_until, confirmed_at}
    }

    public function down(): void
    {
        Schema::table('servers', fn (Blueprint $t) => $t->dropColumn('mesh_ssh_lock'));
    }
};
